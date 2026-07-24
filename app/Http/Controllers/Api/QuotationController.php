<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Models\Quotation;
use App\Models\QuotationRevision;
use App\Models\Slip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Mpdf\QrCode\QrCode;
use Mpdf\QrCode\Output\Svg as QrSvg;

class QuotationController extends Controller
{
    use \App\Http\Controllers\Concerns\ScopesOwnedRecords;

    public function index(Request $request): JsonResponse
    {
        $accountType = $request->attributes->get('account_type');
        $query = Quotation::with(['customer', 'creator', 'shippingAddress'])->where('account_type', $accountType);

        $this->scopeToOwner($query, $request);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('quotation_number', 'like', "%{$search}%")
                  ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        $quotations = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 10));

        return response()->json($quotations);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'customer_address_id' => 'nullable|exists:customer_addresses,id',
            'notes' => 'nullable|string',
            'valid_until' => 'nullable|date',
            'discount_type' => 'nullable|in:percent,amount',
            'discount_value' => 'nullable|numeric|min:0',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.thickness' => 'nullable|numeric|min:0',
            'items.*.length' => 'nullable|numeric|min:0',
            'items.*.description' => 'nullable|string|max:500',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit' => 'nullable|string|max:50',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        $quotation = DB::transaction(function () use ($request) {
            $totals = $this->calculateTotals($request);

            $quotation = Quotation::create([
                'account_type' => $request->attributes->get('account_type'),
                'quotation_number' => Quotation::generateNumber(),
                'customer_id' => $request->customer_id,
                'customer_address_id' => $request->customer_address_id,
                'status' => 'draft',
                'notes' => $request->notes,
                'valid_until' => $request->valid_until,
                'discount_type' => $request->discount_type ?? 'amount',
                'discount_value' => $request->discount_value ?? 0,
                'vat_rate' => $request->vat_rate ?? 7,
                'subtotal' => $totals['subtotal'],
                'discount_amount' => $totals['discount_amount'],
                'vat_amount' => $totals['vat_amount'],
                'total' => $totals['total'],
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            foreach ($request->items as $i => $item) {
                $thickness = isset($item['thickness']) ? (float) $item['thickness'] : null;
                $length = isset($item['length']) ? (float) $item['length'] : null;
                $amount = $this->calculateItemAmount($thickness, $length, $item['quantity'], $item['unit_price']);
                $quotation->items()->create([
                    'product_id' => $item['product_id'] ?? null,
                    'thickness' => $thickness,
                    'length' => $length,
                    'description' => $item['description'] ?? '',
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'] ?? 'ชิ้น',
                    'unit_price' => $item['unit_price'],
                    'amount' => $amount,
                    'sort_order' => $i,
                ]);
            }

            return $quotation;
        });

        // Log creation revision
        QuotationRevision::create([
            'quotation_id' => $quotation->id,
            'revision_number' => 0,
            'action' => 'created',
            'summary' => 'สร้างใบเสนอราคา ' . $quotation->quotation_number,
            'user_id' => $request->user()->id,
        ]);

        $quotation->load(['customer.addresses', 'shippingAddress', 'items.product', 'creator', 'updater']);

        return response()->json(['quotation' => $quotation], 201);
    }

    public function show(Quotation $quotation, Request $request): JsonResponse
    {
        $this->ensureAccountMatch($quotation, $request);

        $quotation->load(['customer.addresses', 'shippingAddress', 'items.product', 'creator', 'updater']);

        $linkedOrder = Order::where('quotation_id', $quotation->id)->first(['id', 'order_number', 'status']);

        return response()->json([
            'quotation' => $quotation,
            'linked_order' => $linkedOrder,
        ]);
    }

    private function ensureAccountMatch(Quotation $quotation, Request $request): void
    {
        $accountType = $request->attributes->get('account_type');
        if ($quotation->account_type !== $accountType) {
            abort(404, 'ไม่พบเอกสารในบัญชีปัจจุบัน');
        }
    }

    public function update(Request $request, Quotation $quotation): JsonResponse
    {
        $this->ensureAccountMatch($quotation, $request);

        // Block edits if a non-cancelled order has been opened from this quotation.
        // The user must edit the order instead; the system will sync changes back here.
        $linkedOrder = Order::where('quotation_id', $quotation->id)
            ->where('status', '!=', 'cancelled')
            ->first();

        if ($linkedOrder) {
            // Allow only soft fields that don't affect financials/items (status & notes still safe to change
            // only if user isn't trying to send items/discount/vat). If they sent any of those, block.
            $blockedFields = ['items', 'discount_type', 'discount_value', 'vat_rate', 'customer_id', 'customer_address_id'];
            $hasBlocked = false;
            foreach ($blockedFields as $f) {
                if ($request->has($f)) { $hasBlocked = true; break; }
            }
            if ($hasBlocked) {
                return response()->json([
                    'message' => 'ใบเสนอราคานี้มีคำสั่งซื้อ ' . $linkedOrder->order_number . ' แล้ว ไม่สามารถแก้ไขได้ กรุณาไปแก้ไขที่คำสั่งซื้อแทน',
                    'linked_order' => [
                        'id' => $linkedOrder->id,
                        'order_number' => $linkedOrder->order_number,
                    ],
                ], 423);
            }
        }

        $request->validate([
            'customer_id' => 'sometimes|exists:customers,id',
            'customer_address_id' => 'nullable|exists:customer_addresses,id',
            'status' => 'sometimes|in:draft,sent,approved,rejected,cancelled',
            'notes' => 'nullable|string',
            'valid_until' => 'nullable|date',
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

        $oldStatus = $quotation->status;
        $oldTotal = (float) $quotation->total;

        DB::transaction(function () use ($request, $quotation) {
            if ($request->has('items')) {
                $keepIds = collect($request->items)->pluck('id')->filter()->toArray();
                $quotation->items()->whereNotIn('id', $keepIds)->delete();

                foreach ($request->items as $i => $item) {
                    $thickness = isset($item['thickness']) ? (float) $item['thickness'] : null;
                    $length = isset($item['length']) ? (float) $item['length'] : null;
                    $amount = $this->calculateItemAmount($thickness, $length, $item['quantity'], $item['unit_price']);
                    $data = [
                        'product_id' => $item['product_id'] ?? null,
                        'thickness' => $thickness,
                        'length' => $length,
                        'description' => $item['description'] ?? '',
                        'quantity' => $item['quantity'],
                        'unit' => $item['unit'] ?? 'ชิ้น',
                        'unit_price' => $item['unit_price'],
                        'amount' => $amount,
                        'sort_order' => $i,
                    ];

                    if (!empty($item['id'])) {
                        $quotation->items()->where('id', $item['id'])->update($data);
                    } else {
                        $quotation->items()->create($data);
                    }
                }

                $totals = $this->calculateTotals($request);
                $quotation->update([
                    ...$request->except(['items']),
                    'subtotal' => $totals['subtotal'],
                    'discount_amount' => $totals['discount_amount'],
                    'vat_amount' => $totals['vat_amount'],
                    'total' => $totals['total'],
                    'updated_by' => $request->user()->id,
                ]);
            } else {
                $quotation->update([
                    ...$request->all(),
                    'updated_by' => $request->user()->id,
                ]);
            }
        });

        // Log revision
        $quotation->refresh();
        $changes = [];
        $summaryParts = [];

        if ($oldStatus !== $quotation->status) {
            $changes['status'] = ['from' => $oldStatus, 'to' => $quotation->status];
            $summaryParts[] = 'เปลี่ยนสถานะ: ' . $oldStatus . ' → ' . $quotation->status;
        }

        if ($oldTotal !== (float) $quotation->total) {
            $changes['total'] = ['from' => $oldTotal, 'to' => (float) $quotation->total];
            $summaryParts[] = 'ยอดรวม: ' . number_format($oldTotal, 2) . ' → ' . number_format((float) $quotation->total, 2);
        }

        if ($request->has('items')) {
            $summaryParts[] = 'แก้ไขรายการสินค้า';
        }

        $quotation->increment('revision_number');
        $quotation->refresh();

        QuotationRevision::create([
            'quotation_id' => $quotation->id,
            'revision_number' => $quotation->revision_number,
            'action' => $oldStatus !== $quotation->status ? 'status_changed' : 'updated',
            'summary' => implode(', ', $summaryParts) ?: 'แก้ไขใบเสนอราคา Rev.' . str_pad($quotation->revision_number, 2, '0', STR_PAD_LEFT),
            'changes' => !empty($changes) ? $changes : null,
            'user_id' => $request->user()->id,
        ]);

        // Auto-create Order when quotation is approved
        if ($oldStatus !== 'approved' && $quotation->status === 'approved') {
            $order = DB::transaction(function () use ($quotation, $request) {
                $order = Order::create([
                    'account_type' => $quotation->account_type,
                    'order_number' => Order::generateNumber(),
                    'quotation_id' => $quotation->id,
                    'customer_id' => $quotation->customer_id,
                    'customer_address_id' => $quotation->customer_address_id,
                    'status' => 'pending',
                    'subtotal' => $quotation->subtotal,
                    'discount_type' => $quotation->discount_type,
                    'discount_value' => $quotation->discount_value,
                    'discount_amount' => $quotation->discount_amount,
                    'vat_rate' => $quotation->vat_rate,
                    'vat_amount' => $quotation->vat_amount,
                    'total' => $quotation->total,
                    'paid_amount' => 0,
                    'remaining_amount' => $quotation->total,
                    'notes' => $quotation->notes,
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);

                foreach ($quotation->items as $item) {
                    $order->items()->create([
                        'product_id' => $item->product_id,
                        'description' => $item->description,
                        'quantity' => $item->quantity,
                        'unit' => $item->unit,
                        'unit_price' => $item->unit_price,
                        'thickness' => $item->thickness,
                        'length' => $item->length,
                        'amount' => $item->amount,
                        'sort_order' => $item->sort_order,
                    ]);
                }

                PaymentLog::create([
                    'order_id' => $order->id,
                    'action' => 'order_created',
                    'summary' => 'สร้างคำสั่งซื้อ ' . $order->order_number . ' จากใบเสนอราคา ' . $quotation->quotation_number,
                    'user_id' => $request->user()->id,
                ]);

                return $order;
            });
        }

        $quotation->load(['customer.addresses', 'shippingAddress', 'items.product', 'creator', 'updater']);

        // Check related documents and generate warnings
        $warnings = [];
        $linkedOrder = Order::where('quotation_id', $quotation->id)->first();
        if ($linkedOrder) {
            $warnings[] = [
                'type' => 'info',
                'message' => "มีคำสั่งซื้อ {$linkedOrder->order_number} ผูกอยู่ การแก้ไขจะไม่อัพเดทคำสั่งซื้ออัตโนมัติ",
            ];

            if ((float) $linkedOrder->total !== (float) $quotation->total) {
                $warnings[] = [
                    'type' => 'warning',
                    'message' => "ยอดรวมใบเสนอราคา (" . number_format((float) $quotation->total, 2) . ") ไม่ตรงกับคำสั่งซื้อ (" . number_format((float) $linkedOrder->total, 2) . ")",
                ];
            }

            $issuedInvoices = $linkedOrder->invoices()->where('status', 'issued')->pluck('invoice_number');
            if ($issuedInvoices->count() > 0) {
                $warnings[] = [
                    'type' => 'warning',
                    'message' => "คำสั่งซื้อมีใบกำกับภาษี " . $issuedInvoices->join(', ') . " ที่ออกแล้ว ยอดอาจไม่ตรงกัน",
                ];
            }

            $confirmedCount = $linkedOrder->deliveries()->where('status', 'delivered')->count();
            if ($confirmedCount > 0) {
                $warnings[] = [
                    'type' => 'warning',
                    'message' => "มีใบส่งของที่ยืนยันการส่งแล้ว {$confirmedCount} ใบ",
                ];
            }

            $pendingCount = $linkedOrder->deliveries()->whereIn('status', ['pending', 'delivering'])->count();
            if ($pendingCount > 0) {
                $warnings[] = [
                    'type' => 'info',
                    'message' => "มีใบส่งของที่ยังไม่ยืนยัน {$pendingCount} ใบ กรุณาตรวจสอบจำนวนสินค้า",
                ];
            }
        }

        return response()->json([
            'quotation' => $quotation,
            'warnings' => $warnings,
            'linked_order' => $linkedOrder ? ['id' => $linkedOrder->id, 'order_number' => $linkedOrder->order_number] : null,
        ]);
    }

    public function destroy(Quotation $quotation, Request $request): JsonResponse
    {
        $this->ensureAccountMatch($quotation, $request);
        $quotation->delete();

        return response()->json(['message' => 'ลบใบเสนอราคาสำเร็จ']);
    }

    public function nextNumber(): JsonResponse
    {
        return response()->json(['number' => Quotation::generateNumber()]);
    }

    public function revisions(Quotation $quotation, Request $request): JsonResponse
    {
        $this->ensureAccountMatch($quotation, $request);
        $revisions = $quotation->revisions()->with('user:id,name')->get();

        return response()->json(['revisions' => $revisions]);
    }

    public function duplicate(Quotation $quotation, Request $request): JsonResponse
    {
        $this->ensureAccountMatch($quotation, $request);
        $quotation->load('items');

        $userId = $request->user()->id;

        $new = DB::transaction(function () use ($quotation, $userId) {
            $new = $quotation->replicate(['quotation_number', 'status', 'created_at', 'updated_at']);
            $new->quotation_number = Quotation::generateNumber();
            $new->status = 'draft';
            $new->created_by = $userId;
            $new->updated_by = $userId;
            $new->save();

            foreach ($quotation->items as $item) {
                $newItem = $item->replicate(['quotation_id', 'created_at', 'updated_at']);
                $newItem->quotation_id = $new->id;
                $newItem->save();
            }

            return $new;
        });

        // Log revision for new duplicated quotation
        QuotationRevision::create([
            'quotation_id' => $new->id,
            'revision_number' => 0,
            'action' => 'duplicated',
            'summary' => 'คัดลอกจากใบเสนอราคา ' . $quotation->quotation_number,
            'user_id' => $userId,
        ]);

        $new->load(['customer.addresses', 'shippingAddress', 'items.product', 'creator', 'updater']);

        return response()->json(['quotation' => $new], 201);
    }

    /**
     * Convert a quotation and ALL its downstream documents (order, deliveries,
     * invoices, payments, and — when safe — the slips they reference) between
     * account modes: cash <-> tax.
     *
     * The user must have permission for BOTH the current account (already
     * enforced by AccountScope middleware) and the target account.
     */
    public function convertAccount(Quotation $quotation, Request $request): JsonResponse
    {
        $this->ensureAccountMatch($quotation, $request);

        $request->validate([
            'target_account_type' => 'required|in:cash,tax',
        ]);

        $target = $request->input('target_account_type');
        $source = $quotation->account_type;

        if ($target === $source) {
            return response()->json([
                'message' => 'ใบเสนอราคานี้อยู่ในโหมด "' . ($source === 'cash' ? 'บิลเงินสด' : 'ใบกำกับภาษี') . '" อยู่แล้ว',
            ], 422);
        }

        // Verify user can access the TARGET account. Admins bypass.
        $user = $request->user();
        $isAdmin = $user->roles()->where('name', 'admin')->exists();
        if (!$isAdmin) {
            $requiredPermission = $target === 'cash' ? 'accounts.cash' : 'accounts.tax';
            $hasPermission = $user->roles()
                ->whereHas('permissions', fn ($q) => $q->where('name', $requiredPermission))
                ->exists();
            if (!$hasPermission) {
                return response()->json([
                    'message' => 'คุณไม่มีสิทธิ์แปลงเอกสารไปยังบัญชี "' . ($target === 'cash' ? 'บิลเงินสด' : 'ใบกำกับภาษี') . '"',
                    'code' => 'target_account_forbidden',
                ], 403);
            }
        }

        // Collect the whole document chain BEFORE flipping account_type,
        // because the global BelongsToAccount scope would hide rows after.
        $order = Order::withoutGlobalScope('account')
            ->where('quotation_id', $quotation->id)
            ->first();

        $deliveryIds = [];
        $invoiceIds = [];
        $paymentIds = [];
        $slipIdsCandidate = collect();

        if ($order) {
            $deliveryIds = Delivery::withoutGlobalScope('account')
                ->where('order_id', $order->id)->pluck('id')->all();
            $invoiceIds = Invoice::withoutGlobalScope('account')
                ->where('order_id', $order->id)->pluck('id')->all();
            $payments = Payment::withoutGlobalScope('account')
                ->where('order_id', $order->id)
                ->get(['id', 'slip_id']);
            $paymentIds = $payments->pluck('id')->all();
            $slipIdsCandidate = $payments->pluck('slip_id')->filter()->unique();
        }

        // A slip may be shared across multiple orders (Feature #2: 1 slip -> many
        // orders). Only convert slips that are ONLY referenced by payments of the
        // order we are moving — otherwise flipping them would break the other side.
        $slipIdsToConvert = [];
        $slipIdsSkipped = [];
        foreach ($slipIdsCandidate as $slipId) {
            $externalUsage = Payment::withoutGlobalScope('account')
                ->where('slip_id', $slipId)
                ->when($order, fn ($q) => $q->where('order_id', '!=', $order->id))
                ->exists();
            if ($externalUsage) {
                $slipIdsSkipped[] = $slipId;
            } else {
                $slipIdsToConvert[] = $slipId;
            }
        }

        // Determine the new VAT rate for the TARGET mode:
        //   cash -> always 0% (บิลเงินสดไม่มีภาษี)
        //   tax  -> keep existing rate if already > 0, otherwise default 7%
        $newVatRate = $target === 'cash'
            ? 0.0
            : ((float) $quotation->vat_rate > 0 ? (float) $quotation->vat_rate : 7.0);

        // Snapshot old totals (for the summary/audit trail).
        $quotationTotalBefore = (float) $quotation->total;
        $quotationVatBefore = (float) $quotation->vat_amount;
        $quotationVatRateBefore = (float) $quotation->vat_rate;
        $orderTotalBefore = $order ? (float) $order->total : 0.0;

        // Precompute quotation totals — subtotal + discount stay; only VAT/total change.
        [$newQuotationVatAmount, $newQuotationTotal] = $this->recomputeVatTotals(
            (float) $quotation->subtotal,
            (float) $quotation->discount_amount,
            $newVatRate
        );

        [$newOrderVatAmount, $newOrderTotal] = $order
            ? $this->recomputeVatTotals(
                (float) $order->subtotal,
                (float) $order->discount_amount,
                $newVatRate
            )
            : [0.0, 0.0];

        // Invoices are legal documents. If any is 'issued', its amounts must NOT be
        // silently rewritten — only account_type is flipped and a warning is surfaced.
        $issuedInvoiceIds = [];
        if (!empty($invoiceIds)) {
            $issuedInvoiceIds = Invoice::withoutGlobalScope('account')
                ->whereIn('id', $invoiceIds)
                ->where('status', 'issued')
                ->pluck('id')
                ->all();
        }
        $recomputableInvoiceIds = array_values(array_diff($invoiceIds, $issuedInvoiceIds));

        DB::transaction(function () use (
            $quotation, $order, $target, $newVatRate,
            $newQuotationVatAmount, $newQuotationTotal,
            $newOrderVatAmount, $newOrderTotal,
            $deliveryIds, $invoiceIds, $recomputableInvoiceIds,
            $paymentIds, $slipIdsToConvert
        ) {
            // Quotation — flip mode + recompute VAT/total.
            $quotation->forceFill([
                'account_type' => $target,
                'vat_rate' => $newVatRate,
                'vat_amount' => $newQuotationVatAmount,
                'total' => $newQuotationTotal,
            ])->save();

            // Order — recompute totals AND remaining_amount (paid_amount stays).
            if ($order) {
                $paid = (float) $order->paid_amount;
                $order->forceFill([
                    'account_type' => $target,
                    'vat_rate' => $newVatRate,
                    'vat_amount' => $newOrderVatAmount,
                    'total' => $newOrderTotal,
                    'remaining_amount' => max(0.0, round($newOrderTotal - $paid, 2)),
                ])->save();
            }

            // Deliveries carry no monetary totals — just flip account_type.
            if (!empty($deliveryIds)) {
                Delivery::withoutGlobalScope('account')
                    ->whereIn('id', $deliveryIds)
                    ->update(['account_type' => $target]);
            }

            // Invoices — flip account_type on all; recompute VAT/total ONLY on
            // non-issued ones (safe). Issued invoices keep their historic amounts.
            if (!empty($invoiceIds)) {
                Invoice::withoutGlobalScope('account')
                    ->whereIn('id', $invoiceIds)
                    ->update(['account_type' => $target]);
            }
            if (!empty($recomputableInvoiceIds)) {
                $invoices = Invoice::withoutGlobalScope('account')
                    ->whereIn('id', $recomputableInvoiceIds)
                    ->get();
                foreach ($invoices as $inv) {
                    [$vat, $total] = $this->recomputeVatTotals(
                        (float) $inv->subtotal,
                        (float) $inv->discount_amount,
                        $newVatRate
                    );
                    $inv->forceFill([
                        'vat_rate' => $newVatRate,
                        'vat_amount' => $vat,
                        'total' => $total,
                    ])->save();
                }
            }

            // Payments & slips = real money records. Only flip account_type,
            // never touch amounts.
            if (!empty($paymentIds)) {
                Payment::withoutGlobalScope('account')
                    ->whereIn('id', $paymentIds)
                    ->update(['account_type' => $target]);
            }
            if (!empty($slipIdsToConvert)) {
                Slip::withoutGlobalScope('account')
                    ->whereIn('id', $slipIdsToConvert)
                    ->update(['account_type' => $target]);
            }
        });

        $summary = 'แปลงโหมดบัญชี ' .
            ($source === 'cash' ? 'บิลเงินสด' : 'ใบกำกับภาษี') . ' → ' .
            ($target === 'cash' ? 'บิลเงินสด' : 'ใบกำกับภาษี');

        $quotation->increment('revision_number');
        $quotation->refresh();

        QuotationRevision::create([
            'quotation_id' => $quotation->id,
            'revision_number' => $quotation->revision_number,
            'action' => 'account_converted',
            'summary' => $summary,
            'changes' => [
                'account_type' => ['from' => $source, 'to' => $target],
                'vat_rate' => ['from' => $quotationVatRateBefore, 'to' => $newVatRate],
                'total' => ['from' => $quotationTotalBefore, 'to' => $newQuotationTotal],
                'vat_amount' => ['from' => $quotationVatBefore, 'to' => $newQuotationVatAmount],
                'converted' => [
                    'order' => $order?->id,
                    'deliveries' => count($deliveryIds),
                    'invoices' => count($invoiceIds),
                    'invoices_recomputed' => count($recomputableInvoiceIds),
                    'invoices_issued_kept' => count($issuedInvoiceIds),
                    'payments' => count($paymentIds),
                    'slips' => count($slipIdsToConvert),
                    'slips_skipped' => count($slipIdsSkipped),
                ],
            ],
            'user_id' => $request->user()->id,
        ]);

        if ($order) {
            PaymentLog::create([
                'order_id' => $order->id,
                'action' => 'account_converted',
                'summary' => $summary . ' (โดยแปลงจากใบเสนอราคา ' . $quotation->quotation_number
                    . ' — VAT: ' . number_format($quotationVatRateBefore, 2) . '% → ' . number_format($newVatRate, 2) . '%'
                    . ', ยอดคำสั่งซื้อ: ' . number_format($orderTotalBefore, 2) . ' → ' . number_format($newOrderTotal, 2) . ')',
                'user_id' => $request->user()->id,
            ]);
        }

        return response()->json([
            'message' => 'แปลงโหมดบัญชีสำเร็จ',
            'quotation' => [
                'id' => $quotation->id,
                'quotation_number' => $quotation->quotation_number,
                'account_type' => $quotation->account_type,
            ],
            'summary' => [
                'from' => $source,
                'to' => $target,
                'order_id' => $order?->id,
                'order_number' => $order?->order_number,
                'deliveries' => count($deliveryIds),
                'invoices' => count($invoiceIds),
                'invoices_recomputed' => count($recomputableInvoiceIds),
                'invoices_issued_kept' => count($issuedInvoiceIds),
                'payments' => count($paymentIds),
                'slips_converted' => count($slipIdsToConvert),
                'slips_skipped' => count($slipIdsSkipped),
                'vat_rate_from' => $quotationVatRateBefore,
                'vat_rate_to' => $newVatRate,
                'quotation_total_from' => $quotationTotalBefore,
                'quotation_total_to' => $newQuotationTotal,
                'order_total_from' => $orderTotalBefore,
                'order_total_to' => $newOrderTotal,
            ],
        ]);
    }

    /**
     * Recompute vat_amount + total from a subtotal, discount amount and vat_rate (%).
     * subtotal + discount are preserved (unit prices remain the same in BOTH modes);
     * only the VAT layer changes.
     */
    private function recomputeVatTotals(float $subtotal, float $discountAmount, float $vatRate): array
    {
        $net = max(0.0, $subtotal - $discountAmount);
        $vatAmount = round($net * $vatRate / 100, 2);
        $total = round($net + $vatAmount, 2);
        return [$vatAmount, $total];
    }

    private function calculateItemAmount(?float $thickness, ?float $length, float $quantity, float $unitPrice): float
    {
        if ($thickness && $thickness > 0) {
            return round($thickness * ($length ?? 1) * $quantity * $unitPrice, 2);
        }
        if ($length && $length > 0) {
            return round($length * $quantity * $unitPrice, 2);
        }
        return round($quantity * $unitPrice, 2);
    }

    private function calculateTotals(Request $request): array
    {
        $subtotal = 0;
        foreach ($request->items as $item) {
            $thickness = isset($item['thickness']) ? (float) $item['thickness'] : null;
            $length = isset($item['length']) ? (float) $item['length'] : null;
            $subtotal += $this->calculateItemAmount($thickness, $length, $item['quantity'], $item['unit_price']);
        }

        $discountType = $request->discount_type ?? 'amount';
        $discountValue = (float) ($request->discount_value ?? 0);
        $discount_amount = $discountType === 'percent'
            ? round($subtotal * $discountValue / 100, 2)
            : $discountValue;

        $afterDiscount = $subtotal - $discount_amount;
        $vatRate = (float) ($request->vat_rate ?? 7);
        $vat_amount = round($afterDiscount * $vatRate / 100, 2);
        $total = round($afterDiscount + $vat_amount, 2);

        return compact('subtotal', 'discount_amount', 'vat_amount', 'total');
    }

    public function exportPdf(Request $request, Quotation $quotation)
    {
        // Authenticate via query token (window.open cannot send Bearer header)
        $token = $request->query('token');
        if (!$token) {
            abort(401, 'Unauthorized');
        }
        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            abort(401, 'Unauthorized');
        }

        $quotation->load(['customer', 'shippingAddress', 'items.product.sizes', 'creator']);

        $company = CompanySetting::getAll();
        $isVat = $quotation->account_type === 'tax';

        // Logo as data URI
        $logoDataUri = null;
        if (!empty($company['logo']) && Storage::disk('public')->exists($company['logo'])) {
            $logoFile = Storage::disk('public')->path($company['logo']);
            $logoMime = mime_content_type($logoFile) ?: 'image/png';
            $logoDataUri = 'data:' . $logoMime . ';base64,' . base64_encode(file_get_contents($logoFile));
        }

        $createdDate = $quotation->created_at->format('d/m/Y');
        $bahtText = $this->numberToThaiText((float) $quotation->total);

        // QR code as inline SVG data URI
        $qrCode = new QrCode($quotation->quotation_number, 'L');
        $qrCode->disableBorder();
        $qrSvg = (new QrSvg())->output($qrCode, 100);
        $qrDataUri = 'data:image/svg+xml;base64,' . base64_encode($qrSvg);

        // Watermark
        $watermark = null;
        if ($quotation->status === 'cancelled') {
            $watermark = 'ยกเลิก';
        } elseif ($quotation->valid_until && \Carbon\Carbon::parse($quotation->valid_until)->endOfDay()->isPast()) {
            $watermark = 'เลยกำหนดยืนราคา';
        }

        return response()
            ->view('quotations.pdf', compact(
                'quotation', 'company', 'isVat', 'logoDataUri', 'createdDate', 'bahtText', 'qrDataUri', 'watermark'
            ))
            ->header('Content-Type', 'text/html; charset=utf-8');
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
                if ($pos === 1 && $d === 1) { $result .= 'สิบ'; continue; }
                if ($pos === 1 && $d === 2) { $result .= 'ยี่สิบ'; continue; }
                if ($pos === 0 && $d === 1 && $len > 1) { $result .= 'เอ็ด'; continue; }
                $result .= $digits[$d] . $positions[$pos];
            }
            return $result;
        };

        $intPart = (int) floor($num);
        $decPart = (int) round(($num - $intPart) * 100);

        $text = '';
        if ($intPart > 999999) {
            $millions = (int) floor($intPart / 1000000);
            $remainder = $intPart % 1000000;
            $text = $convert($millions) . 'ล้าน' . $convert($remainder);
        } else {
            $text = $convert($intPart);
        }

        $text .= 'บาท';
        if ($decPart > 0) {
            $text .= $convert($decPart) . 'สตางค์';
        } else {
            $text .= 'ถ้วน';
        }

        return $text;
    }
}
