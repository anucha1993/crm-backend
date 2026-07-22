<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ScopesOwnedRecords;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Models\Slip;
use App\Services\Slip2goService;
use App\Services\SlipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PaymentController extends Controller
{
    use ScopesOwnedRecords;

    public function index(Request $request): JsonResponse
    {
        $accountType = $request->attributes->get('account_type');
        $query = Payment::with(['order:id,order_number', 'customer:id,name,code', 'creator:id,name', 'approver:id,name'])
            ->where('account_type', $accountType);

        $this->scopeToOwner($query, $request);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('payment_number', 'like', "%{$search}%")
                  ->orWhereHas('order', fn ($oq) => $oq->where('order_number', 'like', "%{$search}%"))
                  ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('method')) {
            $query->where('method', $request->method);
        }

        $payments = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 10));

        return response()->json($payments);
    }

    public function show(Payment $payment, Request $request): JsonResponse
    {
        $this->ensureAccountMatch($payment, $request);
        $payment->load([
            'order.customer', 'order.items', 'customer',
            'creator:id,name', 'approver:id,name',
        ]);

        return response()->json(['payment' => $payment]);
    }

    /**
     * List all payments (slips) awaiting approval for an order — used by the
     * /payments/scan approval screen at the order-issuing step.
     */
    public function pendingByOrder(Order $order, Request $request): JsonResponse
    {
        $accountType = $request->attributes->get('account_type');
        if ($accountType && $order->account_type !== $accountType) {
            abort(404, 'ไม่พบคำสั่งซื้อในบัญชีปัจจุบัน');
        }

        $order->loadMissing('customer:id,name,code');

        $pending = $order->payments()
            ->where('status', 'pending')
            ->with(['creator:id,name', 'slip'])
            ->orderBy('created_at')
            ->get();

        $allPayments = $order->payments()
            ->with(['creator:id,name', 'approver:id,name', 'slip'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'total' => (float) $order->total,
                'paid_amount' => (float) $order->paid_amount,
                'remaining_amount' => (float) $order->remaining_amount,
                'customer' => $order->customer,
            ],
            'pending_payments' => $pending,
            'payments' => $allPayments,
            'pending_total' => (float) $pending->sum('amount'),
        ]);
    }

    /**
     * Approve all pending payments for an order in one action (the single
     * "อนุมัติ" button placed below the slip list).
     */
    public function approveOrderPayments(Order $order, Request $request): JsonResponse
    {
        $accountType = $request->attributes->get('account_type');
        if ($accountType && $order->account_type !== $accountType) {
            abort(404, 'ไม่พบคำสั่งซื้อในบัญชีปัจจุบัน');
        }

        $pending = $order->payments()->where('status', 'pending')->get();
        if ($pending->isEmpty()) {
            return response()->json(['message' => 'ไม่มีรายการที่รออนุมัติ'], 422);
        }

        DB::transaction(function () use ($pending, $order, $request) {
            foreach ($pending as $payment) {
                $payment->update([
                    'status' => 'approved',
                    'approved_by' => $request->user()->id,
                    'approved_at' => now(),
                ]);

                if ($payment->method === 'pocket_money') {
                    Customer::where('id', $payment->customer_id)
                        ->decrement('pocket_money', (float) $payment->amount);
                }

                PaymentLog::create([
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                    'action' => 'approved',
                    'summary' => 'อนุมัติการชำระเงิน ' . $payment->payment_number .
                        ' จำนวน ' . number_format((float) $payment->amount, 2) . ' บาท',
                    'user_id' => $request->user()->id,
                ]);
            }

            $order->recalculatePaid();
            $fresh = $order->fresh();
            if ((float) $fresh->remaining_amount <= 0 && $fresh->status !== 'completed') {
                $fresh->update(['status' => 'completed']);
                PaymentLog::create([
                    'order_id' => $order->id,
                    'action' => 'order_status_changed',
                    'summary' => 'คำสั่งซื้อสำเร็จ (ชำระครบ)',
                    'user_id' => $request->user()->id,
                ]);
            } elseif ($fresh->status === 'pending') {
                $fresh->update(['status' => 'in_progress']);
            }
        });

        return response()->json([
            'message' => 'อนุมัติสำเร็จ ' . $pending->count() . ' รายการ',
            'approved' => $pending->count(),
        ]);
    }

    private function ensureAccountMatch(Payment $payment, Request $request): void
    {
        $accountType = $request->attributes->get('account_type');
        if ($payment->account_type !== $accountType) {
            abort(404, 'ไม่พบเอกสารในบัญชีปัจจุบัน');
        }
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        $accountType = $request->attributes->get('account_type');
        if ($accountType && $order->account_type !== $accountType) {
            abort(404, 'ไม่พบคำสั่งซื้อในบัญชีปัจจุบัน');
        }

        $request->validate([
            'method' => 'required|in:cash,transfer,pocket_money',
            'amount' => 'required|numeric|min:0.01',
            'is_deposit' => 'boolean',
            'notes' => 'nullable|string',
            'slip_image' => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            'slip_images' => 'nullable|array',
            'slip_images.*' => 'file|mimes:jpg,jpeg,png|max:5120',
            // Gallery allocations: share existing slips across orders with split amounts
            'slip_allocations' => 'nullable',
            // Manual transfer fields
            'sender_name' => 'nullable|string|max:255',
            'sender_bank' => 'nullable|string|max:255',
            'sender_account' => 'nullable|string|max:100',
            'receiver_name' => 'nullable|string|max:255',
            'receiver_bank' => 'nullable|string|max:255',
            'receiver_account' => 'nullable|string|max:100',
            'transfer_amount' => 'nullable|numeric|min:0',
            'transfer_date' => 'nullable|date',
        ]);

        // Collect slip files: support both single slip_image and multi slip_images[]
        $slipFilesList = [];
        if ($request->hasFile('slip_images')) {
            $slipFilesList = $request->file('slip_images');
        } elseif ($request->hasFile('slip_image')) {
            $slipFilesList = [$request->file('slip_image')];
        }

        $method = $request->input('method');
        $accountType = $request->attributes->get('account_type') ?? $order->account_type;

        // Pocket money: check balance (before creating any payments)
        if ($method === 'pocket_money') {
            $customer = $order->customer;
            if ((float) $customer->pocket_money < (float) $request->amount) {
                abort(422, 'ยอด Pocket Money ไม่เพียงพอ (คงเหลือ: ' . number_format((float) $customer->pocket_money, 2) . ' บาท)');
            }
        }

        $slipService = app(SlipService::class);
        $manual = [
            'sender_name' => $request->sender_name,
            'sender_bank' => $request->sender_bank,
            'sender_account' => $request->sender_account,
            'receiver_name' => $request->receiver_name,
            'receiver_bank' => $request->receiver_bank,
            'receiver_account' => $request->receiver_account,
            'transfer_date' => $request->transfer_date,
            'amount' => $request->transfer_amount ?? $request->amount,
        ];

        // Build the list of allocations: each entry is a slip (or null) + amount for one payment.
        $allocations = [];

        if ($method === 'transfer') {
            // Mode A — Gallery allocations: reuse existing slips, split amounts across orders.
            $rawAllocations = $request->input('slip_allocations');
            if (is_string($rawAllocations)) {
                $rawAllocations = json_decode($rawAllocations, true) ?: [];
            }

            if (is_array($rawAllocations) && count($rawAllocations) > 0) {
                foreach ($rawAllocations as $alloc) {
                    $slip = Slip::where('id', $alloc['slip_id'] ?? 0)
                        ->where('account_type', $accountType)
                        ->first();
                    if (!$slip) {
                        abort(422, 'ไม่พบสลิปที่เลือก');
                    }
                    $amt = isset($alloc['amount']) ? (float) $alloc['amount'] : $slip->remaining_amount;
                    if ($amt <= 0) {
                        abort(422, 'จำนวนเงินที่แบ่งจ่ายไม่ถูกต้อง');
                    }
                    if ($amt - $slip->remaining_amount > 0.009) {
                        abort(422, 'สลิป ' . ($slip->slip_ref ?? '') . ' มียอดคงเหลือไม่พอ (คงเหลือ ' .
                            number_format($slip->remaining_amount, 2) . ' บาท)');
                    }
                    $allocations[] = ['slip' => $slip, 'amount' => $amt];
                }
            } else {
                // Mode B — Uploaded files: create/reuse slips (de-dup by transRef), then attach.
                foreach ($slipFilesList as $file) {
                    $resolved = $slipService->resolveFromUpload(
                        $file,
                        $accountType,
                        $request->user()->id,
                        $manual,
                        (float) $request->amount
                    );
                    /** @var Slip $slip */
                    $slip = $resolved['slip'];

                    // Amount for this payment: single slip -> requested amount;
                    // multiple slips -> each slip's own value.
                    $amt = count($slipFilesList) > 1
                        ? ($slip->amount > 0 ? (float) $slip->amount : (float) $request->amount)
                        : (float) $request->amount;

                    // Clamp to the slip's remaining balance to avoid over-allocation.
                    $remaining = $slip->remaining_amount;
                    if ($remaining > 0 && $amt - $remaining > 0.009) {
                        $amt = $remaining;
                    }

                    $allocations[] = ['slip' => $slip, 'amount' => $amt];
                }
            }
        }

        // No slips (cash / pocket money / manual transfer without slip): one plain payment.
        if (count($allocations) === 0) {
            $allocations[] = ['slip' => null, 'amount' => (float) $request->amount];
        }

        // Prevent attaching the same slip to this order more than once.
        foreach ($allocations as $alloc) {
            if ($alloc['slip']) {
                $dup = Payment::where('order_id', $order->id)
                    ->where('slip_id', $alloc['slip']->id)
                    ->where('status', '!=', 'rejected')
                    ->exists();
                if ($dup) {
                    abort(422, 'สลิปนี้ถูกแนบกับคำสั่งซื้อนี้แล้ว (' . ($alloc['slip']->slip_ref ?? '') . ')');
                }
            }
        }

        $payments = DB::transaction(function () use ($request, $order, $allocations, $method, $accountType, $manual) {
            $createdPayments = [];

            $methodLabel = match ($method) {
                'cash' => 'เงินสด',
                'transfer' => 'โอนเงิน',
                'pocket_money' => 'Pocket Money',
            };

            $total = count($allocations);
            foreach ($allocations as $i => $alloc) {
                /** @var Slip|null $slip */
                $slip = $alloc['slip'];
                $paymentAmount = $alloc['amount'];

                $payment = Payment::create([
                    'account_type' => $accountType,
                    'payment_number' => Payment::generateNumber(),
                    'order_id' => $order->id,
                    'customer_id' => $order->customer_id,
                    'method' => $method,
                    'amount' => $paymentAmount,
                    'is_deposit' => $request->boolean('is_deposit'),
                    'status' => 'pending',
                    'notes' => $request->notes,
                    'slip_id' => $slip?->id,
                    'slip_image' => $slip?->slip_image,
                    'slip_verified' => $slip?->slip_verified ?? false,
                    'slip_ref' => $slip?->slip_ref,
                    'slip_data' => $slip?->slip_data,
                    'slip_status_code' => $slip?->slip_status_code,
                    'sender_name' => $slip?->sender_name ?? $manual['sender_name'],
                    'sender_bank' => $slip?->sender_bank ?? $manual['sender_bank'],
                    'sender_account' => $slip?->sender_account ?? $manual['sender_account'],
                    'receiver_name' => $slip?->receiver_name ?? $manual['receiver_name'],
                    'receiver_bank' => $slip?->receiver_bank ?? $manual['receiver_bank'],
                    'receiver_account' => $slip?->receiver_account ?? $manual['receiver_account'],
                    'transfer_amount' => $slip?->amount ?? $request->transfer_amount,
                    'transfer_date' => $slip?->transfer_date ?? $manual['transfer_date'],
                    'created_by' => $request->user()->id,
                ]);

                PaymentLog::create([
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                    'action' => 'created',
                    'summary' => 'สร้างรายการชำระเงิน ' . $payment->payment_number .
                        ' (' . $methodLabel . ') ' . number_format((float) $payment->amount, 2) . ' บาท' .
                        ($payment->is_deposit ? ' (มัดจำ)' : '') .
                        ($total > 1 ? ' [สลิป ' . ($i + 1) . '/' . $total . ']' : '') .
                        ($slip ? ' [สลิป ' . ($slip->slip_ref ?? $slip->id) . ']' : ''),
                    'details' => [
                        'method' => $method,
                        'amount' => $paymentAmount,
                        'slip_id' => $slip?->id,
                        'slip_verified' => $slip?->slip_verified ?? false,
                        'slip_status_code' => $slip?->slip_status_code,
                    ],
                    'user_id' => $request->user()->id,
                ]);

                $createdPayments[] = $payment;
            }

            return $createdPayments;
        });

        // Load relations
        foreach ($payments as $payment) {
            $payment->load(['creator', 'order', 'slip']);
        }

        return response()->json(['payments' => $payments], 201);
    }

    public function approve(Request $request, Payment $payment): JsonResponse
    {
        $this->ensureAccountMatch($payment, $request);
        if ($payment->status !== 'pending') {
            return response()->json(['message' => 'สถานะการชำระเงินไม่ถูกต้อง'], 422);
        }

        DB::transaction(function () use ($request, $payment) {
            $payment->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            // Deduct pocket money if method is pocket_money
            if ($payment->method === 'pocket_money') {
                Customer::where('id', $payment->customer_id)
                    ->decrement('pocket_money', (float) $payment->amount);
            }

            // Recalculate order paid amounts
            $payment->order->recalculatePaid();

            // Auto-update order status based on payment
            $order = $payment->order->fresh();
            if ((float) $order->remaining_amount <= 0 && $order->status !== 'completed') {
                $order->update(['status' => 'completed']);
                PaymentLog::create([
                    'order_id' => $order->id,
                    'action' => 'order_status_changed',
                    'summary' => 'คำสั่งซื้อสำเร็จ (ชำระครบ)',
                    'user_id' => $request->user()->id,
                ]);
            } elseif ($order->status === 'pending') {
                $order->update(['status' => 'in_progress']);
                PaymentLog::create([
                    'order_id' => $order->id,
                    'action' => 'order_status_changed',
                    'summary' => 'อยู่ระหว่างดำเนินการ (ชำระเงินมัดจำ)',
                    'user_id' => $request->user()->id,
                ]);
            }

            PaymentLog::create([
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'action' => 'approved',
                'summary' => 'อนุมัติการชำระเงิน ' . $payment->payment_number .
                    ' จำนวน ' . number_format((float) $payment->amount, 2) . ' บาท',
                'user_id' => $request->user()->id,
            ]);
        });

        return response()->json(['payment' => $payment->fresh(['creator', 'approver'])]);
    }

    public function reject(Request $request, Payment $payment): JsonResponse
    {
        $this->ensureAccountMatch($payment, $request);
        if ($payment->status !== 'pending') {
            return response()->json(['message' => 'สถานะการชำระเงินไม่ถูกต้อง'], 422);
        }

        $request->validate(['reason' => 'required|string|max:500']);

        $payment->update([
            'status' => 'rejected',
            'reject_reason' => $request->reason,
        ]);

        PaymentLog::create([
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
            'action' => 'rejected',
            'summary' => 'ปฏิเสธการชำระเงิน ' . $payment->payment_number . ': ' . $request->reason,
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['payment' => $payment->fresh(['creator', 'approver'])]);
    }

    public function resubmit(Request $request, Payment $payment): JsonResponse
    {
        $this->ensureAccountMatch($payment, $request);
        if ($payment->status !== 'rejected') {
            return response()->json(['message' => 'สามารถส่งใหม่ได้เฉพาะรายการที่ถูกปฏิเสธ'], 422);
        }

        $request->validate([
            'amount' => 'sometimes|numeric|min:0.01',
            'slip_image' => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            'notes' => 'nullable|string',
        ]);

        DB::transaction(function () use ($request, $payment) {
            $updateData = [
                'status' => 'pending',
                'reject_reason' => null,
            ];

            if ($request->filled('amount')) {
                $updateData['amount'] = $request->amount;
            }
            if ($request->filled('notes')) {
                $updateData['notes'] = $request->notes;
            }

            // Handle new slip upload
            if ($request->hasFile('slip_image')) {
                // Delete old slip
                if ($payment->slip_image) {
                    Storage::disk('public')->delete($payment->slip_image);
                }
                $slipPath = $request->file('slip_image')->store('slips', 'public');
                $updateData['slip_image'] = $slipPath;

                // Re-verify with slip2go
                $slip2go = new Slip2goService();
                if ($slip2go->isConfigured()) {
                    $result = $slip2go->verifyByImage($request->file('slip_image'), [
                        'amount' => $request->amount ?? $payment->amount,
                    ]);
                    $updateData['slip_data'] = $result;
                    $updateData['slip_status_code'] = $result['code'] ?? null;
                    $updateData['slip_verified'] = in_array($result['code'] ?? '', ['200000', '200200']);
                    if (isset($result['data']['transRef'])) {
                        $updateData['slip_ref'] = $result['data']['transRef'];
                    }
                    // Update transfer details from new slip
                    if (isset($result['data']['sender'])) {
                        $updateData['sender_name'] = $result['data']['sender']['account']['name'] ?? $payment->sender_name;
                        $updateData['sender_bank'] = $result['data']['sender']['bank']['name'] ?? $payment->sender_bank;
                        $updateData['sender_account'] = $result['data']['sender']['account']['bank']['account'] ?? $payment->sender_account;
                    }
                    if (isset($result['data']['receiver'])) {
                        $updateData['receiver_name'] = $result['data']['receiver']['account']['name'] ?? $payment->receiver_name;
                        $updateData['receiver_bank'] = $result['data']['receiver']['bank']['name'] ?? $payment->receiver_bank;
                        $updateData['receiver_account'] = $result['data']['receiver']['account']['bank']['account'] ?? $payment->receiver_account;
                    }
                    $updateData['transfer_amount'] = $result['data']['amount'] ?? $payment->transfer_amount;
                    $updateData['transfer_date'] = $result['data']['dateTime'] ?? $payment->transfer_date;
                }
            }

            $payment->update($updateData);

            PaymentLog::create([
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'action' => 'resubmitted',
                'summary' => 'ส่งขออนุมัติใหม่ ' . $payment->payment_number,
                'user_id' => $request->user()->id,
            ]);
        });

        return response()->json(['payment' => $payment->fresh(['creator', 'approver'])]);
    }

    /**
     * Verify slip image via Slip2Go API (standalone endpoint)
     */
    public function verifySlip(Request $request): JsonResponse
    {
        $request->validate([
            'slip_image' => 'required|file|mimes:jpg,jpeg,png|max:5120',
            'amount' => 'nullable|numeric|min:0',
            'exclude_order_id' => 'nullable|integer',
        ]);

        $slip2go = new Slip2goService();
        if (!$slip2go->isConfigured()) {
            return response()->json(['message' => 'Slip2Go API ยังไม่ได้ตั้งค่า'], 422);
        }

        $result = $slip2go->verifyByImage($request->file('slip_image'), [
            'amount' => $request->amount,
        ]);

        // Check whether this slip (by transRef) was already recorded on a real order
        $transRef = $result['data']['transRef'] ?? null;
        if ($transRef) {
            $result['existing_usage'] = $this->findSlipUsage($transRef, $request->input('exclude_order_id'));

            // Surface an existing gallery slip so the UI can offer to share/split it.
            $accountType = $request->attributes->get('account_type');
            $slip = Slip::where('account_type', $accountType)
                ->where('slip_ref', $transRef)
                ->first();
            if ($slip) {
                $result['existing_slip'] = [
                    'id' => $slip->id,
                    'amount' => (float) $slip->amount,
                    'used_amount' => $slip->used_amount,
                    'remaining_amount' => $slip->remaining_amount,
                    'slip_ref' => $slip->slip_ref,
                ];
            }
        }

        return response()->json($result);
    }

    /**
     * Find non-rejected payments that already used this slip reference (transRef).
     * Returns a list of orders the slip has been recorded against.
     */
    private function findSlipUsage(string $slipRef, $excludeOrderId = null): array
    {
        $query = Payment::withoutGlobalScope('account')
            ->where('slip_ref', $slipRef)
            ->where('status', '!=', 'rejected')
            ->with('order:id,order_number');

        if ($excludeOrderId) {
            $query->where('order_id', '!=', $excludeOrderId);
        }

        return $query->get()
            ->map(fn ($p) => [
                'payment_id' => $p->id,
                'payment_number' => $p->payment_number,
                'order_id' => $p->order_id,
                'order_number' => $p->order?->order_number,
                'status' => $p->status,
                'created_at' => $p->created_at,
            ])
            ->values()
            ->all();
    }

    /**
     * Slip2Go settings management
     */
    public function slip2goSettings(Request $request): JsonResponse
    {
        if ($request->isMethod('get')) {
            return response()->json([
                'slip2go_api_url' => \App\Models\CompanySetting::getValue('slip2go_api_url', 'https://connect.slip2go.com'),
                'slip2go_secret_key' => \App\Models\CompanySetting::getValue('slip2go_secret_key', ''),
                'slip2go_check_duplicate' => \App\Models\CompanySetting::getValue('slip2go_check_duplicate', 'true'),
            ]);
        }

        $request->validate([
            'slip2go_api_url' => 'required|url',
            'slip2go_secret_key' => 'required|string',
            'slip2go_check_duplicate' => 'required|in:true,false',
        ]);

        \App\Models\CompanySetting::setValue('slip2go_api_url', $request->slip2go_api_url);
        \App\Models\CompanySetting::setValue('slip2go_secret_key', $request->slip2go_secret_key);
        \App\Models\CompanySetting::setValue('slip2go_check_duplicate', $request->slip2go_check_duplicate);

        return response()->json(['message' => 'บันทึกสำเร็จ']);
    }

    /**
     * Test Slip2Go connection
     */
    public function slip2goTest(): JsonResponse
    {
        $slip2go = new Slip2goService();
        $result = $slip2go->getAccountInfo();

        return response()->json($result);
    }
}
