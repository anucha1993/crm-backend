<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Mpdf\Mpdf;

// Mock invoice with 2 items (matching user's test data)
$item1 = new \stdClass();
$item1->id = 1;
$item1->quantity = 100.00;
$item1->unit = 'แผ่น';
$item1->description = 'แผ่นพื้นสำเร็จรูป';
$item1->unit_price = 77.00;
$item1->length = 1.00;
$item1->thickness = 0.35;
$item1->amount = 7700.00;
$productDummy = new \stdClass();
$productDummy->name = 'แผ่นพื้นสำเร็จรูป';
$productDummy->unit = 'แผ่น';
$productDummy->thickness_unit = null;
$productDummy->steel_type = null;
$productDummy->sizes = collect([(object)['length_unit' => 'เมตร']]);
$item1->product = $productDummy;

$item2 = clone $item1;
$item2->id = 2;
$item2->quantity = 150.00;
$item2->unit_price = 154.00;
$item2->length = 2.00;
$item2->amount = 23100.00;

$customer = new \stdClass();
$customer->name = 'บริษัท เมทาเภท เซอวิส จำกัด';
$customer->address = '38/8 ตำบลบ้านป้อม อำเภอพระนครศรีอยุธยา จังหวัดพระนครศรีอยุธยา 13000';
$customer->tax_id = '0145568007119';
$customer->phone = null;

$creator = new \stdClass();
$creator->name = 'Admin';

$invoice = new \stdClass();
$invoice->invoice_number = 'IV6906000061';
$invoice->issue_date = \Carbon\Carbon::parse('2026-06-29');
$invoice->customer = $customer;
$invoice->shippingAddress = null;
$invoice->items = collect([$item1, $item2]);
$invoice->creator = $creator;
$invoice->subtotal = 30800.00;
$invoice->discount_amount = 0;
$invoice->vat_amount = 2156.00;
$invoice->total = 32956.00;
$invoice->notes = '';
$invoice->status = 'issued';
$invoice->account_type = 'tax';
$invoice->order = null;

$company = [
    'name' => 'บริษัท เจริญมั่น คอนกรีต จำกัด',
    'branch' => 'สำนักงานใหญ่',
    'address' => '99/35 หมู่ 9 ตำบลละหาร อำเภอบางบัวทอง จังหวัดนนทบุรี 11110',
    'phone' => '082-478-9197',
    'tax_id' => '0125560015546',
    'logo' => null,
];
$isVat = true;
$logoPath = null;

$buddhistYear = (int) $invoice->issue_date->format('Y') + 543;
$issueDate = $invoice->issue_date->format('d/m/') . $buddhistYear;
$bahtText = 'สามหมื่นสองพันเก้าร้อยห้าสิบหกบาทถ้วน';
$qrData = $invoice->invoice_number;

$html = view('invoices.pdf', compact(
    'invoice', 'company', 'isVat', 'logoPath', 'issueDate', 'bahtText', 'qrData'
))->render();

$mpdf = new Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'default_font' => 'garuda',
    'margin_top' => 5,
    'margin_bottom' => 8,
    'margin_left' => 8,
    'margin_right' => 8,
    'autoLangToFont' => true,
    'autoScriptToLang' => true,
    'tempDir' => storage_path('app/mpdf-temp'),
]);
$mpdf->WriteHTML($html);
$mpdf->Output(__DIR__.'/storage/test-invoice.pdf', 'F');
echo "PDF written: " . __DIR__.'/storage/test-invoice.pdf' . PHP_EOL;
echo "Size: " . filesize(__DIR__.'/storage/test-invoice.pdf') . " bytes" . PHP_EOL;
