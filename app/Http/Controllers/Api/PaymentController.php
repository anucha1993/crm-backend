<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Services\Slip2goService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Payment::with(['order:id,order_number', 'customer:id,name,code', 'creator:id,name', 'approver:id,name']);

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

    public function show(Payment $payment): JsonResponse
    {
        $payment->load([
            'order.customer', 'order.items', 'customer',
            'creator:id,name', 'approver:id,name',
        ]);

        return response()->json(['payment' => $payment]);
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        $request->validate([
            'method' => 'required|in:cash,transfer,pocket_money',
            'amount' => 'required|numeric|min:0.01',
            'is_deposit' => 'boolean',
            'notes' => 'nullable|string',
            'slip_image' => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
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

        $payment = DB::transaction(function () use ($request, $order) {
            $slipPath = null;
            $slipData = null;
            $slipRef = null;
            $slipVerified = false;
            $slipStatusCode = null;
            $senderName = $request->sender_name;
            $senderBank = $request->sender_bank;
            $senderAccount = $request->sender_account;
            $receiverName = $request->receiver_name;
            $receiverBank = $request->receiver_bank;
            $receiverAccount = $request->receiver_account;
            $transferAmount = $request->transfer_amount;
            $transferDate = $request->transfer_date;

            // Handle slip image upload and verification
            $method = $request->input('method');
            if ($request->hasFile('slip_image') && $method === 'transfer') {
                $slipPath = $request->file('slip_image')->store('slips', 'public');

                // Try slip2go verification
                $slip2go = new Slip2goService();
                if ($slip2go->isConfigured()) {
                    try {
                        $result = $slip2go->verifyByImage($request->file('slip_image'), [
                            'amount' => $request->amount,
                        ]);
                        $slipData = $result;
                        $slipStatusCode = $result['code'] ?? null;

                        if (isset($result['data'])) {
                            $slipRef = $result['data']['transRef'] ?? null;
                            $slipVerified = in_array($slipStatusCode, ['200000', '200200']);

                            // Auto-fill transfer details from slip
                            if (isset($result['data']['sender'])) {
                                $senderName = $senderName ?: ($result['data']['sender']['account']['name'] ?? null);
                                $senderBank = $senderBank ?: ($result['data']['sender']['bank']['name'] ?? null);
                                $senderAccount = $senderAccount ?: ($result['data']['sender']['account']['bank']['account'] ?? null);
                            }
                            if (isset($result['data']['receiver'])) {
                                $receiverName = $receiverName ?: ($result['data']['receiver']['account']['name'] ?? null);
                                $receiverBank = $receiverBank ?: ($result['data']['receiver']['bank']['name'] ?? null);
                                $receiverAccount = $receiverAccount ?: ($result['data']['receiver']['account']['bank']['account'] ?? null);
                            }
                            $transferAmount = $transferAmount ?: ($result['data']['amount'] ?? null);
                            $transferDate = $transferDate ?: ($result['data']['dateTime'] ?? null);
                        }
                    } catch (\Throwable $e) {
                        $slipData = ['code' => 'error', 'message' => $e->getMessage()];
                        $slipStatusCode = 'error';
                    }
                }
            }

            // Pocket money: check balance
            if ($method === 'pocket_money') {
                $customer = $order->customer;
                if ((float) $customer->pocket_money < (float) $request->amount) {
                    abort(422, 'ยอด Pocket Money ไม่เพียงพอ (คงเหลือ: ' . number_format((float) $customer->pocket_money, 2) . ' บาท)');
                }
            }

            $payment = Payment::create([
                'payment_number' => Payment::generateNumber(),
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'method' => $method,
                'amount' => $request->amount,
                'is_deposit' => $request->boolean('is_deposit'),
                'status' => 'pending',
                'notes' => $request->notes,
                'slip_image' => $slipPath,
                'slip_verified' => $slipVerified,
                'slip_ref' => $slipRef,
                'slip_data' => $slipData,
                'slip_status_code' => $slipStatusCode,
                'sender_name' => $senderName,
                'sender_bank' => $senderBank,
                'sender_account' => $senderAccount,
                'receiver_name' => $receiverName,
                'receiver_bank' => $receiverBank,
                'receiver_account' => $receiverAccount,
                'transfer_amount' => $transferAmount,
                'transfer_date' => $transferDate,
                'created_by' => $request->user()->id,
            ]);

            $methodLabel = match ($method) {
                'cash' => 'เงินสด',
                'transfer' => 'โอนเงิน',
                'pocket_money' => 'Pocket Money',
            };

            PaymentLog::create([
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'action' => 'created',
                'summary' => 'สร้างรายการชำระเงิน ' . $payment->payment_number .
                    ' (' . $methodLabel . ') ' . number_format((float) $payment->amount, 2) . ' บาท' .
                    ($payment->is_deposit ? ' (มัดจำ)' : ''),
                'details' => [
                    'method' => $request->method,
                    'amount' => $request->amount,
                    'slip_verified' => $slipVerified,
                    'slip_status_code' => $slipStatusCode,
                ],
                'user_id' => $request->user()->id,
            ]);

            return $payment;
        });

        $payment->load(['creator', 'order']);

        return response()->json(['payment' => $payment], 201);
    }

    public function approve(Request $request, Payment $payment): JsonResponse
    {
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
        ]);

        $slip2go = new Slip2goService();
        if (!$slip2go->isConfigured()) {
            return response()->json(['message' => 'Slip2Go API ยังไม่ได้ตั้งค่า'], 422);
        }

        $result = $slip2go->verifyByImage($request->file('slip_image'), [
            'amount' => $request->amount,
        ]);

        return response()->json($result);
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
