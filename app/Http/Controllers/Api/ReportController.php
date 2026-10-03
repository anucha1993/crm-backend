<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerLevel;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    use \App\Http\Controllers\Concerns\ScopesOwnedRecords;

    /**
     * Dashboard summary — overview stats + charts data
     */
    public function dashboard(Request $request): JsonResponse
    {
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();

        // Feature #6 — a sales user only sees their own records and no grand totals.
        $restricted = $this->isSalesRestricted($request);
        $ownerId = $request->user()->id;
        $own = fn ($q) => $restricted ? $q->where('created_by', $ownerId) : $q;

        // Summary cards
        $monthlyOrders = $own(Order::whereBetween('created_at', [$startOfMonth, $endOfMonth]))->count();
        $monthlySales = $own(Order::whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->where('status', '!=', 'cancelled'))
            ->sum('total');
        $monthlyPayments = $own(Payment::where('status', 'approved')
            ->whereBetween('approved_at', [$startOfMonth, $endOfMonth]))
            ->sum('amount');
        $pendingPayments = $own(Payment::where('status', 'pending'))->count();
        $totalReceivable = $own(Order::where('status', '!=', 'cancelled')
            ->where('remaining_amount', '>', 0))
            ->sum('remaining_amount');
        $todayDeliveries = $own(Delivery::whereDate('delivery_date', $now->toDateString())
            ->where('status', '!=', 'cancelled'))
            ->count();
        $newCustomers = $own(Customer::whereBetween('created_at', [$startOfMonth, $endOfMonth]))->count();
        $totalCustomers = $own(Customer::query())->count();

        // Sales trend — last 12 months
        $salesTrend = Order::where('status', '!=', 'cancelled')
            ->where('created_at', '>=', $now->copy()->subMonths(11)->startOfMonth())
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                DB::raw('SUM(total) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // Order status breakdown
        $orderStatuses = Order::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        // Payment method breakdown (approved only this month)
        $paymentMethods = Payment::where('status', 'approved')
            ->whereBetween('approved_at', [$startOfMonth, $endOfMonth])
            ->select('method', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('method')
            ->get();

        // Top 5 customers (by sales this month)
        $topCustomers = Order::where('status', '!=', 'cancelled')
            ->whereBetween('orders.created_at', [$startOfMonth, $endOfMonth])
            ->join('customers', 'orders.customer_id', '=', 'customers.id')
            ->select('customers.id', 'customers.name', 'customers.code',
                DB::raw('SUM(orders.total) as total_sales'),
                DB::raw('COUNT(orders.id) as order_count'))
            ->groupBy('customers.id', 'customers.name', 'customers.code')
            ->orderByDesc('total_sales')
            ->limit(5)
            ->get();

        // Top 5 products (by quantity this month)
        $topProducts = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.status', '!=', 'cancelled')
            ->whereBetween('orders.created_at', [$startOfMonth, $endOfMonth])
            ->select(
                'order_items.description',
                DB::raw('COALESCE(products.name, order_items.description) as product_name'),
                DB::raw('SUM(order_items.quantity) as total_qty'),
                DB::raw('SUM(order_items.amount) as total_amount')
            )
            ->groupBy('order_items.description', 'product_name')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get();

        return response()->json([
            'restricted' => $restricted,
            'summary' => [
                'monthly_orders' => $monthlyOrders,
                // Grand sales/payment totals are hidden for sales-restricted users.
                'monthly_sales' => $restricted ? null : round($monthlySales, 2),
                'monthly_payments' => $restricted ? null : round($monthlyPayments, 2),
                'pending_payments' => $pendingPayments,
                'total_receivable' => round($totalReceivable, 2), // ยอดค้างชำระ (own)
                'today_deliveries' => $todayDeliveries,
                'new_customers' => $newCustomers,
                'total_customers' => $totalCustomers,
            ],
            'sales_trend' => $restricted ? [] : $salesTrend,
            'order_statuses' => $orderStatuses,
            'payment_methods' => $restricted ? [] : $paymentMethods,
            'top_customers' => $restricted ? [] : $topCustomers,
            'top_products' => $restricted ? [] : $topProducts,
        ]);
    }

    /**
     * Sales by seller (salesperson) report
     */
    public function salesBySeller(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->endOfMonth()->toDateString());
        $today = now()->toDateString();
        $staleBefore = now()->subDays(30)->toDateString();

        // A sales-restricted user only sees their own performance row.
        $restricted = $this->isSalesRestricted($request);
        $ownerId = $request->user()->id;

        // Orders are auto-created by whoever approves the quotation, so the seller
        // is the quotation's creator (falling back to the order's creator).
        $orderSeller = 'COALESCE(quotations.created_by, orders.created_by)';

        // ── Quotation funnel (cohort = quotations created in the period) ──
        $openExpired = "quotations.status IN ('draft','sent') AND quotations.valid_until IS NOT NULL AND quotations.valid_until < ?";
        $approvedAt = '(SELECT MIN(o.created_at) FROM orders o WHERE o.quotation_id = quotations.id)';

        $quoteStats = Quotation::query()
            ->whereNotNull('quotations.created_by')
            ->whereDate('quotations.created_at', '>=', $from)
            ->whereDate('quotations.created_at', '<=', $to)
            ->when($restricted, fn ($q) => $q->where('quotations.created_by', $ownerId))
            ->select(
                'quotations.created_by as seller_id',
                DB::raw('COUNT(*) as quote_count'),
                DB::raw('SUM(quotations.total) as quote_value'),
                DB::raw("SUM(quotations.status = 'draft') as draft_count"),
                DB::raw("SUM(quotations.status = 'sent') as sent_count"),
                DB::raw("SUM(quotations.status = 'approved') as approved_count"),
                DB::raw("SUM(quotations.status = 'rejected') as rejected_count"),
                DB::raw("SUM(quotations.status = 'cancelled') as cancelled_count"),
                DB::raw("SUM(CASE WHEN quotations.status = 'approved' THEN quotations.total ELSE 0 END) as approved_value"),
                DB::raw("SUM(CASE WHEN quotations.status IN ('rejected','cancelled') THEN quotations.total ELSE 0 END) as lost_value"),
                DB::raw('AVG(quotations.revision_number) as avg_revisions'),
                DB::raw('COUNT(DISTINCT quotations.customer_id) as quoted_customers'),
                DB::raw("AVG(CASE WHEN quotations.status = 'approved' THEN TIMESTAMPDIFF(HOUR, quotations.created_at, {$approvedAt}) / 24 END) as avg_days_to_approve")
            )
            ->selectRaw("SUM({$openExpired}) as expired_count", [$today])
            ->selectRaw("SUM(CASE WHEN quotations.status IN ('draft','sent') AND NOT ({$openExpired}) THEN quotations.total ELSE 0 END) as pipeline_value", [$today])
            // Open (not expired) for 30+ days without an outcome — follow-up needed
            ->selectRaw("SUM(quotations.status IN ('draft','sent') AND NOT ({$openExpired}) AND DATE(quotations.created_at) < ?) as stale_count", [$today, $staleBefore])
            ->groupBy('quotations.created_by')
            ->get()
            ->keyBy('seller_id');

        // ── Orders created in the period ──
        $orderStats = Order::query()
            ->leftJoin('quotations', 'orders.quotation_id', '=', 'quotations.id')
            ->whereDate('orders.created_at', '>=', $from)
            ->whereDate('orders.created_at', '<=', $to)
            ->whereRaw("{$orderSeller} IS NOT NULL")
            ->when($restricted, fn ($q) => $q->whereRaw("{$orderSeller} = ?", [$ownerId]))
            ->select(
                DB::raw("{$orderSeller} as seller_id"),
                DB::raw("SUM(orders.status != 'cancelled') as order_count"),
                DB::raw("SUM(CASE WHEN orders.status != 'cancelled' THEN orders.total ELSE 0 END) as total_sales"),
                DB::raw("COUNT(DISTINCT CASE WHEN orders.status != 'cancelled' THEN orders.customer_id END) as customer_count"),
                DB::raw("SUM(orders.status = 'completed') as completed_count"),
                DB::raw("SUM(orders.status = 'cancelled') as cancelled_order_count"),
                DB::raw("SUM(CASE WHEN orders.status = 'cancelled' THEN orders.total ELSE 0 END) as cancelled_order_value"),
                DB::raw("SUM(CASE WHEN orders.status != 'cancelled' THEN orders.paid_amount ELSE 0 END) as paid_amount"),
                DB::raw("SUM(CASE WHEN orders.status != 'cancelled' THEN orders.remaining_amount ELSE 0 END) as remaining_amount")
            )
            ->groupBy('seller_id')
            ->get()
            ->keyBy('seller_id');

        // ── New customers registered by each seller ──
        $newCustomers = Customer::query()
            ->whereNotNull('created_by')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->when($restricted, fn ($q) => $q->where('created_by', $ownerId))
            ->select('created_by as seller_id', DB::raw('COUNT(*) as new_customers'))
            ->groupBy('created_by')
            ->pluck('new_customers', 'seller_id');

        $sellerIds = $quoteStats->keys()
            ->merge($orderStats->keys())
            ->merge($newCustomers->keys())
            ->unique()
            ->values();
        $names = User::whereIn('id', $sellerIds)->pluck('name', 'id');

        $pct = fn ($num, $den) => $den > 0 ? round($num / $den * 100, 1) : null;

        $sellers = $sellerIds->map(function ($id) use ($quoteStats, $orderStats, $newCustomers, $names, $pct) {
            $q = $quoteStats->get($id);
            $o = $orderStats->get($id);

            $quoteCount = (int) ($q->quote_count ?? 0);
            $approved = (int) ($q->approved_count ?? 0);
            $rejected = (int) ($q->rejected_count ?? 0);
            $cancelled = (int) ($q->cancelled_count ?? 0);
            $expired = (int) ($q->expired_count ?? 0);
            $stale = (int) ($q->stale_count ?? 0);
            $quoteValue = (float) ($q->quote_value ?? 0);
            $approvedValue = (float) ($q->approved_value ?? 0);
            $orderCount = (int) ($o->order_count ?? 0);
            $totalSales = (float) ($o->total_sales ?? 0);
            $paid = (float) ($o->paid_amount ?? 0);

            return [
                'id' => (int) $id,
                'name' => $names[$id] ?? ('#' . $id),
                // Quotations
                'quote_count' => $quoteCount,
                'quote_value' => round($quoteValue, 2),
                'avg_quote_value' => $quoteCount > 0 ? round($quoteValue / $quoteCount, 2) : 0,
                'draft_count' => (int) ($q->draft_count ?? 0),
                'sent_count' => (int) ($q->sent_count ?? 0),
                'approved_count' => $approved,
                'rejected_count' => $rejected,
                'cancelled_count' => $cancelled,
                'expired_count' => $expired,
                'stale_count' => $stale,
                'approved_value' => round($approvedValue, 2),
                'lost_value' => round((float) ($q->lost_value ?? 0), 2),
                'pipeline_value' => round((float) ($q->pipeline_value ?? 0), 2),
                'quoted_customers' => (int) ($q->quoted_customers ?? 0),
                'avg_revisions' => $q ? round((float) $q->avg_revisions, 1) : null,
                'avg_days_to_approve' => $q && $q->avg_days_to_approve !== null ? round((float) $q->avg_days_to_approve, 1) : null,
                // Rates (%) — win_rate counts only quotations with an outcome
                'conversion_rate' => $pct($approved, $quoteCount),
                'win_rate' => $pct($approved, $approved + $rejected + $cancelled + $expired),
                'cancel_rate' => $pct($cancelled, $quoteCount),
                'reject_rate' => $pct($rejected, $quoteCount),
                'expired_rate' => $pct($expired, $quoteCount),
                'stale_rate' => $pct($stale, $quoteCount),
                'value_win_rate' => $pct($approvedValue, $quoteValue),
                // Orders
                'order_count' => $orderCount,
                'total_sales' => round($totalSales, 2),
                'avg_per_order' => $orderCount > 0 ? round($totalSales / $orderCount, 2) : 0,
                'customer_count' => (int) ($o->customer_count ?? 0),
                'completed_count' => (int) ($o->completed_count ?? 0),
                'cancelled_order_count' => (int) ($o->cancelled_order_count ?? 0),
                'cancelled_order_value' => round((float) ($o->cancelled_order_value ?? 0), 2),
                'paid_amount' => round($paid, 2),
                'remaining_amount' => round((float) ($o->remaining_amount ?? 0), 2),
                'collection_rate' => $pct($paid, $totalSales),
                'new_customers' => (int) ($newCustomers[$id] ?? 0),
            ];
        })
            ->sortBy([['total_sales', 'desc'], ['quote_count', 'desc']])
            ->values();

        // ── Team totals ──
        $sum = fn ($key) => $sellers->sum($key);
        $tQuotes = $sum('quote_count');
        $tApproved = $sum('approved_count');
        $tDecided = $tApproved + $sum('rejected_count') + $sum('cancelled_count') + $sum('expired_count');
        $tSales = $sum('total_sales');
        $tOrders = $sum('order_count');
        // Weighted by approved count so a seller with a single deal doesn't skew it
        $withDays = $sellers->filter(fn ($s) => $s['avg_days_to_approve'] !== null);
        $daysWeight = $withDays->sum('approved_count');
        $summary = [
            'seller_count' => $sellers->count(),
            'quote_count' => $tQuotes,
            'quote_value' => round($sum('quote_value'), 2),
            'approved_count' => $tApproved,
            'approved_value' => round($sum('approved_value'), 2),
            'rejected_count' => $sum('rejected_count'),
            'cancelled_count' => $sum('cancelled_count'),
            'expired_count' => $sum('expired_count'),
            'stale_count' => $sum('stale_count'),
            'lost_value' => round($sum('lost_value'), 2),
            'pipeline_value' => round($sum('pipeline_value'), 2),
            'conversion_rate' => $pct($tApproved, $tQuotes),
            'win_rate' => $pct($tApproved, $tDecided),
            'cancel_rate' => $pct($sum('cancelled_count'), $tQuotes),
            'reject_rate' => $pct($sum('rejected_count'), $tQuotes),
            'expired_rate' => $pct($sum('expired_count'), $tQuotes),
            'stale_rate' => $pct($sum('stale_count'), $tQuotes),
            'value_win_rate' => $pct($sum('approved_value'), $sum('quote_value')),
            'order_count' => $tOrders,
            'total_sales' => round($tSales, 2),
            'avg_per_order' => $tOrders > 0 ? round($tSales / $tOrders, 2) : 0,
            'paid_amount' => round($sum('paid_amount'), 2),
            'remaining_amount' => round($sum('remaining_amount'), 2),
            'collection_rate' => $pct($sum('paid_amount'), $tSales),
            'cancelled_order_count' => $sum('cancelled_order_count'),
            'new_customers' => $sum('new_customers'),
            'avg_days_to_approve' => $daysWeight > 0
                ? round($withDays->sum(fn ($s) => $s['avg_days_to_approve'] * $s['approved_count']) / $daysWeight, 1)
                : null,
        ];

        // ── Monthly trend per seller ──
        $quoteTrend = Quotation::query()
            ->whereNotNull('quotations.created_by')
            ->whereDate('quotations.created_at', '>=', $from)
            ->whereDate('quotations.created_at', '<=', $to)
            ->when($restricted, fn ($q) => $q->where('quotations.created_by', $ownerId))
            ->select(
                'quotations.created_by as seller_id',
                DB::raw("DATE_FORMAT(quotations.created_at, '%Y-%m') as month"),
                DB::raw('COUNT(*) as quote_count'),
                DB::raw("SUM(quotations.status = 'approved') as approved_count"),
                DB::raw("SUM(quotations.status = 'cancelled') as cancelled_count")
            )
            ->groupBy('seller_id', 'month')
            ->get();

        $salesTrend = Order::query()
            ->leftJoin('quotations', 'orders.quotation_id', '=', 'quotations.id')
            ->where('orders.status', '!=', 'cancelled')
            ->whereDate('orders.created_at', '>=', $from)
            ->whereDate('orders.created_at', '<=', $to)
            ->whereRaw("{$orderSeller} IS NOT NULL")
            ->when($restricted, fn ($q) => $q->whereRaw("{$orderSeller} = ?", [$ownerId]))
            ->select(
                DB::raw("{$orderSeller} as seller_id"),
                DB::raw("DATE_FORMAT(orders.created_at, '%Y-%m') as month"),
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(orders.total) as total')
            )
            ->groupBy('seller_id', 'month')
            ->get();

        $trend = [];
        $blank = fn ($sellerId, $month) => [
            'seller_id' => (int) $sellerId,
            'seller_name' => $names[$sellerId] ?? ('#' . $sellerId),
            'month' => $month,
            'quote_count' => 0,
            'approved_count' => 0,
            'cancelled_count' => 0,
            'order_count' => 0,
            'total' => 0.0,
        ];
        foreach ($quoteTrend as $r) {
            $k = $r->seller_id . '|' . $r->month;
            $trend[$k] = array_merge($blank($r->seller_id, $r->month), [
                'quote_count' => (int) $r->quote_count,
                'approved_count' => (int) $r->approved_count,
                'cancelled_count' => (int) $r->cancelled_count,
            ]);
        }
        foreach ($salesTrend as $r) {
            $k = $r->seller_id . '|' . $r->month;
            $trend[$k] ??= $blank($r->seller_id, $r->month);
            $trend[$k]['order_count'] = (int) $r->order_count;
            $trend[$k]['total'] = round((float) $r->total, 2);
        }

        return response()->json([
            'restricted' => $restricted,
            'sellers' => $sellers,
            'summary' => $summary,
            'trend' => collect($trend)->sortBy('month')->values(),
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Inactive customers report — grouped by customer level
     */
    public function inactiveCustomers(Request $request): JsonResponse
    {
        $levels = CustomerLevel::where('is_active', true)->orderBy('sort_order')->get();

        $result = [];
        foreach ($levels as $level) {
            $thresholdDate = now()->subDays($level->inactive_days);

            $customers = Customer::where('customer_level_id', $level->id)
                ->where(function ($q) use ($thresholdDate) {
                    $q->where('last_activity_at', '<', $thresholdDate)
                      ->orWhereNull('last_activity_at');
                })
                ->select('id', 'code', 'name', 'phone', 'last_activity_at', 'customer_level_id')
                ->orderBy('last_activity_at')
                ->get()
                ->map(function ($c) {
                    $c->inactive_days = $c->last_activity_at
                        ? (int) now()->diffInDays($c->last_activity_at)
                        : null;
                    // Last order info
                    $lastOrder = Order::where('customer_id', $c->id)
                        ->where('status', '!=', 'cancelled')
                        ->orderByDesc('created_at')
                        ->first(['order_number', 'total', 'created_at']);
                    $c->last_order = $lastOrder;
                    return $c;
                });

            $result[] = [
                'level' => $level,
                'threshold_days' => $level->inactive_days,
                'inactive_count' => $customers->count(),
                'customers' => $customers,
            ];
        }

        // Also include "at risk" — customers approaching threshold (80%+)
        $atRisk = [];
        foreach ($levels as $level) {
            $warningDate = now()->subDays((int) ($level->inactive_days * 0.8));
            $thresholdDate = now()->subDays($level->inactive_days);

            $customers = Customer::where('customer_level_id', $level->id)
                ->whereBetween('last_activity_at', [$thresholdDate, $warningDate])
                ->select('id', 'code', 'name', 'phone', 'last_activity_at', 'customer_level_id')
                ->orderBy('last_activity_at')
                ->get()
                ->map(function ($c) use ($level) {
                    $c->inactive_days = $c->last_activity_at
                        ? (int) now()->diffInDays($c->last_activity_at)
                        : null;
                    $c->remaining_days = $level->inactive_days - ($c->inactive_days ?? 0);
                    return $c;
                });

            if ($customers->count() > 0) {
                $atRisk[] = [
                    'level' => $level,
                    'customers' => $customers,
                ];
            }
        }

        return response()->json([
            'inactive' => $result,
            'at_risk' => $atRisk,
        ]);
    }

    /**
     * AR Aging — receivable aging report
     */
    public function arAging(Request $request): JsonResponse
    {
        $orders = Order::where('status', '!=', 'cancelled')
            ->where('remaining_amount', '>', 0)
            ->with(['customer:id,name,code', 'creator:id,name'])
            ->orderBy('created_at')
            ->get();

        $buckets = [
            '0-30' => ['label' => '0-30 วัน', 'total' => 0, 'count' => 0],
            '31-60' => ['label' => '31-60 วัน', 'total' => 0, 'count' => 0],
            '61-90' => ['label' => '61-90 วัน', 'total' => 0, 'count' => 0],
            '90+' => ['label' => 'มากกว่า 90 วัน', 'total' => 0, 'count' => 0],
        ];

        $items = $orders->map(function ($order) use (&$buckets) {
            $days = (int) now()->diffInDays($order->created_at);
            $bucket = match (true) {
                $days <= 30 => '0-30',
                $days <= 60 => '31-60',
                $days <= 90 => '61-90',
                default => '90+',
            };
            $buckets[$bucket]['total'] += (float) $order->remaining_amount;
            $buckets[$bucket]['count']++;

            return [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'customer' => $order->customer,
                'creator' => $order->creator,
                'total' => $order->total,
                'paid' => $order->paid_amount,
                'remaining' => $order->remaining_amount,
                'days' => $days,
                'bucket' => $bucket,
                'created_at' => $order->created_at,
            ];
        });

        $totalReceivable = $orders->sum('remaining_amount');

        return response()->json([
            'buckets' => $buckets,
            'total_receivable' => round($totalReceivable, 2),
            'items' => $items,
        ]);
    }

    /**
     * Monthly sales summary
     */
    public function monthlySales(Request $request): JsonResponse
    {
        $year = $request->input('year', now()->year);

        $monthly = Order::where('status', '!=', 'cancelled')
            ->whereYear('created_at', $year)
            ->select(
                DB::raw("MONTH(created_at) as month"),
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(total) as total_sales'),
                DB::raw('SUM(paid_amount) as total_paid'),
                DB::raw('SUM(remaining_amount) as total_remaining'),
                DB::raw('COUNT(DISTINCT customer_id) as customer_count')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // Previous year for comparison
        $prevYear = Order::where('status', '!=', 'cancelled')
            ->whereYear('created_at', $year - 1)
            ->select(
                DB::raw("MONTH(created_at) as month"),
                DB::raw('SUM(total) as total_sales')
            )
            ->groupBy('month')
            ->pluck('total_sales', 'month');

        $yearTotal = Order::where('status', '!=', 'cancelled')
            ->whereYear('created_at', $year)
            ->sum('total');

        return response()->json([
            'year' => $year,
            'monthly' => $monthly,
            'prev_year' => $prevYear,
            'year_total' => round($yearTotal, 2),
        ]);
    }

    /**
     * Orders in a month (or whole year) — drill-down for monthly sales
     */
    public function monthlySalesOrders(Request $request): JsonResponse
    {
        $request->validate([
            'year' => 'required|integer',
            'month' => 'nullable|integer|between:1,12',
        ]);

        $orders = Order::where('status', '!=', 'cancelled')
            ->whereYear('created_at', $request->input('year'))
            ->when($request->filled('month'), fn ($q) => $q->whereMonth('created_at', $request->input('month')))
            ->with(['customer:id,name,code', 'creator:id,name'])
            ->orderBy('created_at')
            ->get(['id', 'order_number', 'customer_id', 'status', 'delivery_status', 'total', 'paid_amount', 'remaining_amount', 'created_by', 'created_at']);

        return response()->json(['orders' => $orders]);
    }

    /**
     * Invoice report
     */
    public function invoiceReport(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->endOfMonth()->toDateString());

        $invoices = Invoice::with(['order:id,order_number,customer_id', 'order.customer:id,name,code', 'creator:id,name'])
            ->whereDate('issue_date', '>=', $from)
            ->whereDate('issue_date', '<=', $to)
            ->orderBy('issue_date')
            ->get();

        $summary = [
            'total_issued' => $invoices->where('status', 'issued')->count(),
            'total_cancelled' => $invoices->where('status', 'cancelled')->count(),
            'total_amount' => round($invoices->where('status', 'issued')->sum('total'), 2),
            'cancelled_amount' => round($invoices->where('status', 'cancelled')->sum('total'), 2),
        ];

        // Monthly breakdown
        $byMonth = $invoices->where('status', 'issued')
            ->groupBy(fn ($inv) => date('Y-m', strtotime($inv->issue_date)))
            ->map(fn ($group) => [
                'count' => $group->count(),
                'total' => round($group->sum('total'), 2),
            ]);

        return response()->json([
            'invoices' => $invoices,
            'summary' => $summary,
            'by_month' => $byMonth,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Sales by customer report
     */
    public function salesByCustomer(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->endOfMonth()->toDateString());

        $customers = Order::where('status', '!=', 'cancelled')
            ->whereDate('orders.created_at', '>=', $from)
            ->whereDate('orders.created_at', '<=', $to)
            ->join('customers', 'orders.customer_id', '=', 'customers.id')
            ->leftJoin('customer_levels', 'customers.customer_level_id', '=', 'customer_levels.id')
            ->select(
                'customers.id',
                'customers.name',
                'customers.code',
                'customer_levels.name as level_name',
                'customer_levels.color as level_color',
                DB::raw('COUNT(orders.id) as order_count'),
                DB::raw('SUM(orders.total) as total_sales'),
                DB::raw('SUM(orders.paid_amount) as total_paid'),
                DB::raw('SUM(orders.remaining_amount) as total_remaining')
            )
            ->selectSub(
                Delivery::whereColumn('deliveries.customer_id', 'customers.id')
                    ->where('status', '!=', 'cancelled')
                    ->whereDate('created_at', '>=', $from)
                    ->whereDate('created_at', '<=', $to)
                    ->selectRaw('COUNT(*)'),
                'delivery_count'
            )
            ->groupBy('customers.id', 'customers.name', 'customers.code', 'level_name', 'level_color')
            ->orderByDesc('total_sales')
            ->get();

        return response()->json([
            'customers' => $customers,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Sales by product report
     */
    public function salesByProduct(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->endOfMonth()->toDateString());

        $products = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.status', '!=', 'cancelled')
            ->whereDate('orders.created_at', '>=', $from)
            ->whereDate('orders.created_at', '<=', $to)
            ->select(
                'order_items.product_id',
                DB::raw('COALESCE(products.name, order_items.description) as product_name'),
                DB::raw('COALESCE(products.code, "-") as product_code'),
                DB::raw('SUM(order_items.quantity) as total_qty'),
                DB::raw('SUM(order_items.amount) as total_amount'),
                DB::raw('COUNT(DISTINCT orders.id) as order_count')
            )
            ->groupBy('order_items.product_id', 'product_name', 'product_code')
            ->orderByDesc('total_amount')
            ->get();

        return response()->json([
            'products' => $products,
            'from' => $from,
            'to' => $to,
        ]);
    }
}
