<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Customer::with(['level', 'creator', 'updater', 'addresses']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('contact_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('tax_id', 'like', "%{$search}%");
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('customer_level_id')) {
            $query->where('customer_level_id', $request->customer_level_id);
        }

        $customers = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 10));

        return response()->json($customers);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:customers,name',
            'type' => 'nullable|in:regular,general',
            'customer_level_id' => 'nullable|exists:customer_levels,id',
            'tax_id' => 'nullable|string|max:20|unique:customers,tax_id',
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'shipping_addresses' => 'nullable|array',
            'shipping_addresses.*.label' => 'nullable|string|max:255',
            'shipping_addresses.*.contact_name' => 'nullable|string|max:255',
            'shipping_addresses.*.phone' => 'nullable|string|max:50',
            'shipping_addresses.*.address' => 'nullable|string',
            'shipping_addresses.*.is_default' => 'nullable|boolean',
        ]);

        $customer = DB::transaction(function () use ($request) {
            $customer = Customer::create([
                ...$request->except('shipping_addresses'),
                'code' => Customer::generateCode(),
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
                'last_activity_at' => now(),
            ]);

            if ($request->filled('shipping_addresses')) {
                foreach ($request->shipping_addresses as $addr) {
                    $customer->addresses()->create($addr);
                }
            }

            return $customer;
        });

        $customer->load(['level', 'creator', 'updater', 'addresses']);

        return response()->json(['customer' => $customer], 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $customer->load(['level', 'creator', 'updater', 'addresses']);

        return response()->json(['customer' => $customer]);
    }

    /**
     * Full document history for a customer: quotations -> orders -> deliveries
     * -> invoices -> payments. Not account-scoped, so it spans cash + tax.
     */
    public function history(Customer $customer): JsonResponse
    {
        $customer->load(['level', 'creator']);

        $quotations = $customer->quotations()
            ->orderByDesc('created_at')
            ->get(['id', 'account_type', 'quotation_number', 'status', 'total', 'created_at']);

        $orders = $customer->orders()
            ->orderByDesc('created_at')
            ->get(['id', 'account_type', 'order_number', 'quotation_id', 'status', 'delivery_status', 'total', 'paid_amount', 'remaining_amount', 'created_at']);

        $deliveries = $customer->deliveries()
            ->orderByDesc('created_at')
            ->get(['id', 'account_type', 'delivery_number', 'order_id', 'status', 'delivery_date', 'created_at']);

        $invoices = $customer->invoices()
            ->orderByDesc('created_at')
            ->get(['id', 'account_type', 'invoice_number', 'order_id', 'status', 'total', 'issue_date', 'created_at']);

        $payments = $customer->payments()
            ->orderByDesc('created_at')
            ->get(['id', 'account_type', 'payment_number', 'order_id', 'method', 'status', 'amount', 'created_at']);

        $activeOrders = $orders->where('status', '!=', 'cancelled');

        return response()->json([
            'customer' => $customer,
            'summary' => [
                'quotation_count' => $quotations->count(),
                'order_count' => $orders->count(),
                'delivery_count' => $deliveries->count(),
                'invoice_count' => $invoices->count(),
                'payment_count' => $payments->count(),
                'total_sales' => (float) $activeOrders->sum('total'),
                'total_paid' => (float) $activeOrders->sum('paid_amount'),
                'total_remaining' => (float) $activeOrders->sum('remaining_amount'),
            ],
            'quotations' => $quotations,
            'orders' => $orders,
            'deliveries' => $deliveries,
            'invoices' => $invoices,
            'payments' => $payments,
        ]);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('customers', 'name')->ignore($customer->id)],
            'type' => 'sometimes|in:regular,general',
            'customer_level_id' => 'nullable|exists:customer_levels,id',
            'tax_id' => ['nullable', 'string', 'max:20', Rule::unique('customers', 'tax_id')->ignore($customer->id)],
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'shipping_addresses' => 'nullable|array',
            'shipping_addresses.*.id' => 'nullable|integer',
            'shipping_addresses.*.label' => 'nullable|string|max:255',
            'shipping_addresses.*.contact_name' => 'nullable|string|max:255',
            'shipping_addresses.*.phone' => 'nullable|string|max:50',
            'shipping_addresses.*.address' => 'nullable|string',
            'shipping_addresses.*.is_default' => 'nullable|boolean',
        ]);

        DB::transaction(function () use ($request, $customer) {
            $customer->update([
                ...$request->except('shipping_addresses'),
                'updated_by' => $request->user()->id,
                'last_activity_at' => now(),
            ]);

            if ($request->has('shipping_addresses')) {
                $keepIds = collect($request->shipping_addresses)
                    ->pluck('id')
                    ->filter()
                    ->toArray();

                $customer->addresses()->whereNotIn('id', $keepIds)->delete();

                foreach ($request->shipping_addresses as $addr) {
                    if (!empty($addr['id'])) {
                        $customer->addresses()->where('id', $addr['id'])->update($addr);
                    } else {
                        $customer->addresses()->create($addr);
                    }
                }
            }
        });

        $customer->load(['level', 'creator', 'updater', 'addresses']);

        return response()->json(['customer' => $customer]);
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $customer->delete();

        return response()->json(['message' => 'ลบลูกค้าสำเร็จ']);
    }

    public function nextCode(): JsonResponse
    {
        return response()->json(['code' => Customer::generateCode()]);
    }
}
