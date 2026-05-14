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
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 0;">
        <tr>
            <td valign="top">
                @if($logoPath)
                    <img src="{{ $logoPath }}" height="48" style="margin-bottom:0;" />
                @endif
                <span style="font-size: 13pt; font-weight: bold;">{{ $company['name'] ?? 'บริษัท' }}</span>
                @if(!empty($company['address']))
                    <br><span style="font-size: 7pt; color: #555;">{{ $company['address'] }}</span>
                @endif
                @if(!empty($company['tax_id']))
                    <br><span style="font-size: 7pt; color: #555;">เลขผู้เสียภาษี: {{ $company['tax_id'] }}</span>
                @endif
                <br><span style="font-size: 7pt; color: #555;">
                    @if(!empty($company['phone']))โทร: {{ $company['phone'] }}@endif
                    @if(!empty($company['fax']))&nbsp;&nbsp;แฟกซ์: {{ $company['fax'] }}@endif
                    @if(!empty($company['email']))&nbsp;&nbsp;{{ $company['email'] }}@endif
                </span>
            </td>
            <td width="160" valign="top" style="text-align: right;">
                <barcode code="{{ $qrData }}" type="QR" size="0.7" error="L" style="margin-bottom: 0;" />
                <div style="font-size: 8pt; color: #555; margin-bottom:0; margin-top:2px;"><b>วันที่:</b> {{ $createdDate }}</div>
                <div style="font-size: 8pt; color: #555; margin-bottom:0;"><b>เลขที่:</b> {{ $quotation->quotation_number }}@if($quotation->revision_number > 0)<span style="font-size: 7pt; color: #2563eb; font-weight: bold;">&nbsp;Rev.{{ str_pad($quotation->revision_number, 2, '0', STR_PAD_LEFT) }}</span>@endif</div>
                @if($quotation->valid_until)
                    @php $isExpired = \Carbon\Carbon::parse($quotation->valid_until)->endOfDay()->isPast(); @endphp
                    <div style="font-size: 8pt; color: {{ $isExpired ? '#dc2626' : '#555' }}; margin-bottom:0;"><b>ยืนราคาถึง:</b> {{ \Carbon\Carbon::parse($quotation->valid_until)->locale('th')->translatedFormat('d M Y') }}@if($isExpired)<span style="font-size: 8pt; color: #dc2626; font-weight: bold;">&nbsp;(เลยกำหนด)</span>@endif</div>
                @endif
                @if($quotation->creator)
                    <div style="font-size: 8pt; color: #555; margin-bottom:0;"><b>ชื่อผู้ขาย (Sale):</b> {{ $quotation->creator->name }}</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- ===== Document Title (centered) ===== --}}
    <div style="text-align: center; font-size: 14pt; font-weight: bold; margin-bottom: 0; margin-top: 0; padding: 0;">
        {{ 'ใบเสนอราคา / Quotation' }}
    </div>
    <hr style="border: none; border-top: 2px solid #1a1a1a; margin-bottom: 4px; margin-top: 2px;">
    {{-- ===== Customer & Shipping Info ===== --}}
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 2px;">
        <tr>
            <td width="48%" valign="top">
                <table width="100%" cellpadding="2" cellspacing="0" style="margin-bottom:0;">
                    <tr>
                        <td>
                            <span style="font-size: 8pt; font-weight: bold; color: #888;">ลูกค้า</span><br>
                            <span style="font-size: 11pt; font-weight: bold;">{{ $quotation->customer->name ?? '-' }}</span><br>
                            @if($quotation->customer?->address)
                                <span style="font-size: 8pt; color: #555;">{{ $quotation->customer->address }}</span><br>
                            @endif
                            <span style="font-size: 8pt; color: #555;">
                                @if($quotation->customer?->phone)โทร: {{ $quotation->customer->phone }}@endif
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
                <table width="100%" cellpadding="2" cellspacing="0" style="margin-top: 0; margin-bottom:0;">
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
    <table width="100%" cellpadding="2" cellspacing="0" style="border-collapse: collapse; margin-bottom: 0;">
        <thead>
            <tr style="background-color: #1f2937; color: #ffffff;">
                <th width="22" style="text-align: center; font-size: 7pt; padding: 3px; color: #ffffff;">#</th>
                <th width="40" style="text-align: right; font-size: 7pt; padding: 3px; color: #ffffff;">จำนวน</th>
                <th width="40" style="text-align: center; font-size: 7pt; padding: 3px; color: #ffffff;">หน่วย</th>
                <th style="text-align: left; font-size: 7pt; padding: 3px; color: #ffffff;">รายการสินค้า</th>
                <th width="70" style="text-align: right; font-size: 7pt; padding: 3px; color: #ffffff; white-space: nowrap;">ความยาว</th>
                <th width="70" style="text-align: right; font-size: 7pt; padding: 3px; color: #ffffff; white-space: nowrap;">ราคา/หน่วย</th>
                <th width="80" style="text-align: right; font-size: 7pt; padding: 3px; color: #ffffff; white-space: nowrap;">จำนวนเงินรวม</th>
            </tr>
        </thead>
        <tbody>
            @foreach($quotation->items as $i => $item)
                @php
                    $rawUnit = trim((string)($item->unit ?? ''));
                    $productUnit = trim((string)($item->product->unit ?? ''));
                    $isSheet = in_array($rawUnit, ['แผ่น', 'ตรม.', 'ตรม']) || $productUnit === 'แผ่น';
                    $displayUnit = $isSheet ? 'ตรม.' : $rawUnit;
                    $lengthUnitRaw = $item->product?->sizes?->first()?->length_unit ?? '';
                    $displayLengthUnit = $isSheet ? 'ตรม.' : $lengthUnitRaw;
                @endphp
                <tr style="{{ $i % 2 === 1 ? 'background-color: #f9fafb;' : '' }}">
                    <td style="text-align: center; color: #555; border-bottom: 1px solid #e5e7eb; font-size: 7pt; padding: 2px 3px;">{{ $i + 1 }}</td>
                    <td style="text-align: right; color: #555; border-bottom: 1px solid #e5e7eb; font-size: 7pt; padding: 2px 3px;">{{ number_format((float)$item->quantity) }}</td>
                    <td style="text-align: center; color: #555; border-bottom: 1px solid #e5e7eb; font-size: 7pt; padding: 2px 3px;">{{ $displayUnit }}</td>
                    <td style="border-bottom: 1px solid #e5e7eb; font-size: 7pt; padding: 2px 3px;">
                        @if($item->product){{ $item->product->name }}. ({{ $item->unit_price }}/{{ $displayLengthUnit }})@endif
                        @if($item->description) <span style="color: #555;">({{ $item->description }})</span>@endif
                    </td>
                    <td style="text-align: right; color: #555; border-bottom: 1px solid #e5e7eb; font-size: 7pt; padding: 2px 3px; white-space: nowrap;">{{ $item->length ? number_format((float)$item->length, 2) . ' ' . $displayLengthUnit : '-' }}</td>
                    <td style="text-align: right; color: #555; border-bottom: 1px solid #e5e7eb; font-size: 7pt; padding: 2px 3px; white-space: nowrap;">{{ number_format((float)$item->length * (float)$item->unit_price, 2).'/'. $displayUnit }}</td>
                    <td style="text-align: right; font-weight: bold; border-bottom: 1px solid #e5e7eb; font-size: 7pt; padding: 2px 3px; white-space: nowrap;">{{ number_format((float)$item->amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ===== Totals ===== --}}
    <table width="280" cellpadding="2" cellspacing="0" style="margin-left: auto; margin-bottom: 0;">
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
            <td style="border-top: 2px solid #1a1a1a; font-weight: bold; font-size: 12pt; padding-top: 0;">ยอดรวมสุทธิ</td>
            <td style="border-top: 2px solid #1a1a1a; font-weight: bold; font-size: 12pt; padding-top: 0; text-align: right;">{{ number_format((float)$quotation->total, 2) }}</td>
        </tr>
    </table>
    <div style="font-size: 8pt; color: #888; text-align: right; margin-top: 0; margin-bottom: 0; padding: 0;">({{ $bahtText }})</div>

    {{-- ===== Notes ===== --}}
    @if($quotation->notes)
        <table width="100%" cellpadding="2" cellspacing="0" style="background-color: #fefce8; border: 1px solid #fde68a; margin-top: 0; margin-bottom: 0;">
            <tr>
                <td>
                    <span style="font-size: 7.5pt; font-weight: bold; color: #888;">หมายเหตุ</span><br>
                    <span style="font-size: 9pt; color: #374151;">{{ $quotation->notes }}</span>
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
    <table width="100%" cellpadding="2" cellspacing="0" style="background-color: #f9fafb; border: 1px solid #d1d5db; margin-bottom: 0; margin-top: 0;">
        <tr>
            <td>
                <span style="font-size: 7.5pt; font-weight: bold; color: #555;">หมายเหตุ: เงื่อนไขการชำระเงิน</span><br>
                <span style="font-size: 8.5pt; color: #374151;">1. โอนก่อนจัดส่งสินค้า</span><br>
                <span style="font-size: 8.5pt; color: #374151;">2. ชำระเป็นเงินสด เมื่อตรวจรับสินค้าเรียบร้อย</span><br>
                <span style="font-size: 8.5pt; color: #374151;">3. รบกวนลูกค้าตรวจสอบรายการสินค้า ก่อนคอนเฟิร์มการสั่งซื้อ หากผิดพลาดทางบริษัท ขอสงวนสิทธิ์รับผิดชอบทุกกรณี</span><br>
                <span style="font-size: 8.5pt; color: #374151;">4. หากเป็นสินค้าไซต์พิเศษ เมื่อสั่งผลิตแล้ว ไม่สามารถเปลี่ยนแปลงได้</span>
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
