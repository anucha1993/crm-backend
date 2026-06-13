<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use Illuminate\Http\JsonResponse;

class TrackingController extends Controller
{
    /**
     * Public endpoint: resolve any document number (quotation / order / delivery /
     * invoice / payment) into a unified tracking timeline for the related order.
     * The QR code printed on documents stores only the document number.
     */
    public function show(string $number): JsonResponse
    {
        $number = trim($number);
        $upper = strtoupper($number);

        $quotation = null;
        $order = null;

        if (str_starts_with($upper, 'QT-')) {
            $quotation = $this->findQuotation($number);
            $order = $quotation ? $this->findOrderByQuotation($quotation->id) : null;
        } elseif (str_starts_with($upper, 'ORD-')) {
            $order = $this->findOrder($number);
        } elseif (str_starts_with($upper, 'DLV-')) {
            $delivery = Delivery::withoutGlobalScope('account')->where('delivery_number', $number)->first();
            $order = $delivery ? $this->findOrderById($delivery->order_id) : null;
        } elseif (str_starts_with($upper, 'IV')) {
            $invoice = Invoice::withoutGlobalScope('account')->where('invoice_number', $number)->first();
            $order = $invoice ? $this->findOrderById($invoice->order_id) : null;
        } elseif (str_starts_with($upper, 'PAY-')) {
            $payment = Payment::withoutGlobalScope('account')->where('payment_number', $number)->first();
            $order = $payment ? $this->findOrderById($payment->order_id) : null;
        } else {
            // Unknown prefix: try order then quotation
            $order = $this->findOrder($number);
            if (!$order) {
                $quotation = $this->findQuotation($number);
                $order = $quotation ? $this->findOrderByQuotation($quotation->id) : null;
            }
        }

        if (!$order && !$quotation) {
            return response()->json(['message' => 'ไม่พบเอกสาร'], 404);
        }

        if ($order && !$quotation) {
            $quotation = $order->quotation_id ? $this->findQuotation(null, $order->quotation_id) : null;
        }

        $events = [];

        // Quotation phase (creation + revisions/edits)
        if ($quotation) {
            $quotation->load(['revisions.user:id,name', 'customer:id,name']);
            foreach ($quotation->revisions as $rev) {
                $events[] = [
                    'stage' => 'quotation',
                    'action' => $rev->action,
                    'title' => $this->revisionTitle($rev->action),
                    'summary' => $rev->summary,
                    'user' => $rev->user?->name,
                    'at' => optional($rev->created_at)->toIso8601String(),
                ];
            }
        }

        // Order / payment / invoice / delivery phases (from the order activity log)
        if ($order) {
            $order->load(['paymentLogs.user:id,name', 'customer:id,name']);
            foreach ($order->paymentLogs as $log) {
                $events[] = [
                    'stage' => $this->stageForAction($log->action),
                    'action' => $log->action,
                    'title' => $this->logTitle($log->action),
                    'summary' => $log->summary,
                    'user' => $log->user?->name,
                    'at' => optional($log->created_at)->toIso8601String(),
                ];
            }
        }

        // Sort ascending by time (nulls last)
        usort($events, function ($a, $b) {
            return strcmp($a['at'] ?? '9999', $b['at'] ?? '9999');
        });

        $customerName = $order?->customer?->name ?? $quotation?->customer?->name ?? '-';

        $deliveries = [];
        $invoices = [];
        if ($order) {
            $deliveries = $order->deliveries()
                ->withoutGlobalScope('account')
                ->orderBy('created_at')
                ->get(['id', 'delivery_number', 'status', 'delivery_date', 'delivered_at', 'created_at'])
                ->map(fn ($d) => [
                    'number' => $d->delivery_number,
                    'status' => $d->status,
                    'delivery_date' => optional($d->delivery_date)->toDateString(),
                    'delivered_at' => optional($d->delivered_at)->toIso8601String(),
                ]);

            $invoices = $order->invoices()
                ->withoutGlobalScope('account')
                ->orderBy('created_at')
                ->get(['id', 'invoice_number', 'status', 'issue_date', 'total'])
                ->map(fn ($i) => [
                    'number' => $i->invoice_number,
                    'status' => $i->status,
                    'issue_date' => optional($i->issue_date)->toDateString(),
                    'total' => $i->total,
                ]);
        }

        return response()->json([
            'document' => [
                'number' => $number,
                'type' => $this->documentType($upper),
            ],
            'customer_name' => $customerName,
            'account_type' => $order?->account_type ?? $quotation?->account_type,
            'quotation' => $quotation ? [
                'number' => $quotation->quotation_number,
                'status' => $quotation->status,
                'revision_number' => $quotation->revision_number,
                'total' => $quotation->total,
                'valid_until' => optional($quotation->valid_until)->toDateString(),
                'created_at' => optional($quotation->created_at)->toIso8601String(),
            ] : null,
            'order' => $order ? [
                'number' => $order->order_number,
                'status' => $order->status,
                'delivery_status' => $order->delivery_status,
                'total' => $order->total,
                'paid_amount' => $order->paid_amount,
                'remaining_amount' => $order->remaining_amount,
                'created_at' => optional($order->created_at)->toIso8601String(),
            ] : null,
            'deliveries' => $deliveries,
            'invoices' => $invoices,
            'events' => $events,
        ]);
    }

