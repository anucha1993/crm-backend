<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class MockQuotationInvoiceSeeder extends Seeder
{
    /**
     * Seed: 1 Quotation + 1 Invoice ที่มี 30 รายการ
     */
    public function run(): void
    {
        $user = User::query()->first();
        $userId = $user?->id;

        // === Customer ===
        $customer = Customer::query()->first();
        if (!$customer) {
            $customer = Customer::create([
                'code' => Customer::generateCode(),
                'type' => 'general',
                'name' => 'บริษัท ทดสอบ Mockup จำกัด',
                'tax_id' => '0105526020737',
                'contact_name' => 'คุณสมชาย ทดสอบ',
                'phone' => '089-814-119',
                'address' => '29 หมู่ 5 ต.อ่างศิลา อ.เมืองชลบุรี จ.ชลบุรี 20000',
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        }

        $address = $customer->addresses()->first();
        if (!$address) {
            $address = CustomerAddress::create([
                'customer_id' => $customer->id,
                'label' => 'ที่อยู่จัดส่งหลัก',
                'contact_name' => $customer->contact_name ?? 'ผู้รับ',
                'phone' => $customer->phone ?? '',
                'address' => 'นครปฐม',
                'is_default' => true,
            ]);
        }

        // === Products (ensure อย่างน้อย 30 รายการ) ===
        $products = Product::query()->limit(30)->get();
        if ($products->count() < 30) {
            $units = ['ต้น', 'เส้น', 'แผ่น', 'ท่อน'];
            $lengthUnits = ['เมตร', 'เมตร', 'ตรม.', 'เมตร'];
            for ($i = $products->count(); $i < 30; $i++) {
                $idx = $i % count($units);
                $code = 'MOCK-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
                $products->push(Product::create([
                    'code' => $code,
                    'name' => 'สินค้าทดสอบ #' . ($i + 1),
                    'unit' => $units[$idx],
                    'length' => 3 + ($i % 5),
                    'length_unit' => $lengthUnits[$idx],
                    'thickness' => 0.5 + ($i % 3) * 0.5,
                    'thickness_unit' => 'มม.',
                    'side_steel' => 'unspecified',
                    'size_type' => 'standard',
                    'selling_price' => 100 + $i * 10,
                ]));
            }
            $products = $products->take(30)->values();
        }

        // === Quotation ===
        $quotationItems = [];
        $subtotal = 0;
        foreach ($products as $i => $product) {
            $quantity = 5 + ($i % 10);
            $length = 3 + ($i % 5);
            $unitPrice = 50 + $i * 5;
            $amount = round($quantity * $length * $unitPrice, 2);
            $subtotal += $amount;

            $quotationItems[] = [
                'product_id' => $product->id,
                'description' => $product->name,
                'thickness' => $product->thickness,
                'length' => $length,
                'quantity' => $quantity,
                'unit' => $product->unit ?? 'ชิ้น',
                'unit_price' => $unitPrice,
                'amount' => $amount,
                'sort_order' => $i + 1,
            ];
        }

        $vatRate = 7;
        $vatAmount = round($subtotal * $vatRate / 100, 2);
        $total = round($subtotal + $vatAmount, 2);

        $quotation = Quotation::create([
            'account_type' => 'tax',
            'quotation_number' => Quotation::generateNumber(),
            'customer_id' => $customer->id,
            'customer_address_id' => $address->id,
            'status' => 'sent',
            'revision_number' => 0,
            'notes' => 'Mockup ข้อมูลทดสอบ 30 รายการ',
            'subtotal' => $subtotal,
            'discount_type' => 'amount',
            'discount_value' => 0,
            'discount_amount' => 0,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'total' => $total,
            'valid_until' => Carbon::now()->addDays(30)->toDateString(),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        foreach ($quotationItems as $item) {
            QuotationItem::create(array_merge($item, [
                'quotation_id' => $quotation->id,
            ]));
        }

        // === Order (จำเป็นเพราะ invoice มี order_id required) ===
        $order = Order::create([
            'account_type' => 'tax',
            'order_number' => 'ORD-' . date('Ym') . '-' . str_pad((string) (Order::withoutGlobalScope('account')->count() + 1), 4, '0', STR_PAD_LEFT),
            'quotation_id' => $quotation->id,
            'customer_id' => $customer->id,
            'customer_address_id' => $address->id,
            'status' => 'confirmed',
            'notes' => 'Mockup order',
            'subtotal' => $subtotal,
            'discount_type' => 'amount',
            'discount_value' => 0,
            'discount_amount' => 0,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'total' => $total,
            'paid_amount' => 0,
            'remaining_amount' => $total,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        foreach ($quotationItems as $item) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $item['product_id'],
                'description' => $item['description'],
                'thickness' => $item['thickness'],
                'length' => $item['length'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'unit_price' => $item['unit_price'],
                'amount' => $item['amount'],
                'sort_order' => $item['sort_order'],
            ]);
        }

        // === Invoice ===
        $invoice = Invoice::create([
            'account_type' => 'tax',
            'invoice_number' => Invoice::generateNumber(),
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'customer_address_id' => $address->id,
            'status' => 'issued',
            'issue_date' => Carbon::now()->toDateString(),
            'subtotal' => $subtotal,
            'discount_type' => 'amount',
            'discount_value' => 0,
            'discount_amount' => 0,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'total' => $total,
            'notes' => 'Mockup ใบกำกับภาษี 30 รายการ',
            'created_by' => $userId,
        ]);

        foreach ($quotationItems as $item) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'product_id' => $item['product_id'],
                'description' => $item['description'],
                'thickness' => $item['thickness'],
                'length' => $item['length'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'unit_price' => $item['unit_price'],
                'amount' => $item['amount'],
                'sort_order' => $item['sort_order'],
            ]);
        }

        $this->command?->info('Mockup created: Quotation ' . $quotation->quotation_number . ', Invoice ' . $invoice->invoice_number);
    }
}
