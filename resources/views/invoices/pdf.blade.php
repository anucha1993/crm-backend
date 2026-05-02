<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<style>
    body {
        font-family: sarabun, sans-serif;
        font-size: 10pt;
        color: #000;
        line-height: 1.5;
    }
    td, th {
        font-family: sarabun, sans-serif;
    }
    .bordered td, .bordered th {
        border: 1px solid #000;
    }
</style>
</head>
<body>

    {{-- ===== Document Title (centered) ===== --}}
    <div style="text-align: center; font-size: 14pt; font-weight: bold; margin-bottom: 8px;">
        {{ $isVat ? 'ใบเสร็จรับเงิน / ใบกำกับภาษี' : 'ใบเสร็จรับเงิน / บิลเงินสด' }}
    </div>

    {{-- ===== Company Info + Invoice Number ===== --}}
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 6px;">
        <tr>
            <td valign="top">
              
                <span style="font-size: 12pt; font-weight: bold;">{{ $company['name'] ?? 'บริษัท' }}</span>
                @if(!empty($company['branch']))
                    <span style="font-size: 10pt;"> ({{ $company['branch'] }})</span>
                @endif
                <br>
                @if(!empty($company['address']))
                    <span style="font-size: 9pt;">ที่อยู่: {{ $company['address'] }}</span><br>
                @endif
                @if(!empty($company['phone']))
                    <span style="font-size: 9pt;">โทร: {{ $company['phone'] }}</span><br>
                @endif
                <span style="font-size: 9pt;">

                    @if(!empty($company['tax_id'])) สาขา/Branch สำนักงานใหญ่ เลขประจำตัวผู้เสียภาษี/Tax ID: {{ $company['tax_id'] }}@endif
                </span>
            </td>
            <td width="180" valign="top" style="text-align: right;">
                <span style="font-size: 10pt;">เลขที่ {{ $invoice->invoice_number }}</span>
            </td>
        </tr>
    </table>
    <br>

    {{-- <hr style="border: none; border-top: 1px solid #000; margin: 4px 0 8px 0;"> --}}

    {{-- ===== Customer Info + Date ===== --}}
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 10px;">
        <tr>
            <td valign="top">
                <span style="font-size: 10pt;">ได้รับเงินจาก: <b>{{ $invoice->customer->name ?? '-' }}</b></span><br>
                @if($invoice->customer?->address)
                    <span style="font-size: 9pt;">ที่อยู่: {{ $invoice->customer->address }}</span><br>
                @endif
                @if($invoice->shippingAddress && $invoice->shippingAddress->address !== ($invoice->customer?->address ?? ''))
                    <span style="font-size: 9pt;">{{ $invoice->shippingAddress->address }}</span><br>
                @endif
                @if($invoice->customer?->tax_id && $isVat)
                    <span style="font-size: 9pt;">เลขประจำตัวผู้เสียภาษีอากร: {{ $invoice->customer->tax_id }}</span>
                @endif
            </td>
            <td width="160" valign="top" style="text-align: right;">
                <span style="font-size: 10pt;">วันที่ {{ $issueDate }}</span>
            </td>
        </tr>
    </table>

    {{-- ===== Items Table ===== --}}
    <table width="100%" cellpadding="4" cellspacing="0" style="border-collapse: collapse; margin-bottom: 0;">
        <thead>
            <tr>
                <th width="35" style="text-align: center; font-size: 9pt; padding: 5px; border: 1px solid #000;">ลำดับ</th>
                <th width="50" style="text-align: center; font-size: 9pt; padding: 5px; border: 1px solid #000;">จำนวน</th>
                <th width="50" style="text-align: center; font-size: 9pt; padding: 5px; border: 1px solid #000;">หน่วยนับ</th>
                <th style="text-align: center; font-size: 9pt; padding: 5px; border: 1px solid #000;">รายการสินค้า</th>
                <th width="110" style="text-align: center; font-size: 9pt; padding: 5px; border: 1px solid #000;">ราคาต่อหน่วย</th>
                <th width="95" style="text-align: center; font-size: 9pt; padding: 5px; border: 1px solid #000;">{{ $isVat ? 'จำนวนเงินไม่รวมภาษี' : 'จำนวนเงิน' }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $i => $item)
                <tr>
                    <td style="text-align: center; font-size: 9pt; border-left: 1px solid #000; border-right: 1px solid #000;">{{ $i + 1 }}</td>
                    <td style="text-align: right; font-size: 9pt; border-right: 1px solid #000; padding-right: 6px;">{{ number_format((float)$item->quantity, 2) }}</td>
                    <td style="text-align: center; font-size: 9pt; border-right: 1px solid #000;">{{ $item->unit }}</td>
                    <td style="font-size: 9pt; border-right: 1px solid #000; padding-left: 6px;">
                        @if($item->product)
                            {{ $item->product->name }}
                        @endif
                        @if($item->length)
                            &nbsp;&nbsp;&nbsp;ยาว {{ number_format((float)$item->length, 2) }} {{ $item->product?->sizes?->first()?->length_unit ?? 'เมตร' }}
                        @endif
                        {{-- @if($item->description)
                            <br><span style="font-size: 8pt; color: #333;">({{ $item->description }})</span>
                        @endif --}}
                    </td>
                    <td style="text-align: right; font-size: 9pt; border-right: 1px solid #000; padding-right: 6px;">{{ number_format((float)$item->unit_price, 2) }} /{{ $item->product?->sizes?->first()?->length_unit ?? $item->unit }}</td>
                    <td style="text-align: right; font-size: 9pt; border-right: 1px solid #000; padding-right: 6px;">{{ number_format((float)$item->amount, 2) }}</td>
                </tr>
            @endforeach
            {{-- Empty rows to fill table --}}
            @for($j = count($invoice->items); $j < 22; $j++)
                <tr>
                    <td style="border-left: 1px solid #000; border-right: 1px solid #000; height: 18px;">&nbsp;</td>
                    <td style="border-right: 1px solid #000;">&nbsp;</td>
                    <td style="border-right: 1px solid #000;">&nbsp;</td>
                    <td style="border-right: 1px solid #000;">&nbsp;</td>
                    <td style="border-right: 1px solid #000;">&nbsp;</td>
                    <td style="border-right: 1px solid #000;">&nbsp;</td>
                </tr>
            @endfor
            {{-- Bottom border + Summary rows --}}
            <tr>
                <td colspan="4" style="border-top: 1px solid #000; padding: 0;"></td>
                <td style="border: 1px solid #000; font-size: 9pt; padding: 4px 8px; text-align: right;">รวมเงิน</td>
                <td style="border: 1px solid #000; font-size: 9pt; text-align: right; padding: 4px 8px;">{{ number_format((float)$invoice->subtotal, 2) }}</td>
            </tr>
            <tr>
                <td colspan="4" style="padding: 0;"></td>
                <td style="border: 1px solid #000; font-size: 9pt; padding: 4px 8px; text-align: right;">ส่วนลด{{ $invoice->discount_type === 'percent' ? ' (' . (float)$invoice->discount_value . '%)' : '' }}</td>
                <td style="border: 1px solid #000; font-size: 9pt; text-align: right; padding: 4px 8px;">{{ (float)$invoice->discount_amount > 0 ? number_format((float)$invoice->discount_amount, 2) : '' }}</td>
            </tr>
            <tr>
                <td colspan="4" style="padding: 0;"></td>
                <td style="border: 1px solid #000; font-size: 9pt; padding: 4px 8px; text-align: right;">เงินหลังหักส่วนลด</td>
                <td style="border: 1px solid #000; font-size: 9pt; text-align: right; padding: 4px 8px;">{{ number_format((float)$invoice->subtotal - (float)$invoice->discount_amount, 2)}}</td>
            </tr>
            @if($isVat)
            <tr>
                <td colspan="4" style="padding: 0;"></td>
                <td style="border: 1px solid #000; font-size: 9pt; padding: 4px 8px; text-align: right;">ภาษีมูลค่าเพิ่ม {{ rtrim(rtrim(number_format((float)$invoice->vat_rate, 2), '0'), '.') }}%</td>
                <td style="border: 1px solid #000; font-size: 9pt; text-align: right; padding: 4px 8px;">{{ number_format((float)$invoice->vat_amount, 2) }}</td>
            </tr>
            @endif
            <tr>
                <td colspan="4" style="padding: 0;"></td>
                <td style="border: 1px solid #000; font-size: 10pt; font-weight: bold; padding: 4px 8px;">ยอดเงินสุทธิ</td>
                <td style="border: 1px solid #000; font-size: 10pt; font-weight: bold; text-align: right; padding: 4px 8px;">{{ number_format((float)$invoice->total, 2) }}</td>
            </tr>
        </tbody>
    </table>

    {{-- ===== Thai Baht Text ===== --}}
    <div style="font-size: 9pt; margin-top: 8px;">
        จำนวนเงินรวมทั้งสิ้น (ตัวอักษร) <b>({{ $bahtText }})</b>
    </div>

    {{-- ===== Notes ===== --}}
    @if($invoice->notes)
        <div style="font-size: 9pt; margin-top: 6px; color: #333;">
            หมายเหตุ: {{ $invoice->notes }}
        </div>
    @endif

    {{-- ===== Signature Footer ===== --}}
    <htmlpagefooter name="sigfooter">
        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td width="50%" style="height: 20mm;">&nbsp;</td>
                <td width="50%" style="height: 20mm;">&nbsp;</td>
            </tr>
            <tr>
                <td width="50%" style="text-align: center; font-family: sarabun, sans-serif;">
                    <span style="font-size: 9pt;">ลงชื่อ</span>
                    <span style="font-size: 9pt;">..........................................................</span><br>
                    <span style="font-size: 10pt;">ผู้รับสินค้า</span>
                </td>
                <td width="50%" style="text-align: center; font-family: sarabun, sans-serif;">
                    <span style="font-size: 9pt;">ลงชื่อ</span>
                    <span style="font-size: 9pt;">..........................................................</span><br>
                    <span style="font-size: 10pt;">ผู้รับเงิน</span>
                </td>
            </tr>
        </table>
    </htmlpagefooter>
    <sethtmlpagefooter name="sigfooter" value="on" />

</body>
</html>
