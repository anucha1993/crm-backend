<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\PaymentLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;
use Mpdf\Mpdf;

class InvoiceController extends Controller
{
    use \App\Http\Controllers\Concerns\ScopesOwnedRecords;

    public function index(Request $request): JsonResponse
    {
        $accountType = $request->attributes->get('account_type');
        $query = Invoice::with(['order:id,order_number', 'customer:id,name,code', 'creator:id,name'])
            ->where('account_type', $accountType);

        $this->scopeToOwner($query, $request);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhere('cancelled_invoice_number', 'like', "%{$search}%")
                  ->orWhereHas('order', fn ($oq) => $oq->where('order_number', 'like', "%{$search}%"))
                  ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $invoices = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 10));

        return response()->json($invoices);
    }

    public function show(Invoice $invoice, Request $request): JsonResponse
    {
        $this->ensureAccountMatch($invoice, $request);
        $invoice->load([
            'order.customer', 'order.shippingAddress',
            'customer', 'shippingAddress',
            'items.product.sizes', 'items.orderItem',
            'creator:id,name', 'canceller:id,name',
        ]);

        return response()->json(['invoice' => $invoice]);
    }

    /**
     * Preview of the number the system would assign right now, shown in the
     * issue dialog (editable there for users with invoices.edit_number).
     */
    public function nextNumber(Request $request): JsonResponse
    {
        return response()->json([
            'invoice_number' => Invoice::generateNumber(),
            'can_edit' => $this->userCan($request, 'invoices.edit_number'),
        ]);
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        $accountType = $request->attributes->get('account_type');
        if ($accountType && $order->account_type !== $accountType) {
            abort(404, 'ไม่พบคำสั่งซื้อในบัญชีปัจจุบัน');
        }

        // Validate order is fully paid — bypassed for credit customers (ลูกค้าเครดิต),
        // who are allowed to receive the invoice before settling payment.
        $isCreditCustomer = (bool) $order->customer()->value('is_credit');
        if (!$isCreditCustomer && (float) $order->remaining_amount > 0) {
            return response()->json([
                'message' => 'ไม่สามารถออกใบกำกับภาษีได้ คำสั่งซื้อยังชำระเงินไม่ครบ',
            ], 422);
        }

        if ($order->status === 'cancelled') {
            return response()->json([
                'message' => 'ไม่สามารถออกใบกำกับภาษีได้ คำสั่งซื้อถูกยกเลิก',
            ], 422);
        }

        // Check if order already has an active invoice
        $existingInvoice = Invoice::where('order_id', $order->id)
            ->where('status', 'issued')
            ->first();

        if ($existingInvoice) {
            return response()->json([
                'message' => 'คำสั่งซื้อนี้มีใบกำกับภาษีอยู่แล้ว: ' . $existingInvoice->invoice_number,
            ], 422);
        }

        // Cash bills never get an invoice number (it stays NULL).
        // For tax invoices, a custom number is only honoured for users allowed
        // to edit it; everyone else always gets the system-generated number.
        $isCash = $order->account_type === 'cash';
        $canEditNumber = !$isCash && $this->userCan($request, 'invoices.edit_number');
        $request->validate([
            'notes' => 'nullable|string|max:1000',
            'issue_date' => 'nullable|date|before_or_equal:today',
            'invoice_number' => $canEditNumber ? $this->numberRules() : 'nullable',
        ]);
        $customNumber = $canEditNumber && $request->filled('invoice_number')
            ? trim($request->invoice_number)
            : null;

        $order->load(['items.product', 'customer', 'shippingAddress']);

        $invoice = DB::transaction(function () use ($request, $order, $customNumber, $isCash) {
            $invoice = Invoice::create([
                'account_type' => $order->account_type,
                'invoice_number' => $isCash ? null : ($customNumber ?? Invoice::generateNumber()),
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'customer_address_id' => $order->customer_address_id,
                'status' => 'issued',
                'issue_date' => $request->input('issue_date', now()->toDateString()),
                'subtotal' => $order->subtotal,
                'discount_type' => $order->discount_type,
                'discount_value' => $order->discount_value,
                'discount_amount' => $order->discount_amount,
                'vat_rate' => $order->vat_rate,
                'vat_amount' => $order->vat_amount,
                'total' => $order->total,
                'notes' => $request->notes,
                'created_by' => $request->user()->id,
            ]);

            // Copy items from order
            foreach ($order->items as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'order_item_id' => $item->id,
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

            // Log
            PaymentLog::create([
                'order_id' => $order->id,
                'action' => 'invoice_created',
                'summary' => $isCash ? 'ออกบิลเงินสด' : 'ออกใบกำกับภาษี ' . $invoice->invoice_number,
                'details' => [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'total' => (float) $invoice->total,
                ],
                'user_id' => $request->user()->id,
            ]);

            return $invoice;
        });

        $invoice->load(['items.product.sizes', 'order', 'customer', 'creator:id,name']);

        return response()->json(['invoice' => $invoice], 201);
    }

    /**
     * List orders that are fully paid in the current account scope,
     * showing whether an invoice (tax invoice / cash bill) has been issued.
     * Reference date = latest approved payment's approved_at.
     */
    public function pending(Request $request): JsonResponse
    {
        $accountType = $request->attributes->get('account_type');

        $query = Order::query()
            ->where('account_type', $accountType)
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) {
                // Normal orders only show up once fully paid; credit customers
                // (is_credit) are allowed to be invoiced regardless of balance.
                $q->where(function ($qq) {
                    $qq->whereRaw('CAST(remaining_amount AS DECIMAL(15,2)) <= 0')
                        ->whereRaw('CAST(paid_amount AS DECIMAL(15,2)) > 0');
                })->orWhereHas('customer', fn ($cq) => $cq->where('is_credit', true));
            })
            ->with([
                'customer:id,code,name,tax_id,phone,is_credit',
                'invoices' => fn ($q) => $q->where('status', 'issued')
                    ->select('id', 'order_id', 'invoice_number', 'issue_date'),
            ])
            ->withMax(['payments as last_paid_at' => fn ($q) => $q->where('status', 'approved')], 'approved_at');

        $this->scopeToOwner($query, $request);

        // Filter by month of latest approved payment (YYYY-MM)
        if ($request->filled('month')) {
            $month = $request->month;
            $query->whereHas('payments', function ($q) use ($month) {
                $q->where('status', 'approved')
                  ->whereRaw("DATE_FORMAT(approved_at, '%Y-%m') = ?", [$month]);
            });
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('order_number', 'like', "%{$s}%")
                  ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$s}%")
                      ->orWhere('code', 'like', "%{$s}%"));
            });
        }

        $orders = $query->orderByDesc('last_paid_at')->get();

        $rows = $orders->map(function ($order) {
            $invoice = $order->invoices->first();
            return [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'customer' => $order->customer ? [
                    'id' => $order->customer->id,
                    'code' => $order->customer->code,
                    'name' => $order->customer->name,
                    'tax_id' => $order->customer->tax_id,
                    'phone' => $order->customer->phone,
                    'is_credit' => (bool) $order->customer->is_credit,
                ] : null,
                'total' => (float) $order->total,
                'paid_amount' => (float) $order->paid_amount,
                'remaining_amount' => (float) $order->remaining_amount,
                'last_paid_at' => $order->last_paid_at,
                'invoice_issued' => (bool) $invoice,
                'invoice' => $invoice ? [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'issue_date' => $invoice->issue_date,
                ] : null,
            ];
        });

        // Optional status filter (issued / pending) applied after map
        if ($request->filled('issued')) {
            $want = $request->issued === '1' || $request->issued === 'true';
            $rows = $rows->filter(fn ($r) => $r['invoice_issued'] === $want)->values();
        }

        $summary = [
            'total' => $rows->count(),
            'issued' => $rows->where('invoice_issued', true)->count(),
            'pending' => $rows->where('invoice_issued', false)->count(),
        ];

        return response()->json([
            'data' => $rows,
            'summary' => $summary,
        ]);
    }

    public function cancel(Request $request, Invoice $invoice): JsonResponse
    {
        $this->ensureAccountMatch($invoice, $request);
        if ($invoice->status !== 'issued') {
            return response()->json(['message' => 'ใบกำกับภาษีนี้ไม่สามารถยกเลิกได้'], 422);
        }

        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        // Release the number (→ NULL) so it can be reused; keep the original for audit / PDF.
        $number = $invoice->invoice_number;
        $invoice->update([
            'status' => 'cancelled',
            'invoice_number' => null,
            'cancelled_invoice_number' => $number,
            'cancelled_by' => $request->user()->id,
            'cancelled_at' => now(),
            'cancel_reason' => $request->reason,
        ]);

        PaymentLog::create([
            'order_id' => $invoice->order_id,
            'action' => 'invoice_cancelled',
            'summary' => $invoice->account_type === 'cash' ? 'ยกเลิกบิลเงินสด' : 'ยกเลิกใบกำกับภาษี ' . $number,
            'details' => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $number,
                'reason' => $request->reason,
            ],
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['invoice' => $invoice]);
    }

    /**
     * Correct the issue_date and/or invoice_number of an already-issued invoice.
     * Date changes need invoices.create; number changes need invoices.edit_number.
     * Everything else is derived from the order at creation time.
     */
    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $this->ensureAccountMatch($invoice, $request);
        if ($invoice->status !== 'issued') {
            return response()->json(['message' => 'แก้ไขได้เฉพาะใบกำกับภาษีที่ยังไม่ถูกยกเลิก'], 422);
        }

        if ($invoice->account_type === 'cash' && $request->filled('invoice_number')) {
            return response()->json(['message' => 'บิลเงินสดไม่มีเลขที่ใบกำกับภาษี'], 422);
        }

        $request->validate([
            'issue_date' => 'required_without:invoice_number|nullable|date|before_or_equal:today',
            'invoice_number' => ['required_without:issue_date', ...$this->numberRules($invoice->id)],
        ]);

        $oldNumber = $invoice->invoice_number;
        $dateChanged = $request->filled('issue_date')
            && $request->issue_date !== $invoice->issue_date->toDateString();
        $numberChanged = $request->filled('invoice_number')
            && trim($request->invoice_number) !== $oldNumber;

        if ($dateChanged && !$this->userCan($request, 'invoices.create')) {
            return response()->json(['message' => 'คุณไม่มีสิทธิ์แก้ไขวันที่ใบกำกับภาษี'], 403);
        }
        if ($numberChanged && !$this->userCan($request, 'invoices.edit_number')) {
            return response()->json(['message' => 'คุณไม่มีสิทธิ์แก้ไขเลขที่ใบกำกับภาษี'], 403);
        }

        $changes = [];
        if ($dateChanged) $changes['issue_date'] = $request->issue_date;
        if ($numberChanged) $changes['invoice_number'] = trim($request->invoice_number);

        if ($changes) {
            $invoice->update($changes);

            if ($dateChanged) {
                PaymentLog::create([
                    'order_id' => $invoice->order_id,
                    'action' => 'invoice_date_updated',
                    'summary' => 'แก้ไขวันที่ใบกำกับภาษี ' . $invoice->invoice_number . ' เป็น ' . $invoice->issue_date->toDateString(),
                    'details' => ['invoice_id' => $invoice->id],
                    'user_id' => $request->user()->id,
                ]);
            }
            if ($numberChanged) {
                PaymentLog::create([
                    'order_id' => $invoice->order_id,
                    'action' => 'invoice_number_updated',
                    'summary' => 'แก้ไขเลขที่ใบกำกับภาษี ' . $oldNumber . ' เป็น ' . $invoice->invoice_number,
                    'details' => [
                        'invoice_id' => $invoice->id,
                        'old_number' => $oldNumber,
                        'new_number' => $invoice->invoice_number,
                    ],
                    'user_id' => $request->user()->id,
                ]);
            }
        }

        $invoice->load(['order:id,order_number', 'customer:id,name,code', 'creator:id,name']);

        return response()->json(['invoice' => $invoice]);
    }

    private function userCan(Request $request, string $permission): bool
    {
        $user = $request->user();
        return $user->hasRole('admin') || $user->hasPermission($permission);
    }

    private function numberRules(?int $ignoreId = null): array
    {
        return [
            'nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9\-\/]+$/',
            Rule::unique('invoices', 'invoice_number')->ignore($ignoreId),
        ];
    }

    private function ensureAccountMatch(Invoice $invoice, Request $request): void
    {
        $accountType = $request->attributes->get('account_type');
        if ($accountType && $invoice->account_type !== $accountType) {
            abort(404, 'ไม่พบเอกสารในบัญชีปัจจุบัน');
        }
    }

    public function exportPdf(Request $request, Invoice $invoice)
    {
        $token = $request->query('token');
        if (!$token) {
            abort(401, 'Unauthorized');
        }
        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            abort(401, 'Unauthorized');
        }

        $invoice->load(['customer', 'shippingAddress', 'items.product.sizes', 'creator', 'order']);

        $company = CompanySetting::getAll();
        $isVat = $invoice->account_type === 'tax';

        $logoPath = null;
        if (!empty($company['logo']) && Storage::disk('public')->exists($company['logo'])) {
            $logoPath = Storage::disk('public')->path($company['logo']);
        }

        $buddhistYear = (int) $invoice->issue_date->format('Y') + 543;
        $issueDate = $invoice->issue_date->format('d/m/') . $buddhistYear;
        $bahtText = $this->numberToThaiText((float) $invoice->total);

        // Cash bills have no invoice number — fall back to the order number for file name / QR.
        $number = $invoice->invoice_number ?? $invoice->cancelled_invoice_number ?? $invoice->order?->order_number;
        $qrData = $number;

        $html = view('invoices.pdf', compact(
            'invoice', 'company', 'isVat', 'logoPath', 'issueDate', 'bahtText', 'qrData', 'number'
        ))->render();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'angsananew',
            'margin_top' => 5,
            'margin_bottom' => 8,
            'margin_left' => 8,
            'margin_right' => 8,
            'fontDir' => [public_path('fonts')],
            'fontdata' => [
                'angsananew' => [
                    // Standalone .ttf files extracted from angsana.ttc — the installed
                    // mpdf/mpdf 8.3.1 has a bug where TTCfontID always resolves to font #1
                    // regardless of style (isset() typo in Mpdf.php), so B/I/BI silently
                    // rendered as Regular when pointed at the .ttc directly with TTCfontID.
                    'R' => 'angsana-regular.ttf',
                    'B' => 'angsana-bold.ttf',
                    'I' => 'angsana-italic.ttf',
                    'BI' => 'angsana-bolditalic.ttf',
                    // Needed for correct Thai mark stacking (e.g. "พื้น", "เร็จ") — without
                    // this, stacked tone marks/vowels overlap instead of positioning above each other.
                    'useOTL' => 0xFF,
                ],
            ],
            'tempDir' => storage_path('app/mpdf-temp'),
        ]);

        $mpdf->SetTitle('ใบกำกับภาษี ' . $number);
        $mpdf->SetAuthor($company['name'] ?? 'CRM');
        if ($invoice->status === 'cancelled') {
            $mpdf->SetWatermarkText('ยกเลิก', 0.12);
            $mpdf->showWatermarkText = true;
        }
        $mpdf->WriteHTML($html);

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $number . '.pdf"',
        ]);
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
