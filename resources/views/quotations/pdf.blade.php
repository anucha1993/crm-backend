<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<style>
    body {
        font-family: garuda, sans-serif;
        font-size: 11pt;
        color: #1a1a1a;
        line-height: 1.6;
    }
    td, th {
        font-family: garuda, sans-serif;
    }
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .text-left { text-align: left; }
    .text-gray { color: #555; }
    .text-red { color: #dc2626; }
    .text-bold { font-weight: bold; }
</style>
</head>
<body>

    {{-- ===== Header ===== --}}
    {{-- ===== Header: Company + QR ===== --}}
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 0px;">
        <tr>
            <td valign="top">
                @if($logoPath)
                    <img src="{{ $logoPath }}" height="55" /><br>
                @endif
                <span style="font-size: 14pt; font-weight: bold;">{{ $company['name'] ?? 'บริษัท' }}</span><br>
                @if(!empty($company['address']))
                    <span style="font-size: 8pt; color: #555;">{{ $company['address'] }}</span><br>
                @endif
                @if(!empty($company['tax_id']))
                    <span style="font-size: 8pt; color: #555;">เลขผู้เสียภาษี: {{ $company['tax_id'] }}</span>
                @endif
                <span style="font-size: 8pt; color: #555;">
                    @if(!empty($company['phone']))โทร: {{ $company['phone'] }}@endif
                    @if(!empty($company['fax']))&nbsp;&nbsp;แฟกซ์: {{ $company['fax'] }}@endif
                    @if(!empty($company['email']))&nbsp;&nbsp;{{ $company['email'] }}@endif
                </span><br>
            </td>
            <td width="180" valign="top" style="text-align: right;">
                <barcode code="{{ $qrData }}" type="QR" size="0.8" error="L" style="margin-bottom: 5px;" />
                
                <br>
                <span style="font-size: 9pt; color: #555;"><b>วันที่:</b> {{ $createdDate }}</span><br>
                <span style="font-size: 9pt; color: #555;"><b>เลขที่:</b> {{ $quotation->quotation_number }}</span>
                @if($quotation->revision_number > 0)
                    <span style="font-size: 8pt; color: #2563eb; font-weight: bold;">&nbsp;Rev.{{ str_pad($quotation->revision_number, 2, '0', STR_PAD_LEFT) }}</span>
                @endif
                <br>
                @if($quotation->creator)
                    <span style="font-size: 9pt; color: #555;"><b>ชื่อผู้ขาย (Sale):</b> {{ $quotation->creator->name }}</span>
                @endif
            </td>
        </tr>
    </table>

    {{-- ===== Document Title (centered) ===== --}}
    <div style="text-align: center; font-size: 16pt; font-weight: bold; margin-bottom: 0px;">
        {{ $isVat ? 'ใบเสนอราคา / ใบกำกับภาษี' : 'ใบเสนอราคา / Quotation' }}
    </div>

    <hr style="border: none; border-top: 2px solid #1a1a1a; margin-bottom: 12px;">

    {{-- ===== Customer & Shipping Info ===== --}}
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 12px;">
        <tr>
            <td width="48%" valign="top">
                <table width="100%" cellpadding="8" cellspacing="0" style="">
                    <tr>
                        <td>
                            <span style="font-size: 8pt; font-weight: bold; color: #888;">ลูกค้า</span><br>
                            <span style="font-size: 11pt; font-weight: bold;">{{ $quotation->customer->name ?? '-' }}</span><br>
                            @if($quotation->customer?->address)
                                <span style="font-size: 8pt; color: #555;">{{ $quotation->customer->address }}</span><br>
                            @endif
                            <span style="font-size: 8pt; color: #555;">
                                @if($quotation->customer?->phone)โทร: {{ $quotation->customer->phone }}@endif
                                @if($quotation->customer?->email)&nbsp;&nbsp;{{ $quotation->customer->email }}@endif
                            </span>
                            @if($quotation->customer?->tax_id)
                                <br><span style="font-size: 8pt; color: #555;">เลขผู้เสียภาษี: {{ $quotation->customer->tax_id }}</span>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
            <td width="4%"></td>
            <td width="48%" valign="top">
                @if($quotation->shippingAddress)
                <table width="100%" cellpadding="8" cellspacing="0" style="margin-top: 6px;">
                    <tr>
                        <td>
                            <span style="font-size: 8pt; font-weight: bold; color: #888;">ที่อยู่จัดส่ง</span><br>
                            @if($quotation->shippingAddress->label)
                                <span style="font-size: 11pt; font-weight: bold;">{{ $quotation->shippingAddress->label }}</span><br>
                            @endif
                            <span style="font-size: 8pt; color: #555;">{{ $quotation->shippingAddress->address }}</span>
                            @if($quotation->shippingAddress->contact_name || $quotation->shippingAddress->phone)
                                <br><span style="font-size: 8pt; color: #555;">
                                    ผู้รับ: {{ $quotation->shippingAddress->contact_name ?? '' }}
                                    @if($quotation->shippingAddress->phone)({{ $quotation->shippingAddress->phone }})@endif
                                </span>
                            @endif
                        </td>
                    </tr>
                </table>
                @endif
            </td>
        </tr>
    </table>

    {{-- ===== Items Table ===== --}}
    <table width="100%" cellpadding="5" cellspacing="0" style="border-collapse: collapse; margin-bottom: 9px;">
        <thead>
            <tr style="background-color: #1f2937; color: #ffffff;">
                <th width="25" style="text-align: center; font-size: 8pt; padding: 6px; color: #ffffff;">#</th>
                <th width="45" style="text-align: right; font-size: 8pt; padding: 6px; color: #ffffff;">จำนวน</th>
                <th width="45" style="text-align: center; font-size: 8pt; padding: 6px; color: #ffffff;">หน่วย</th>
                <th style="text-align: left; font-size: 8pt; padding: 6px; color: #ffffff;">รายการสินค้า</th>
                <th width="80" style="text-align: right; font-size: 8pt; padding: 6px; color: #ffffff; white-space: nowrap;">ความยาว</th>
                <th width="75" style="text-align: right; font-size: 8pt; padding: 6px; color: #ffffff; white-space: nowrap;">ราคา/หน่วย</th>
                <th width="85" style="text-align: right; font-size: 8pt; padding: 6px; color: #ffffff; white-space: nowrap;">จำนวนเงินรวม</th>
            </tr>
        </thead>
        <tbody>
            @foreach($quotation->items as $i => $item)
                <tr style="{{ $i % 2 === 1 ? 'background-color: #f9fafb;' : '' }}">
                    <td style="text-align: center; color: #555; border-bottom: 1px solid #e5e7eb; font-size: 8pt;">{{ $i + 1 }}</td>
                    <td style="text-align: right; color: #555; border-bottom: 1px solid #e5e7eb; font-size: 8pt;">{{ number_format((float)$item->quantity) }}</td>
                    <td style="text-align: center; color: #555; border-bottom: 1px solid #e5e7eb; font-size: 8pt;">{{ $item->unit }}</td>
                    <td style="border-bottom: 1px solid #e5e7eb; font-size: 8pt;">
                        @if($item->product)
                            {{ $item->product->name }}. {{'('.$item->unit_price.'/'.$item->product?->sizes?->first()?->length_unit.')' ?? ''.')' }}
                        @endif
                        @if($item->description)
                        <br>
                            <span style="font-size: 8pt; color: #555;">({{ $item->description }})</span>
                        @endif
                    </td>
                    <td style="text-align: right; color: #555; border-bottom: 1px solid #e5e7eb; font-size: 8pt; white-space: nowrap;">{{ $item->length ? number_format((float)$item->length, 2) . ' ' . ($item->product?->sizes?->first()?->length_unit ?? '') : '-' }}</td>
                    <td style="text-align: right; color: #555; border-bottom: 1px solid #e5e7eb; font-size: 8pt; white-space: nowrap;">{{ number_format((float)$item->length * (float)$item->unit_price, 2).'/'. $item->unit }}</td>
                    <td style="text-align: right; font-weight: bold; border-bottom: 1px solid #e5e7eb; font-size: 8pt; white-space: nowrap;">{{ number_format((float)$item->amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ===== Totals ===== --}}
    <table width="280" cellpadding="4" cellspacing="0" style="margin-left: auto;">
        <tr>
            <td style="color: #555; font-size: 10pt;">ราคารวม</td>
            <td style="text-align: right; font-size: 10pt;">{{ number_format((float)$quotation->subtotal, 2) }}</td>
        </tr>
        @if((float)$quotation->discount_amount > 0)
            <tr>
                <td style="color: #dc2626; font-size: 10pt;">ส่วนลด{{ $quotation->discount_type === 'percent' ? ' (' . (float)$quotation->discount_value . '%)' : '' }}</td>
                <td style="text-align: right; color: #dc2626; font-size: 10pt;">-{{ number_format((float)$quotation->discount_amount, 2) }}</td>
            </tr>
        @endif
        @if($isVat)
            <tr>
                <td style="color: #555; font-size: 10pt;">ภาษีมูลค่าเพิ่ม ({{ (float)$quotation->vat_rate }}%)</td>
                <td style="text-align: right; font-size: 10pt;">{{ number_format((float)$quotation->vat_amount, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td style="border-top: 2px solid #1a1a1a; font-weight: bold; font-size: 12pt; padding-top: 6px;">ยอดรวมสุทธิ</td>
            <td style="border-top: 2px solid #1a1a1a; font-weight: bold; font-size: 12pt; padding-top: 6px; text-align: right;">{{ number_format((float)$quotation->total, 2) }}</td>
        </tr>
    </table>
    <div style="font-size: 8pt; color: #888; text-align: right;">({{ $bahtText }})</div>

    {{-- ===== Notes ===== --}}
    @if($quotation->notes)
        <table width="100%" cellpadding="10" cellspacing="0" style="background-color: #fefce8; border: 1px solid #fde68a; margin-top: 12px; margin-bottom: 12px;">
            <tr>
                <td>
                    <span style="font-size: 8pt; font-weight: bold; color: #888;">หมายเหตุ</span><br>
                    <span style="font-size: 10pt; color: #374151;">{{ $quotation->notes }}</span>
                </td>
            </tr>
        </table>
    @endif

    {{-- ===== Bank Info ===== --}}
    @if(!empty($company['bank_name']) || !empty($company['bank_account_number']))
        <table width="100%" cellpadding="10" cellspacing="0" style="background-color: #eff6ff; border: 1px solid #bfdbfe; margin-bottom: 15px;">
            <tr>
                <td>
                    <span style="font-size: 8pt; font-weight: bold; color: #888;">ข้อมูลการชำระเงิน</span><br>
                    @if(!empty($company['bank_name']))
                        <span style="font-size: 10pt;"><span style="color: #888;">ธนาคาร: </span>{{ $company['bank_name'] }}</span>
                        @if(!empty($company['bank_branch']))
                            &nbsp;&nbsp;<span style="font-size: 10pt;"><span style="color: #888;">สาขา: </span>{{ $company['bank_branch'] }}</span>
                        @endif
                        <br>
                    @endif
                    @if(!empty($company['bank_account_name']))
                        <span style="font-size: 10pt;"><span style="color: #888;">ชื่อบัญชี: </span>{{ $company['bank_account_name'] }}</span>
                        @if(!empty($company['bank_account_number']))
                            &nbsp;&nbsp;<span style="font-size: 10pt;"><span style="color: #888;">เลขที่บัญชี: </span>{{ $company['bank_account_number'] }}</span>
                        @endif
                    @endif
                </td>
            </tr>
        </table>
    @endif

    {{-- ===== Payment Terms ===== --}}
    <table width="100%" cellpadding="10" cellspacing="0" style="background-color: #f9fafb; border: 1px solid #d1d5db; margin-bottom: 15px;">
        <tr>
            <td>
                <span style="font-size: 9pt; font-weight: bold; color: #555;">หมายเหตุ: เงื่อนไขการชำระเงิน</span><br>
                <span style="font-size: 9pt; color: #374151;">1. โอนก่อนจัดส่งสินค้า</span><br>
                <span style="font-size: 9pt; color: #374151;">2. ชำระเป็นเงินสด เมื่อตรวจรับสินค้าเรียบร้อย</span>
            </td>
        </tr>
    </table>

    {{-- ===== Signature (mPDF page footer — always at bottom) ===== --}}
    <htmlpagefooter name="sigfooter">
        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td width="50%" style="height: 25mm;">&nbsp;</td>
                <td width="50%" style="height: 25mm;">&nbsp;</td>
            </tr>
            <tr>
                <td width="50%" style="text-align: center; font-family: garuda, sans-serif;">
                    <div style="width: 200px; border-bottom: 1px solid #9ca3af; margin: 0 auto 5px auto;">&nbsp;</div>
                    <span style="font-size: 10pt; color: #555;">ผู้เสนอราคา</span><br>
                    <br>
                    <br>
                    @if($quotation->creator)
                        <span style="font-size: 8pt; color: #9ca3af;">{{ $quotation->creator->name }}</span><br>
                    @endif
                    <span style="font-size: 8pt; color: #9ca3af;">วันที่ {{ $createdDate }}</span>
                </td>
                <td width="50%" style="text-align: center; font-family: garuda, sans-serif;">
                    <div style="width: 200px; border-bottom: 1px solid #9ca3af; margin: 0 auto 5px auto;">&nbsp;</div>
                    <span style="font-size: 10pt; color: #555;">ผู้อนุมัติ</span><br>
                    <br>
                    <br>
                    <span style="font-size: 10pt; color: #555;">--------------------------</span><br>
                     
                    <span style="font-size: 8pt; color: #9ca3af;">วันที่ ____/____/____</span>
                </td>
            </tr>
        </table>
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 5px;">
            <tr>
                <td style="text-align: center; font-size: 8pt; color: #9ca3af; font-family: garuda, sans-serif;">
                    หน้า {PAGENO}/{nbpg}
                </td>
            </tr>
        </table>
    </htmlpagefooter>
    <sethtmlpagefooter name="sigfooter" value="on" />

</body>
</html>
