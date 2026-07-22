<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerLevel;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
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

        $sellers = Order::where('status', '!=', 'cancelled')
            ->whereDate('orders.created_at', '>=', $from)
            ->whereDate('orders.created_at', '<=', $to)
            ->join('users', 'orders.created_by', '=', 'users.id')
            ->select(
                'users.id',
                'users.name',
                DB::raw('COUNT(orders.id) as order_count'),
                DB::raw('SUM(orders.total) as total_sales'),
                DB::raw('AVG(orders.total) as avg_per_order'),
                DB::raw('COUNT(DISTINCT orders.customer_id) as customer_count')
            )
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_sales')
            ->get();

        // Monthly trend per seller
        $trend = Order::where('status', '!=', 'cancelled')
            ->whereDate('orders.created_at', '>=', $from)
            ->whereDate('orders.created_at', '<=', $to)
            ->join('users', 'orders.created_by', '=', 'users.id')
            ->select(
                'users.id as seller_id',
                'users.name as seller_name',
                DB::raw("DATE_FORMAT(orders.created_at, '%Y-%m') as month"),
                DB::raw('SUM(orders.total) as total')
            )
            ->groupBy('seller_id', 'seller_name', 'month')
            ->orderBy('month')
            ->get();

        return response()->json([
            'sellers' => $sellers,
            'trend' => $trend,
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
