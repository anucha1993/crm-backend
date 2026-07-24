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
use Mpdf\QrCode\QrCode;
use Mpdf\QrCode\Output\Svg as QrSvg;

class DeliveryController extends Controller
{
    use \App\Http\Controllers\Concerns\ScopesOwnedRecords;

    public function index(Request $request): JsonResponse
    {
        $accountType = $request->attributes->get('account_type');
        $query = Delivery::with(['order:id,order_number,total,paid_amount,remaining_amount', 'customer:id,name', 'creator:id,name'])
            ->where('account_type', $accountType);

        $this->scopeToOwner($query, $request);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('delivery_number', 'like', "%{$search}%")
                  ->orWhereHas('order', fn ($oq) => $oq->where('order_number', 'like', "%{$search}%"))
                  ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status')) {
            $status = $request->status;
            // "delivering" and "pending" are computed from delivery_date for not-yet-delivered items.
            // DB stores not-delivered deliveries as 'pending'; the UI shows them as:
            //   - "delivering" (กำลังจัดส่ง) when delivery_date <= today
            //   - "pending"    (รอจัดส่ง)    when delivery_date > today or has no date
            if ($status === 'delivering') {
                $query->whereIn('status', ['pending', 'delivering'])
                    ->whereNotNull('delivery_date')
                    ->whereDate('delivery_date', '<=', now());
            } elseif ($status === 'pending') {
                $query->whereIn('status', ['pending', 'delivering'])
                    ->where(function ($q) {
                        $q->whereNull('delivery_date')
                          ->orWhereDate('delivery_date', '>', now());
                    });
            } else {
                $query->where('status', $status);
            }
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

        // Feature #1 — Final delivery bill: enforce complete payment slips, or a note.
        // Determine whether this delivery completes the order (บิลส่งของสุดท้าย).
        $requestedByItem = collect($request->items)->keyBy('order_item_id');
        $isFinalDelivery = $orderItems->every(function ($orderItem) use ($existingDeliveries, $requestedByItem) {
            $delivered = $existingDeliveries->has($orderItem->id)
                ? $existingDeliveries->get($orderItem->id)->sum('quantity')
                : 0;
            $requestedNow = (float) ($requestedByItem->get($orderItem->id)['quantity'] ?? 0);
            return ((float) $orderItem->quantity - $delivered - $requestedNow) <= 0.0001;
        });

        if ($isFinalDelivery) {
            // Slips considered "attached" = approved + pending (awaiting approval) payments.
            $coveredAmount = (float) $order->payments()
                ->whereIn('status', ['approved', 'pending'])
                ->sum('amount');
            $slipsComplete = ($coveredAmount + 0.01) >= (float) $order->total;

            if (!$slipsComplete && trim((string) $request->input('notes')) === '') {
                return response()->json([
                    'message' => 'บิลส่งสินค้าสุดท้าย: ยังแนบสลิปการชำระเงินไม่ครบ กรุณาระบุหมายเหตุก่อนดำเนินการต่อ',
                    'code' => 'final_bill_note_required',
                    'covered_amount' => round($coveredAmount, 2),
                    'total_amount' => (float) $order->total,
                    'remaining_amount' => round((float) $order->total - $coveredAmount, 2),
                ], 422);
            }
        }

        $delivery = DB::transaction(function () use ($request, $order, $orderItems) {
            $quotationNumber = $order->quotation?->quotation_number ?? $order->order_number;
            $delivery = Delivery::create([
                'account_type' => $order->account_type,
                'delivery_number' => Delivery::generateNumber($quotationNumber),
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

                // Calculate weight (น้ำหนัก = จำนวน × ความยาว(เมตร) × น้ำหนักต่อเมตร)
                $itemWeight = 0;
                if ($orderItem->product && $orderItem->product->weight) {
                    $weightPerMeter = (float) $orderItem->product->weight;
                    $length = (float) ($orderItem->length ?? $orderItem->product->length ?? 0);
                    $itemWeight = $weightPerMeter * $length * $qty;
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
            ->with([
                'order:id,order_number,status',
                'customer:id,name',
                'items.product:id,name,code',
                'items.product.sizes:id,product_id,length_unit',
                'creator:id,name',
            ])
            ->first();

        if (!$delivery) {
            return response()->json(['message' => 'ไม่พบใบส่งของ'], 404);
        }

        // Pending payments (with slip attached) awaiting approval for this order
        $pendingPayments = [];
        if ($delivery->order_id) {
            $pendingPayments = \App\Models\Payment::where('order_id', $delivery->order_id)
                ->where('status', 'pending')
                ->orderByDesc('created_at')
                ->get([
                    'id', 'payment_number', 'method', 'amount', 'is_deposit', 'status',
                    'slip_image', 'slip_verified', 'slip_status_code', 'slip_ref',
                    'sender_name', 'sender_bank', 'transfer_amount', 'transfer_date',
                    'notes', 'created_at',
                ])
                ->toArray();
        }

        $data = $delivery->toArray();
        $data['pending_payments'] = $pendingPayments;

        return response()->json(['delivery' => $data]);
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
        $orderItems = $order->items()->with('product.sizes')->get();

        $existingDeliveries = DeliveryItem::whereHas('delivery', function ($q) use ($order) {
            $q->where('order_id', $order->id)->where('status', '!=', 'cancelled');
        })->get()->groupBy('order_item_id');

        $items = $orderItems->map(function ($item) use ($existingDeliveries) {
            $delivered = $existingDeliveries->has($item->id)
                ? $existingDeliveries->get($item->id)->sum('quantity')
                : 0;
            $remaining = max(0, (float) $item->quantity - $delivered);

            $weightPerMeter = (float) ($item->product?->weight ?? 0);
            $length = (float) ($item->length ?? $item->product?->length ?? 0);
            $weightPerUnit = $weightPerMeter * $length;

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
                'weight_per_meter' => $weightPerMeter,
                'weight_per_unit' => $weightPerUnit,
                'product' => $item->product,
            ];
        });

        return response()->json([
            'items' => $items,
            'fully_delivered' => $items->every(fn ($i) => $i['remaining'] <= 0),
        ]);
    }

    /**
     * Compute the collectible total (ยอดที่ต้องเรียกเก็บ) of a single delivery bill,
     * applying the order's discount/VAT proportionally to the delivered items.
     * Requires `items` and `order` to be loaded.
     */
    private function computeDeliveryTotal(Delivery $delivery): float
    {
        $subtotal = (float) $delivery->items->sum('amount');
        $order = $delivery->order;
        if (!$order) {
            return round($subtotal, 2);
        }
        $orderSubtotal = (float) $order->subtotal;
        $ratio = $orderSubtotal > 0 ? $subtotal / $orderSubtotal : 0;
        $discount = round((float) $order->discount_amount * $ratio, 2);
        $vat = round((float) $order->vat_amount * $ratio, 2);
        return round($subtotal - $discount + $vat, 2);
    }

    /**
     * Feature #4 — Daily actual-payment summary (สรุปยอดชำระจริงรายวัน).
     * Lists the delivery bills for a given delivery date, with the amount to
     * collect vs the amount already paid on each order.
     */
    public function dailySummary(Request $request): JsonResponse
    {
        $accountType = $request->attributes->get('account_type');
        $date = $request->input('date', now()->toDateString());

        $query = Delivery::with(['items', 'order.customer:id,name,code', 'creator:id,name'])
            ->where('account_type', $accountType)
            ->where('status', '!=', 'cancelled')
            ->whereDate('delivery_date', $date);

        $this->scopeToOwner($query, $request);

        $deliveries = $query->orderBy('delivery_number')->get();

        $rows = $deliveries->map(function (Delivery $delivery) {
            $order = $delivery->order;
            $orderRemaining = (float) ($order->remaining_amount ?? 0);
            $paymentStatus = $orderRemaining <= 0.01 ? 'paid' : 'unpaid';
            $deliveryTotal = $this->computeDeliveryTotal($delivery);

            return [
                'id' => $delivery->id,
                'delivery_number' => $delivery->delivery_number,
                'status' => $delivery->status,
                'delivery_date' => $delivery->delivery_date?->toDateString(),
                'order_id' => $delivery->order_id,
                'order_number' => $order?->order_number,
                'customer_name' => $order?->customer?->name,
                'delivery_total' => $deliveryTotal,
                'order_total' => (float) ($order->total ?? 0),
                'order_paid' => (float) ($order->paid_amount ?? 0),
                'order_remaining' => $orderRemaining,
                'payment_status' => $paymentStatus,
                'creator' => $delivery->creator,
            ];
        });

        $toCollect = $rows->sum('delivery_total');
        $paidBills = $rows->where('payment_status', 'paid')->sum('delivery_total');

        return response()->json([
            'date' => $date,
            'deliveries' => $rows->values(),
            'summary' => [
                'delivery_count' => $rows->count(),
                'total_to_collect' => round($toCollect, 2),
                'total_paid' => round($paidBills, 2),
                'total_unpaid' => round($toCollect - $paidBills, 2),
            ],
        ]);
    }

    /**
     * Feature #5 — Calendar of collections (ปฏิทินตามเก็บเงิน).
     * Returns per-day aggregates for a month based on delivery date, split into
     * paid vs unpaid amounts.
     */
    public function calendar(Request $request): JsonResponse
    {
        $accountType = $request->attributes->get('account_type');
        $month = $request->input('month', now()->format('Y-m')); // YYYY-MM
        try {
            $start = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfMonth();
        } catch (\Throwable $e) {
            $start = now()->startOfMonth();
        }
        $end = $start->copy()->endOfMonth();

        $query = Delivery::with(['items', 'order:id,order_number,subtotal,discount_amount,vat_amount,total,paid_amount,remaining_amount'])
            ->where('account_type', $accountType)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('delivery_date', [$start->toDateString(), $end->toDateString()]);

        $this->scopeToOwner($query, $request);

        $deliveries = $query->get();

        $days = [];
        foreach ($deliveries as $delivery) {
            $key = $delivery->delivery_date?->toDateString();
            if (!$key) {
                continue;
            }
            if (!isset($days[$key])) {
                $days[$key] = ['delivery_count' => 0, 'to_collect' => 0.0, 'paid' => 0.0, 'unpaid' => 0.0];
            }
            $total = $this->computeDeliveryTotal($delivery);
            $isPaid = (float) ($delivery->order->remaining_amount ?? 0) <= 0.01;
            $days[$key]['delivery_count']++;
            $days[$key]['to_collect'] = round($days[$key]['to_collect'] + $total, 2);
            if ($isPaid) {
                $days[$key]['paid'] = round($days[$key]['paid'] + $total, 2);
            } else {
                $days[$key]['unpaid'] = round($days[$key]['unpaid'] + $total, 2);
            }
        }

        return response()->json([
            'month' => $start->format('Y-m'),
            'days' => $days,
        ]);
    }

    /**
     * Export delivery as printable HTML (4 copies). Browser handles print → PDF.
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
            'order.customer', 'order.shippingAddress', 'order.quotation',
            'customer', 'shippingAddress',
            'items.product.sizes', 'creator',
        ]);

        $company = CompanySetting::getAll();
        $order = $delivery->order;

        // Logo as data URI
        $logoDataUri = null;
        if (!empty($company['logo']) && Storage::disk('public')->exists($company['logo'])) {
            $logoFile = Storage::disk('public')->path($company['logo']);
            $logoMime = mime_content_type($logoFile) ?: 'image/png';
            $logoDataUri = 'data:' . $logoMime . ';base64,' . base64_encode(file_get_contents($logoFile));
        }

        $createdDate = $delivery->created_at->format('d/m/Y');
        $deliveryDate = $delivery->delivery_date->format('d/m/Y');

        // Totals
        $subtotal = $delivery->items->sum('amount');
        $orderSubtotal = (float) $order->subtotal;
        $ratio = $orderSubtotal > 0 ? $subtotal / $orderSubtotal : 0;
        $discountAmount = round((float) $order->discount_amount * $ratio, 2);
        $vatAmount = round((float) $order->vat_amount * $ratio, 2);
        $total = round($subtotal - $discountAmount + $vatAmount, 2);
        $bahtText = $this->numberToThaiText($total);
        $isVat = (($delivery->account_type ?? $order->account_type) === 'tax');
        // ส่งครบแล้ว: เฉพาะใบสุดท้ายที่ทำให้ออเดอร์ส่งครบ (ใบล่าสุดที่ไม่ถูกยกเลิก)
        $lastDeliveryId = $order->deliveries()
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('id');
        $isCompleteDelivery = ($order->delivery_status === 'fully_delivered')
            && ($delivery->status !== 'cancelled')
            && ($lastDeliveryId === $delivery->id);

        // QR
        $qrCode = new QrCode($delivery->delivery_number, 'L');
        $qrCode->disableBorder();
        $qrSvg = (new QrSvg())->output($qrCode, 100);
        $qrDataUri = 'data:image/svg+xml;base64,' . base64_encode($qrSvg);

        $html = view('deliveries.pdf', compact(
            'delivery', 'order', 'company', 'logoDataUri', 'createdDate', 'deliveryDate',
            'qrDataUri', 'subtotal', 'discountAmount', 'vatAmount', 'total', 'bahtText',
            'isVat', 'isCompleteDelivery'
        ))->render();

        $tempDir = storage_path('app/mpdf-temp');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0777, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 15,
            'margin_bottom' => 40,
            'margin_header' => 5,
            'margin_footer' => 25,
            'default_font' => 'thsarabunnew',
            'fontDir' => [public_path('fonts')],
            'fontdata' => [
                'thsarabunnew' => [
                    'R'  => 'THSarabunNew.ttf',
                    'B'  => 'THSarabunNew Bold.ttf',
                    'I'  => 'THSarabunNew Italic.ttf',
                    'BI' => 'THSarabunNew BoldItalic.ttf',
                ],
            ],
            'tempDir' => $tempDir,
        ]);

        $css = '
        <style>
            body { font-family: "thsarabunnew", sans-serif; font-size: 14pt; line-height: 1.4; color: #000; }
            h1 { font-size: 18pt; font-weight: bold; margin: 0; padding: 0; color: #000; }
            table { border-collapse: collapse; width: 100%; }
            th { padding: 8px; background-color: #f0f0f0; font-weight: bold; text-align: center; font-size: 14pt; }
            td { padding: 6px; text-align: left; font-size: 14pt; vertical-align: top; }
            hr { border: none; border-top: 1px solid #000; margin: 15px 0; width: 100%; }
            strong, b { font-weight: bold; }
            div { margin: 2px 0; line-height: 1.3; }
            p { margin: 8px 0; line-height: 1.3; }
        </style>';

        $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);

        $footerHtml = '
        <div style="font-size: 16pt; margin-top: 10px; padding-top: 5px;">
            <span><b>หมายเหตุ :</b></span>
            <span>' . e($delivery->notes ?? '') . '</span>
        </div>
        <hr style="margin: 10px 0;">
        <div style="font-size: 14pt; margin-bottom: 0; padding-top: 5px;">
            <span><b>หมายเหตุการรับสินค้า :</b></span>
            <span>กรุณาตรวจสอบความถูกต้องของสินค้าและเซ็นรับสินค้าในวันที่ได้รับ หากไม่มีการตรวจสอบหรือเซ็นรับสินค้า ทางบริษัทขอสงวนสิทธิ์ในการรับผิดชอบต่อความผิดพลาดทุกกรณี</span>
        </div>
        <table style="width: 100%; font-size: 14pt; border: none; margin-top: 20px;">
            <tr>
                <td style="width: 50%; vertical-align: top; padding-right: 20px; border: none; padding-top: 10px;">
                    <p><strong>ลงชื่อผู้รับสินค้า...................................................ผู้รับสินค้า</strong></p>
                </td>
                <td style="width: 50%; vertical-align: top; text-align: right; border: none; padding-top: 10px;">
                    <p><strong>ลงชื่อผู้ส่งสินค้า...................................................ผู้ส่งสินค้า</strong></p>
                </td>
            </tr>
        </table>';
        $mpdf->SetHTMLFooter($footerHtml);

        $mpdf->SetTitle('ใบส่งสินค้า ' . $delivery->delivery_number);
        $mpdf->SetAuthor($company['name'] ?? 'CRM');
        if ($delivery->status === 'cancelled') {
            $mpdf->SetWatermarkText('ยกเลิก', 0.12);
            $mpdf->showWatermarkText = true;
        }

        $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="delivery-' . $delivery->delivery_number . '.pdf"',
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
