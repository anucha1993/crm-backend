<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<style>
    body {
        font-family: garuda, sans-serif;
        font-size: 11pt;
        color: #000;
        line-height: 1.35;
    }
    p, h3, h4, h5, h6, td, th, div, span, b, strong, small, address {
        margin: 0 !important;
        padding: 0 !important;
        line-height: 1.35 !important;
    }
    address { font-style: normal; }
    .table {
        border-collapse: collapse;
        width: 100%;
    }
    .table th, .table td {
        border: 1px solid #dee2e6 !important;
        padding: 6px 8px !important;
        font-size: 9pt;
        vertical-align: middle;
    }
    .table th {
        text-align: center;
        font-weight: bold;
        padding: 8px 6px !important;
        background-color: #f8f9fa;
    }
    .text-center { text-align: center !important; }
    .text-end { text-align: right !important; }
    .text-muted { color: #6c757d; }
    .fs-13 { font-size: 13pt; }
    .fs-12 { font-size: 12pt; }
    .fs-11 { font-size: 11pt; }
    .fs-10 { font-size: 10pt; }
    .fs-9 { font-size: 9pt; }
    hr { border: none; border-top: 1px solid #000; margin: 8px 0 !important; }
</style>
</head>
<body>

@php
    $perPage = 15;
    $lastPageMax = 8;
    $items = $invoice->items;
    $total = $items->count();
    if ($total <= $perPage) {
        $chunks = collect([$items]);
    } else {
        // Reserve last page for totals
        $lastCount = min($lastPageMax, $total);
        $remaining = $total - $lastCount;
        $priorPages = (int) ceil($remaining / $perPage);
        // Distribute remaining evenly across prior pages
        $base = intdiv($remaining, $priorPages);
        $extra = $remaining % $priorPages;
        $chunks = collect();
        $offset = 0;
        for ($i = 0; $i < $priorPages; $i++) {
            $size = $base + ($i < $extra ? 1 : 0);
            $chunks->push($items->slice($offset, $size)->values());
            $offset += $size;
        }
        $chunks->push($items->slice($offset, $lastCount)->values());
    }
    $totalPages = max(1, $chunks->count());
    $title = $isVat ? 'ใบกำกับภาษี / Tax Invoice' : 'ใบเสร็จรับเงิน / Receipt';
    $loopIndex = 1;
@endphp

@foreach($chunks as $chunkIndex => $chunk)

    {{-- ===== Header: Logo + Title | QR + Page ===== --}}
    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td valign="top" width="60%">
                @if($logoPath)
                    <img src="{{ $logoPath }}" height="60" />
                @endif
                <h3 class="fs-13" style="margin-top: 8px !important;"><b>{{ $title }}</b></h3>
            </td>
            <td valign="top" width="40%" style="text-align: right;">
             
                <barcode code="{{ $qrData }}" type="QR" size="0.9" error="L" />
                <table width="100%" cellpadding="0" cellspacing="0"><tr><td >&nbsp;</td>
                </tr><tr><td class="fs-9" style="text-align:right;">หน้า {{ $chunkIndex + 1 }}/{{ $totalPages }}</td></tr></table>
            </td>
        </tr>
    </table>

    {{-- ===== Company + Document Meta ===== --}}
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 10px !important;">
        <tr>
            <td valign="top" width="60%">
                <div class="fs-11"><b>{{ $company['name'] ?? 'บริษัท' }}@if(!empty($company['branch'])) ({{ $company['branch'] }})@endif</b></div>
                <div class="fs-10" style="margin-top: 4px !important;">
                    @if(!empty($company['address']))ที่อยู่ {{ $company['address'] }}@endif
                    @if(!empty($company['phone'])) โทร {{ $company['phone'] }}@endif
                </div>
                @if(!empty($company['tax_id']))
                    <div class="fs-10">เลขประจำตัวผู้เสียภาษี {{ $company['tax_id'] }}</div>
                @endif
            </td>
            <td valign="top" width="40%" style="text-align: right;">
                <div class="fs-10"><b>วันที่: </b>{{ $issueDate }}</div>
                <div class="fs-10"><b>เลขที่: </b>{{ $invoice->invoice_number }}</div>
                @if($invoice->creator)
                    <div class="fs-10"><b>ชื่อผู้ขาย (Sale): </b>{{ $invoice->creator->name }}</div>
                @endif
            </td>
        </tr>
    </table>

    <hr>

    {{-- ===== Customer + Shipping ===== --}}
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 8px !important;">
        <tr>
            <td valign="top" width="50%">
                <h4 class="fs-12"><b>ข้อมูลลูกค้า</b></h4>
                <address class="fs-10" style="margin-top: 4px !important;">
                    {{ $invoice->customer->name ?? '-' }}<br>
                    @if($invoice->customer?->address){{ $invoice->customer->address }}@endif
                    @if($invoice->customer?->phone)<br>โทร {{ $invoice->customer->phone }}@endif
                    @if($invoice->customer?->tax_id && $isVat)<br>เลขประจำตัวผู้เสียภาษี {{ $invoice->customer->tax_id }}@endif
                </address>
            </td>
            <td valign="top" width="50%">
                <h4 class="fs-12"><b>ที่อยู่จัดส่ง</b></h4>
                <address class="fs-10" style="margin-top: 4px !important;">
                    @if($invoice->shippingAddress)
                        @if($invoice->shippingAddress->contact_name || $invoice->shippingAddress->phone)
                            {{ $invoice->shippingAddress->contact_name ?? '' }}@if($invoice->shippingAddress->phone) ({{ $invoice->shippingAddress->phone }})@endif<br>
                        @endif
                        {{ $invoice->shippingAddress->address }}
                    @else
                        {{ $invoice->customer->name ?? '' }}<br>
                        @if($invoice->customer?->address){{ $invoice->customer->address }}<br>@endif
                        @if($invoice->customer?->phone)(+66) {{ $invoice->customer->phone }}@endif
                    @endif
                </address>
            </td>
        </tr>
    </table>

    {{-- ===== Items Table ===== --}}
    <br>
    <table class="table" style="margin-top: 13px !important; font-size: 10pt;">
        <thead>
            <tr>
                <th width="30">ลำดับ</th>
                <th width="50">จำนวน</th>
                <th width="65">หน่วยนับ</th>
                <th>รายการสินค้า</th>
                <th width="75">ความยาว</th>
                <th width="95">ราคาต่อหน่วย</th>
                <th width="100" class="text-end">จำนวนเงินรวม</th>
            </tr>
        </thead>
        <tbody>
            @foreach($chunk as $item)
                @php
                    $rawUnit = trim((string)($item->unit ?? ''));
                    $productUnit = trim((string)($item->product->unit ?? ''));
                    $isSheet = in_array($rawUnit, ['แผ่น', 'ตรม.', 'ตรม']) || $productUnit === 'แผ่น';
                    $displayUnit = $isSheet ? 'ตรม.' : $rawUnit;
                    $lengthUnitRaw = $item->product?->sizes?->first()?->length_unit ?? '';
                    $displayLengthUnit = $isSheet ? 'ตรม.' : $lengthUnitRaw;
                @endphp
                <tr>
                    <td class="text-center">{{ $loopIndex++ }}</td>
                    <td class="text-center">{{ number_format((float)$item->quantity) }}</td>
                    <td class="text-center">{{ $displayUnit }}</td>
                    <td>
                        <b>{{ $item->product->name ?? $item->description }}</b>
                        ({{ number_format((float)$item->unit_price, 2) }}/{{ $displayLengthUnit ?: $displayUnit }})
                        @if($item->description && $item->product)<br><span class="fs-9">{{ $item->description }}</span>@endif
                    </td>
                    <td class="text-center">{{ $item->length ? number_format((float)$item->length, 2) . ' ' . $displayLengthUnit : '-' }}</td>
                    <td class="text-center">{{ number_format((float)$item->unit_price * (float)($item->length ?: 1), 2) }}/{{ $displayUnit }}</td>
                    <td class="text-end">{{ number_format((float)$item->amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ===== Footer: Notes + Totals (LAST page only) ===== --}}
    @if($chunkIndex === $totalPages - 1)
        <br>
        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td valign="top" width="55%">
                    <h6 class="fs-10 text-muted">หมายเหตุ:</h6>
                    <small class="fs-10">{{ $invoice->notes }}</small>
                </td>
                <td valign="top" width="45%">
                    <table width="100%" cellpadding="0" cellspacing="0">
                        <tr>
                            <td class="fs-10"><b>จำนวนเงินรวม :</b></td>
                            <td class="fs-10 text-end">{{ number_format((float)$invoice->subtotal, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="fs-10"><b>ส่วนลด:</b></td>
                            <td class="fs-10 text-end">{{ number_format((float)$invoice->discount_amount, 2) }}</td>
                        </tr>
                        @if($isVat)
                            <tr>
                                <td class="fs-10"><b>ภาษีมูลค่าเพิ่ม:</b></td>
                                <td class="fs-10 text-end">{{ number_format((float)$invoice->vat_amount, 2) }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td class="fs-11"><b>จำนวนเงินทั้งสิ้น: &nbsp;</b></td>
                            <td class="fs-11 text-end"><b>{{ number_format((float)$invoice->total, 2) }}</b></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <hr>

        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td valign="top" width="55%">
                    <span class="fs-10">หมายเหตุ:เงื่อนไขการชำระเงิน</span><br>
                    <span class="fs-10">1. โอนก่อนจัดส่งสินค้า</span><br>
                    <span class="fs-10">2. ชำระเป็นเงินสด เมื่อตรวจรับสินค้าเรียบร้อย</span>
                </td>
                <td valign="top" width="45%" style="text-align: center;">
                    <div style="height: 4mm;">&nbsp;</div>
                    <span class="fs-10">ผู้รับเงิน</span><br>
                    @if($invoice->creator)
                        <span class="fs-10">{{ $invoice->creator->name }}</span>
                    @endif
                </td>
            </tr>
        </table>
    @endif

    @if($chunkIndex < $totalPages - 1)
        <pagebreak />
    @endif
@endforeach

</body>
</html>
