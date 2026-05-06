<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Order;
use App\Models\PaymentLog;
use App\Models\VehicleType;
use App\Models\CompanySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;

class DeliveryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accountType = $request->attributes->get('account_type');
        $query = Delivery::with(['order:id,order_number', 'customer:id,name', 'creator:id,name'])
            ->where('account_type', $accountType);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('delivery_number', 'like', "%{$search}%")
                  ->orWhereHas('order', fn ($oq) => $oq->where('order_number', 'like', "%{$search}%"))
                  ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->order_id);
        }

        if ($request->filled('date_from')) {
            $query->where('delivery_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('delivery_date', '<=', $request->date_to);
        }

        $deliveries = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 10));

        return response()->json($deliveries);
    }

    public function show(Delivery $delivery, Request $request): JsonResponse
    {
        $this->ensureAccountMatch($delivery, $request);
        $delivery->load([
            'order.customer', 'order.shippingAddress',
            'customer', 'shippingAddress',
            'items.product.sizes', 'items.orderItem',
            'creator:id,name', 'deliverer:id,name',
        ]);

        return response()->json(['delivery' => $delivery]);
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        $accountType = $request->attributes->get('account_type');
        if ($accountType && $order->account_type !== $accountType) {
            abort(404, 'ไม่พบคำสั่งซื้อในบัญชีปัจจุบัน');
        }

        $request->validate([
            'delivery_date' => 'required|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.order_item_id' => 'required|exists:order_items,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
        ]);

        // Check if order allows new deliveries
        if ($order->delivery_status === 'fully_delivered') {
            return response()->json(['message' => 'คำสั่งซื้อนี้จัดส่งครบแล้ว ไม่สามารถสร้างใบส่งของเพิ่มได้'], 422);
        }

        if ($order->status === 'cancelled') {
            return response()->json(['message' => 'คำสั่งซื้อถูกยกเลิก'], 422);
        }

        // Validate quantities against remaining
        $orderItems = $order->items()->get()->keyBy('id');
        $existingDeliveries = DeliveryItem::whereHas('delivery', function ($q) use ($order) {
            $q->where('order_id', $order->id)->where('status', '!=', 'cancelled');
        })->get()->groupBy('order_item_id');

        foreach ($request->items as $item) {
            $orderItem = $orderItems->get($item['order_item_id']);
            if (!$orderItem) {
                return response()->json(['message' => 'ไม่พบรายการสินค้า ID: ' . $item['order_item_id']], 422);
            }

            $delivered = $existingDeliveries->has($item['order_item_id'])
                ? $existingDeliveries->get($item['order_item_id'])->sum('quantity')
                : 0;
            $remaining = (float) $orderItem->quantity - $delivered;

            if ((float) $item['quantity'] > $remaining) {
                return response()->json([
                    'message' => "สินค้า \"{$orderItem->description}\" จำนวนเกิน: ต้องการ {$item['quantity']} แต่เหลือ {$remaining}",
                ], 422);
            }
        }

        $delivery = DB::transaction(function () use ($request, $order, $orderItems) {
            $delivery = Delivery::create([
                'account_type' => $order->account_type,
                'delivery_number' => Delivery::generateNumber(),
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'customer_address_id' => $order->customer_address_id,
                'delivery_date' => $request->delivery_date,
                'notes' => $request->notes,
                'status' => 'pending',
                'created_by' => $request->user()->id,
            ]);

            $totalWeight = 0;
            $sortOrder = 0;

            foreach ($request->items as $item) {
                $orderItem = $orderItems->get($item['order_item_id']);
                $qty = (float) $item['quantity'];

                // Calculate amount proportionally
                $ratio = $qty / (float) $orderItem->quantity;
                $itemAmount = round((float) $orderItem->amount * $ratio, 2);

                // Calculate weight
                $itemWeight = 0;
                if ($orderItem->product && $orderItem->product->weight) {
                    $itemWeight = (float) $orderItem->product->weight * $qty;
                }

                DeliveryItem::create([
                    'delivery_id' => $delivery->id,
                    'order_item_id' => $orderItem->id,
                    'product_id' => $orderItem->product_id,
                    'description' => $orderItem->description,
                    'quantity' => $qty,
                    'unit' => $orderItem->unit,
                    'unit_price' => $orderItem->unit_price,
                    'thickness' => $orderItem->thickness,
                    'length' => $orderItem->length,
                    'amount' => $itemAmount,
                    'weight' => $itemWeight,
                    'sort_order' => $sortOrder++,
                ]);

                $totalWeight += $itemWeight;
            }

            // Suggest vehicle based on weight
            $suggestedVehicle = null;
            if ($totalWeight > 0) {
                $weightInTons = $totalWeight / 1000; // Convert kg to tons
                // Find smallest vehicle that can carry the load
                $vehicle = VehicleType::where('is_active', true)
                    ->where('max_weight', '>=', $weightInTons)
                    ->orderBy('max_weight')
                    ->first();
                // Fallback: if weight exceeds all vehicles, suggest the largest
                if (!$vehicle) {
                    $vehicle = VehicleType::where('is_active', true)
                        ->orderByDesc('max_weight')
                        ->first();
                }
                $suggestedVehicle = $vehicle?->name;
            }

            $delivery->update([
                'total_weight' => $totalWeight,
                'suggested_vehicle' => $suggestedVehicle,
            ]);

            // Update order delivery status
            $this->updateOrderDeliveryStatus($order);

            // Log
            PaymentLog::create([
                'order_id' => $order->id,
                'action' => 'delivery_created',
                'summary' => 'สร้างใบส่งของ ' . $delivery->delivery_number,
                'details' => ['delivery_id' => $delivery->id, 'delivery_number' => $delivery->delivery_number],
                'user_id' => $request->user()->id,
            ]);

            return $delivery;
        });

        $delivery->load(['items.product.sizes', 'order', 'customer', 'creator:id,name']);

        return response()->json(['delivery' => $delivery], 201);
    }

    public function confirmDelivery(Request $request, Delivery $delivery): JsonResponse
    {
        $this->ensureAccountMatch($delivery, $request);
        if ($delivery->status === 'delivered') {
            return response()->json(['message' => 'ใบส่งของนี้ยืนยันจัดส่งไปแล้ว'], 422);
        }

        if ($delivery->status === 'cancelled') {
            return response()->json(['message' => 'ใบส่งของนี้ถูกยกเลิก'], 422);
        }

        $delivery->update([
            'status' => 'delivered',
            'delivered_at' => now(),
            'delivered_by' => $request->user()->id,
        ]);

        // Update order delivery status
        $this->updateOrderDeliveryStatus($delivery->order);

        PaymentLog::create([
            'order_id' => $delivery->order_id,
            'action' => 'delivery_confirmed',
            'summary' => 'ยืนยันจัดส่ง ' . $delivery->delivery_number,
            'details' => ['delivery_id' => $delivery->id],
            'user_id' => $request->user()->id,
        ]);

        $delivery->load(['items.product.sizes', 'order', 'customer', 'creator:id,name', 'deliverer:id,name']);

        return response()->json(['delivery' => $delivery]);
    }

    public function cancel(Request $request, Delivery $delivery): JsonResponse
    {
        $this->ensureAccountMatch($delivery, $request);
        if ($delivery->status === 'delivered') {
            return response()->json(['message' => 'ไม่สามารถยกเลิกใบส่งของที่จัดส่งแล้ว'], 422);
        }

        $delivery->update(['status' => 'cancelled']);

        $this->updateOrderDeliveryStatus($delivery->order);

        PaymentLog::create([
            'order_id' => $delivery->order_id,
            'action' => 'delivery_cancelled',
            'summary' => 'ยกเลิกใบส่งของ ' . $delivery->delivery_number,
            'details' => ['delivery_id' => $delivery->id],
            'user_id' => $request->user()->id,
        ]);

        $delivery->load(['items.product.sizes', 'order', 'customer', 'creator:id,name']);

        return response()->json(['delivery' => $delivery]);
    }

    private function ensureAccountMatch(Delivery $delivery, Request $request): void
    {
        $accountType = $request->attributes->get('account_type');
        if ($accountType && $delivery->account_type !== $accountType) {
            abort(404, 'ไม่พบเอกสารในบัญชีปัจจุบัน');
        }
    }

    /**
     * Public: Lookup delivery by delivery_number (for QR scan)
     */
    public function lookupByNumber(string $deliveryNumber): JsonResponse
    {
        $delivery = Delivery::where('delivery_number', $deliveryNumber)
            ->with(['order:id,order_number,status', 'customer:id,name', 'items', 'creator:id,name'])
            ->first();

        if (!$delivery) {
            return response()->json(['message' => 'ไม่พบใบส่งของ'], 404);
        }

        return response()->json(['delivery' => $delivery]);
    }

    /**
     * Get remaining quantities for an order (to help create delivery)
     */
    public function orderRemaining(Order $order, Request $request): JsonResponse
    {
        $accountType = $request->attributes->get('account_type');
        if ($accountType && $order->account_type !== $accountType) {
            abort(404, 'ไม่พบคำสั่งซื้อในบัญชีปัจจุบัน');
        }
        $orderItems = $order->items()->with('product')->get();

        $existingDeliveries = DeliveryItem::whereHas('delivery', function ($q) use ($order) {
            $q->where('order_id', $order->id)->where('status', '!=', 'cancelled');
        })->get()->groupBy('order_item_id');

        $items = $orderItems->map(function ($item) use ($existingDeliveries) {
            $delivered = $existingDeliveries->has($item->id)
                ? $existingDeliveries->get($item->id)->sum('quantity')
                : 0;
            $remaining = max(0, (float) $item->quantity - $delivered);

            return [
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'delivered' => $delivered,
                'remaining' => $remaining,
                'unit' => $item->unit,
                'unit_price' => $item->unit_price,
                'thickness' => $item->thickness,
                'length' => $item->length,
                'amount' => $item->amount,
                'weight_per_unit' => $item->product?->weight ?? 0,
                'product' => $item->product,
            ];
        });

        return response()->json([
            'items' => $items,
            'fully_delivered' => $items->every(fn ($i) => $i['remaining'] <= 0),
        ]);
    }

    /**
     * Export delivery PDF with 4 copies
     */
    public function exportPdf(Request $request, Delivery $delivery)
    {
        $token = $request->query('token');
        if (!$token) {
            abort(401, 'Unauthorized');
        }
        $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            abort(401, 'Unauthorized');
        }

        $delivery->load([
            'order.customer', 'order.shippingAddress',
            'customer', 'shippingAddress',
            'items.product.sizes', 'creator',
        ]);

        $company = CompanySetting::getAll();
        $order = $delivery->order;

        $logoPath = null;
        if (!empty($company['logo']) && Storage::disk('public')->exists($company['logo'])) {
            $logoPath = Storage::disk('public')->path($company['logo']);
        }

        $createdDate = $delivery->created_at->format('d/m/Y');
        $deliveryDate = $delivery->delivery_date->format('d/m/Y');

        // Calculate totals for price copies
        $subtotal = $delivery->items->sum('amount');
        // Proportional discount & VAT
        $orderTotal = (float) $order->total;
        $orderSubtotal = (float) $order->subtotal;
        $ratio = $orderSubtotal > 0 ? $subtotal / $orderSubtotal : 0;
        $discountAmount = round((float) $order->discount_amount * $ratio, 2);
        $vatAmount = round((float) $order->vat_amount * $ratio, 2);
        $total = round($subtotal - $discountAmount + $vatAmount, 2);
        $bahtText = $this->numberToThaiText($total);

        $isVat = (($delivery->account_type ?? $order->account_type) === 'tax');
        $qrData = $delivery->delivery_number;

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'garuda',
            'margin_top' => 5,
            'margin_bottom' => 35,
            'margin_left' => 8,
            'margin_right' => 8,
            'autoLangToFont' => true,
            'autoScriptToLang' => true,
            'tempDir' => storage_path('app/mpdf-temp'),
        ]);

        $mpdf->SetTitle('ใบส่งสินค้า ' . $delivery->delivery_number);
        $mpdf->SetAuthor($company['name'] ?? 'CRM');

        // Copy 1-2: Items only (no prices)
        for ($copy = 1; $copy <= 2; $copy++) {
            $html = view('deliveries.pdf', [
                'delivery' => $delivery,
                'order' => $order,
                'company' => $company,
                'logoPath' => $logoPath,
                'createdDate' => $createdDate,
                'deliveryDate' => $deliveryDate,
                'qrData' => $qrData,
                'showPrices' => false,
                'copyNumber' => $copy,
                'copyLabel' => $copy === 1 ? 'ต้นฉบับ' : 'สำเนา',
            ])->render();

            if ($copy > 1) {
                $mpdf->AddPage();
            }
            if ($delivery->status === 'cancelled') {
                $mpdf->SetWatermarkText('ยกเลิก', 0.12);
                $mpdf->showWatermarkText = true;
            }
            $mpdf->WriteHTML($html);
        }

        // Copy 3-4: With prices
        for ($copy = 3; $copy <= 4; $copy++) {
            $html = view('deliveries.pdf', [
                'delivery' => $delivery,
                'order' => $order,
                'company' => $company,
                'logoPath' => $logoPath,
                'createdDate' => $createdDate,
                'deliveryDate' => $deliveryDate,
                'qrData' => $qrData,
                'showPrices' => true,
                'copyNumber' => $copy,
                'copyLabel' => $copy === 3 ? 'ต้นฉบับ (ราคา)' : 'สำเนา (ราคา)',
                'subtotal' => $subtotal,
                'discountAmount' => $discountAmount,
                'discountType' => $order->discount_type,
                'discountValue' => $order->discount_value,
                'vatRate' => $order->vat_rate,
                'vatAmount' => $vatAmount,
                'total' => $total,
                'bahtText' => $bahtText,
                'isVat' => $isVat,
            ])->render();

            $mpdf->AddPage();
            if ($delivery->status === 'cancelled') {
                $mpdf->SetWatermarkText('ยกเลิก', 0.12);
                $mpdf->showWatermarkText = true;
            }
            $mpdf->WriteHTML($html);
        }

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $delivery->delivery_number . '.pdf"',
        ]);
    }

    private function updateOrderDeliveryStatus(Order $order): void
    {
        $orderItems = $order->items()->get();
        $existingDeliveries = DeliveryItem::whereHas('delivery', function ($q) use ($order) {
            $q->where('order_id', $order->id)->where('status', '!=', 'cancelled');
        })->get()->groupBy('order_item_id');

        $allDelivered = true;
        $anyDelivered = false;

        foreach ($orderItems as $item) {
            $delivered = $existingDeliveries->has($item->id)
                ? $existingDeliveries->get($item->id)->sum('quantity')
                : 0;

            if ($delivered >= (float) $item->quantity) {
                $anyDelivered = true;
            } else if ($delivered > 0) {
                $anyDelivered = true;
                $allDelivered = false;
            } else {
                $allDelivered = false;
            }
        }

        // Check if any deliveries are cancelled
        $allCancelled = $order->deliveries()
            ->where('status', '!=', 'cancelled')
            ->count() === 0 && $order->deliveries()->count() > 0;

        if ($allCancelled) {
            $newStatus = 'not_delivered';
        } elseif ($allDelivered && $anyDelivered) {
            $newStatus = 'fully_delivered';
        } elseif ($anyDelivered) {
            $newStatus = 'partially_delivered';
        } else {
            $newStatus = 'not_delivered';
        }

        $order->update(['delivery_status' => $newStatus]);
    }

    private function numberToThaiText(float $num): string
    {
        if ($num == 0) return 'ศูนย์บาทถ้วน';

        $digits = ['', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
        $positions = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน', 'ล้าน'];

        $convert = function (int $n) use ($digits, $positions): string {
            if ($n === 0) return '';
            $str = (string) $n;
            $result = '';
            $len = strlen($str);
            for ($i = 0; $i < $len; $i++) {
                $d = (int) $str[$i];
                $pos = $len - $i - 1;
                if ($d === 0) continue;
                if ($pos === 0 && $d === 1 && $len > 1) {
                    $result .= 'เอ็ด';
                } elseif ($pos === 1 && $d === 1) {
                    $result .= 'สิบ';
                } elseif ($pos === 1 && $d === 2) {
                    $result .= 'ยี่สิบ';
                } else {
                    $result .= $digits[$d] . $positions[$pos];
                }
            }
            return $result;
        };

        $baht = (int) floor($num);
        $satang = (int) round(($num - $baht) * 100);

        $result = '';
        if ($baht > 0) {
            if ($baht >= 1000000) {
                $result .= $convert((int) floor($baht / 1000000)) . 'ล้าน';
                $baht %= 1000000;
            }
            $result .= $convert($baht) . 'บาท';
        }

        if ($satang > 0) {
            $result .= $convert($satang) . 'สตางค์';
        } else {
            $result .= 'ถ้วน';
        }

        return $result;
    }
}