    private function findQuotation(?string $number, ?int $id = null): ?Quotation
    {
        $query = Quotation::withoutGlobalScope('account');
        if ($id !== null) {
            return $query->find($id);
        }
        return $query->where('quotation_number', $number)->first();
    }

    private function findOrder(string $number): ?Order
    {
        return Order::withoutGlobalScope('account')->where('order_number', $number)->first();
    }

    private function findOrderById(?int $id): ?Order
    {
        return $id ? Order::withoutGlobalScope('account')->find($id) : null;
    }

    private function findOrderByQuotation(int $quotationId): ?Order
    {
        return Order::withoutGlobalScope('account')->where('quotation_id', $quotationId)->first();
    }

    private function documentType(string $upper): string
    {
        return match (true) {
            str_starts_with($upper, 'QT-') => 'quotation',
            str_starts_with($upper, 'ORD-') => 'order',
            str_starts_with($upper, 'DLV-') => 'delivery',
            str_starts_with($upper, 'IV') => 'invoice',
            str_starts_with($upper, 'PAY-') => 'payment',
            default => 'unknown',
        };
    }

    private function stageForAction(string $action): string
    {
        return match ($action) {
            'order_created', 'order_status_changed' => 'order',
            'created', 'approved', 'rejected', 'resubmitted' => 'payment',
            'invoice_created' => 'invoice',
            'delivery_created', 'delivery_confirmed', 'delivery_cancelled' => 'delivery',
            default => 'order',
        };
    }

    private function revisionTitle(string $action): string
    {
        return match ($action) {
            'created' => 'สร้างใบเสนอราคา',
            'updated' => 'แก้ไขใบเสนอราคา',
            'status_changed' => 'เปลี่ยนสถานะใบเสนอราคา',
            'sent' => 'ส่งใบเสนอราคา',
            'approved' => 'อนุมัติใบเสนอราคา',
            'rejected' => 'ปฏิเสธใบเสนอราคา',
            'duplicated' => 'คัดลอกใบเสนอราคา',
            default => 'อัปเดตใบเสนอราคา',
        };
    }

    private function logTitle(string $action): string
    {
        return match ($action) {
            'order_created' => 'สร้างคำสั่งซื้อ',
            'order_status_changed' => 'เปลี่ยนสถานะคำสั่งซื้อ',
            'created' => 'บันทึกการชำระเงิน',
            'approved' => 'อนุมัติการชำระเงิน',
            'rejected' => 'ปฏิเสธการชำระเงิน',
            'resubmitted' => 'ส่งการชำระเงินใหม่',
            'invoice_created' => 'ออกใบกำกับภาษี/ใบเสร็จ',
            'delivery_created' => 'สร้างใบส่งของ',
            'delivery_confirmed' => 'ยืนยันการจัดส่ง',
            'delivery_cancelled' => 'ยกเลิกใบส่งของ',
            default => 'อัปเดตคำสั่งซื้อ',
        };
    }
}
