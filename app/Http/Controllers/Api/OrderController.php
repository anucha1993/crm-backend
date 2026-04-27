<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryItem;
use App\Models\Order;
use App\Models\PaymentLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Order::with(['customer', 'quotation:id,quotation_number', 'creator']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('delivery_status')) {
            $query->where('delivery_status', $request->delivery_status);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 10));

        return response()->json($orders);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load([
            'customer', 'shippingAddress', 'quotation',
            'items.product.sizes', 'payments.creator', 'payments.approver',
            'deliveries.items', 'deliveries.creator:id,name',
            'invoices.creator:id,name',
            'creator', 'updater',
        ]);

        return response()->json(['order' => $order]);
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        $request->validate([
            'status' => 'sometimes|in:pending,in_progress,completed,cancelled',
            'notes' => 'nullable|string',
            'customer_address_id' => 'nullable|exists:customer_addresses,id',
            'discount_type' => 'nullable|in:percent,amount',
            'discount_value' => 'nullable|numeric|min:0',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'items' => 'sometimes|array|min:1',
            'items.*.id' => 'nullable|integer',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.thickness' => 'nullable|numeric|min:0',
            'items.*.length' => 'nullable|numeric|min:0',
            'items.*.description' => 'nullable|string|max:500',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit' => 'nullable|string|max:50',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        $warnings = [];
        $errors = [];

        // Validate item changes against deliveries
        if ($request->has('items')) {
            // Get confirmed delivered quantities per order_item_id
            $confirmedQty = DeliveryItem::whereHas('delivery', function ($q) use ($order) {
                $q->where('order_id', $order->id)->where('status', 'delivered');
            })->selectRaw('order_item_id, SUM(quantity) as total_delivered')
              ->groupBy('order_item_id')
              ->pluck('total_delivered', 'order_item_id');

            // Get pending delivery quantities
            $pendingQty = DeliveryItem::whereHas('delivery', function ($q) use ($order) {
                $q->where('order_id', $order->id)->whereIn('status', ['pending', 'delivering']);
            })->selectRaw('order_item_id, SUM(quantity) as total_pending')
              ->groupBy('order_item_id')
              ->pluck('total_pending', 'order_item_id');

            $requestItemIds = collect($request->items)->pluck('id')->filter()->toArray();

            // Check items being removed
            $existingItems = $order->items()->get();
            foreach ($existingItems as $existing) {
                if (!in_array($existing->id, $requestItemIds)) {
                    $delivered = (float) ($confirmedQty[$existing->id] ?? 0);
                    if ($delivered > 0) {
                        $errors[] = "ไม่สามารถลบรายการ \"{$existing->description}\" ได้ เนื่องจากมีการส่งของยืนยันแล้ว {$delivered} {$existing->unit}";
                    }
                }
            }

            // Check quantity reductions
            foreach ($request->items as $item) {
                if (!empty($item['id'])) {
                    $delivered = (float) ($confirmedQty[$item['id']] ?? 0);
                    $pending = (float) ($pendingQty[$item['id']] ?? 0);

                    if ($delivered > 0 && (float) $item['quantity'] < $delivered) {
                        $errors[] = "รายการ \"{$item['description']}\" ไม่สามารถลดจำนวนเหลือ {$item['quantity']} ได้ เนื่องจากส่งยืนยันแล้ว {$delivered} {$item['unit']}";
                    }

                    if ($pending > 0) {
                        $warnings[] = [
                            'type' => 'info',
                            'message' => "รายการ \"{$item['description']}\" มีใบส่งของรอจัดส่ง {$pending} {$item['unit']} กรุณาตรวจสอบ",
                        ];
                    }
                }
            }

            if (count($errors) > 0) {
                return response()->json(['message' => 'ไม่สามารถบันทึกได้', 'errors' => $errors], 422);
            }
        }

        $oldStatus = $order->status;
        $oldTotal = (float) $order->total;

        DB::transaction(function () use ($request, $order) {
            if ($request->has('items')) {
                $keepIds = collect($request->items)->pluck('id')->filter()->toArray();
                $order->items()->whereNotIn('id', $keepIds)->delete();

                foreach ($request->items as $i => $item) {
                    $thickness = isset($item['thickness']) ? (float) $item['thickness'] : null;
                    $length = isset($item['length']) ? (float) $item['length'] : null;
                    $qty = (float) $item['quantity'];
                    $price = (float) $item['unit_price'];

                    if ($thickness && $length && $length > 0) {
                        $amount = $thickness * $length * $qty * $price;
                    } else {
                        $amount = $qty * $price;
                    }

                    $data = [
                        'product_id' => $item['product_id'] ?? null,
                        'thickness' => $thickness,
                        'length' => $length,
                        'description' => $item['description'] ?? '',
                        'quantity' => $qty,
                        'unit' => $item['unit'] ?? 'ชิ้น',
                        'unit_price' => $price,
                        'amount' => $amount,
                        'sort_order' => $i,
                    ];

                    if (!empty($item['id'])) {
                        $order->items()->where('id', $item['id'])->update($data);
                    } else {
                        $order->items()->create($data);
                    }
                }

                // Recalculate totals
                $subtotal = $order->items()->sum('amount');
                $discountType = $request->input('discount_type', $order->discount_type);
                $discountValue = (float) $request->input('discount_value', $order->discount_value);
                $discountAmount = $discountType === 'percent' ? $subtotal * $discountValue / 100 : $discountValue;
                $afterDiscount = $subtotal - $discountAmount;
                $vatRate = (float) $request->input('vat_rate', $order->vat_rate);
                $vatAmount = $vatRate > 0 ? $afterDiscount * $vatRate / 100 : 0;
                $total = $afterDiscount + $vatAmount;
                $paid = (float) $order->paid_amount;

                $order->update([
                    ...$request->only(['customer_address_id', 'notes', 'status', 'discount_type', 'discount_value', 'vat_rate']),
                    'subtotal' => $subtotal,
                    'discount_amount' => $discountAmount,
                    'vat_amount' => $vatAmount,
                    'total' => $total,
                    'remaining_amount' => max(0, $total - $paid),
                    'updated_by' => $request->user()->id,
                ]);
            } else {
                $order->update([
                    ...$request->only(['status', 'notes', 'customer_address_id']),
                    'updated_by' => $request->user()->id,
                ]);
            }
        });

        if ($oldStatus !== $order->status) {
            PaymentLog::create([
                'order_id' => $order->id,
                'action' => 'order_status_changed',
                'summary' => 'เปลี่ยนสถานะคำสั่งซื้อ: ' . $oldStatus . ' → ' . $order->status,
                'user_id' => $request->user()->id,
            ]);
        }

        $order->refresh();

        if ($request->has('items') && $oldTotal !== (float) $order->total) {
            PaymentLog::create([
                'order_id' => $order->id,
                'action' => 'order_updated',
                'summary' => 'แก้ไขคำสั่งซื้อ ยอดรวม: ' . number_format($oldTotal, 2) . ' → ' . number_format((float) $order->total, 2),
                'user_id' => $request->user()->id,
            ]);
        }

        // Check issued invoices
        $issuedInvoices = $order->invoices()->where('status', 'issued')->pluck('invoice_number');
        if ($issuedInvoices->count() > 0 && $request->has('items')) {
            $warnings[] = [
                'type' => 'warning',
                'message' => "มีใบกำกับภาษี " . $issuedInvoices->join(', ') . " ที่ออกแล้ว ยอดอาจไม่ตรงกับคำสั่งซื้อใหม่",
            ];
        }

        $order->load(['customer', 'shippingAddress', 'quotation', 'items.product.sizes', 'payments.creator', 'payments.approver', 'deliveries.items', 'deliveries.creator:id,name', 'invoices.creator:id,name', 'creator', 'updater']);

        return response()->json(['order' => $order, 'warnings' => $warnings]);
    }

    public function timeline(Order $order): JsonResponse
    {
        $logs = $order->paymentLogs()->with('user:id,name')->get();

        return response()->json(['logs' => $logs]);
    }

    /**
     * Public endpoint: Get order status by order_number (for QR code scan)
     */
    public function publicStatus(string $orderNumber): JsonResponse
    {
        $order = Order::where('order_number', $orderNumber)
            ->with([
                'customer:id,name',
                'items:id,order_id,description,quantity,unit,amount',
                'payments' => function ($q) {
                    $q->where('status', 'approved')->select('id', 'order_id', 'payment_number', 'method', 'amount', 'status', 'created_at');
                },
            ])
            ->first();

        if (!$order) {
            return response()->json(['message' => 'ไม่พบคำสั่งซื้อ'], 404);
        }

        return response()->json(['order' => [
            'order_number' => $order->order_number,
            'customer_name' => $order->customer->name ?? '-',
            'status' => $order->status,
            'delivery_status' => $order->delivery_status,
            'total' => $order->total,
            'paid_amount' => $order->paid_amount,
            'remaining_amount' => $order->remaining_amount,
            'items' => $order->items,
            'payments' => $order->payments,
            'created_at' => $order->created_at,
        ]]);
    }
}
