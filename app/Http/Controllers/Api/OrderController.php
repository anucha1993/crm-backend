<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        ]);

        $oldStatus = $order->status;
        $order->update([
            ...$request->only(['status', 'notes']),
            'updated_by' => $request->user()->id,
        ]);

        if ($oldStatus !== $order->status) {
            PaymentLog::create([
                'order_id' => $order->id,
                'action' => 'order_status_changed',
                'summary' => 'เปลี่ยนสถานะคำสั่งซื้อ: ' . $oldStatus . ' → ' . $order->status,
                'user_id' => $request->user()->id,
            ]);
        }

        $order->load(['customer', 'shippingAddress', 'quotation', 'items.product.sizes', 'payments.creator', 'payments.approver', 'creator', 'updater']);

        return response()->json(['order' => $order]);
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
