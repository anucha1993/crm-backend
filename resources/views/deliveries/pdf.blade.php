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
</style>
</head>
<body>

    {{-- ===== Title + Page Info ===== --}}
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 8px;">
        <tr>
            <td valign="bottom">
                <span style="font-size: 16pt; font-weight: bold; color: #1e40af;">ใบส่งสินค้า/delivery</span>
            </td>
            <td valign="top" style="text-align: right;">
                <span style="font-size: 9pt; color: #555;">หน้าที่: {{ $copyNumber }}/4</span><br>
                @if($delivery->total_weight > 0)
                    <span style="font-size: 9pt; color: #555;">น้ำหนักรวม: {{ number_format((float)$delivery->total_weight, 0) }} Kgs.</span>
                @endif
            </td>
        </tr>
    </table>

    <div style="border-top: 2px solid #1e40af; margin-bottom: 10px;"></div>

    {{-- ===== Customer Info + QR ===== --}}
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin-bottom: 12px;">
        <tr>
            <td width="50%" valign="top" style="border: 1px solid #ccc; padding: 8px 10px; background: #fafafa;">
                @php $addr = $delivery->shippingAddress ?? $order->shippingAddress; @endphp
                <span style="font-size: 9pt; color: #666;"><b>ชื่อลูกค้า :</b></span> <span style="font-size: 10pt; font-weight: bold;">{{ $delivery->customer->name ?? '-' }}</span><br>
                <span style="font-size: 9pt; color: #666;"><b>ที่อยู่จัดส่ง :</b></span> <span style="font-size: 9pt;">{{ $addr->label ?? '' }} {{ $addr->address ?? '-' }}</span><br>
                <span style="font-size: 9pt; color: #666;"><b>ชื่อผู้ติดต่อ:</b></span> <span style="font-size: 9pt;">{{ $addr->contact_name ?? ($delivery->customer->contact_name ?? '-') }}</span><br>
                <span style="font-size: 9pt; color: #666;"><b>เบอร์ติดต่อ:</b></span> <span style="font-size: 9pt;">{{ $addr->phone ?? ($delivery->customer->phone ?? '-') }}</span>
            </td>
            <td width="2%" style="padding: 0;"></td>
            <td width="33%" valign="top" style="border: 1px solid #ccc; padding: 8px 10px; background: #fafafa;">
                @php
                    $statusMap = ['pending' => 'รอจัดส่ง', 'shipped' => 'จัดส่งแล้ว', 'delivered' => 'ส่งครบ', 'partial' => 'ส่งบางส่วน', 'cancelled' => 'ยกเลิก'];
                @endphp
                <span style="font-size: 9pt; color: #666;"><b>วันที่จัดส่ง :</b></span> <span style="font-size: 9pt;">{{ $deliveryDate }}</span><br>
                <span style="font-size: 9pt; color: #666;"><b>เลขที่บิลหลัก :</b></span> <span style="font-size: 9pt;">{{ $order->order_number }}</span><br>
                <span style="font-size: 9pt; color: #666;"><b>เลขที่บิลย่อย :</b></span> <span style="font-size: 9pt;">{{ $delivery->delivery_number }}</span><br>
                <span style="font-size: 9pt; color: #666;"><b>สถานะ :</b></span>
                @if($delivery->status === 'delivered')
                    <span style="font-size: 9pt; font-weight: bold;">ส่งครบแล้ว</span>
                @elseif($delivery->status === 'cancelled')
                    <span style="font-size: 9pt; font-weight: bold; color: #dc2626;">ยกเลิก</span>
                @else
                    <span style="font-size: 9pt; font-weight: bold;">-</span>
                @endif
            </td>
            <td width="2%" style="padding: 0;"></td>
            <td width="13%" valign="top" style="text-align: center; padding: 4px 0 0 0;">
                <barcode code="{{ $qrData }}" type="QR" size="0.75" error="L" />
                <br>
                <span style="font-size: 8pt; color: #888;">Billno : {{ $copyNumber }}</span>
            </td>
        </tr>
    </table>

    {{-- ===== Items Table ===== --}}
    <table width="100%" cellpadding="4" cellspacing="0" style="border-collapse: collapse; margin-bottom: 0;">
        <thead>
            <tr style="background: #1e3a5f;">
                <th width="35" style="text-align: center; font-size: 9pt; padding: 6px; border: 1px solid #1e3a5f; color: #fff;">ลำดับ</th>
                <th width="50" style="text-align: center; font-size: 9pt; padding: 6px; border: 1px solid #1e3a5f; color: #fff;">จำนวน</th>
                <th width="55" style="text-align: center; font-size: 9pt; padding: 6px; border: 1px solid #1e3a5f; color: #fff;">หน่วยนับ</th>
                <th style="text-align: center; font-size: 9pt; padding: 6px; border: 1px solid #1e3a5f; color: #fff;">รายการสินค้า</th>
                @if($showPrices)
                    <th width="85" style="text-align: center; font-size: 9pt; padding: 6px; border: 1px solid #1e3a5f; color: #fff;">ราคาต่อหน่วย</th>
                    <th width="90" style="text-align: center; font-size: 9pt; padding: 6px; border: 1px solid #1e3a5f; color: #fff;">จำนวนเงิน</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @php $colCount = $showPrices ? 6 : 4; @endphp
            @foreach($delivery->items as $i => $item)
                <tr style="{{ $i % 2 === 1 ? 'background: #f7f9fc;' : '' }}">
                    <td style="text-align: center; font-size: 9pt; border-left: 1px solid #ccc; border-right: 1px solid #ccc; border-bottom: 1px solid #eee; padding: 5px;">{{ $i + 1 }}</td>
                    <td style="text-align: right; font-size: 9pt; border-right: 1px solid #ccc; border-bottom: 1px solid #eee; padding: 5px 6px;">{{ number_format((float)$item->quantity) }}</td>
                    <td style="text-align: center; font-size: 9pt; border-right: 1px solid #ccc; border-bottom: 1px solid #eee; padding: 5px;">{{ $item->unit }}</td>
                    <td style="font-size: 9pt; border-right: 1px solid #ccc; border-bottom: 1px solid #eee; padding: 5px 6px;">
                        @if($item->product)
                            {{ $item->product->name }}
                        @endif
                        @if($item->length)
                            &nbsp;&nbsp;ยาว {{ number_format((float)$item->length, 2) }} {{ $item->product?->sizes?->first()?->length_unit ?? 'เมตร' }}
                        @endif
                        @if($item->description)
                            <br><span style="font-size: 8pt; color: #888;">({{ $item->description }})</span>
                        @endif
                    </td>
                    @if($showPrices)
                        <td style="text-align: right; font-size: 9pt; border-right: 1px solid #ccc; border-bottom: 1px solid #eee; padding: 5px 6px;">{{ number_format((float)$item->unit_price, 2) }}</td>
                        <td style="text-align: right; font-size: 9pt; font-weight: bold; border-right: 1px solid #ccc; border-bottom: 1px solid #eee; padding: 5px 6px;">{{ number_format((float)$item->amount, 2) }}</td>
                    @endif
                </tr>
            @endforeach
            {{-- Empty rows to fill table --}}
            @for($j = count($delivery->items); $j < 15; $j++)
                <tr style="{{ $j % 2 === 1 ? 'background: #f7f9fc;' : '' }}">
                    <td style="border-left: 1px solid #ccc; border-right: 1px solid #ccc; border-bottom: 1px solid #eee; height: 20px;">&nbsp;</td>
                    <td style="border-right: 1px solid #ccc; border-bottom: 1px solid #eee;">&nbsp;</td>
                    <td style="border-right: 1px solid #ccc; border-bottom: 1px solid #eee;">&nbsp;</td>
                    <td style="border-right: 1px solid #ccc; border-bottom: 1px solid #eee;">&nbsp;</td>
                    @if($showPrices)
                        <td style="border-right: 1px solid #ccc; border-bottom: 1px solid #eee;">&nbsp;</td>
                        <td style="border-right: 1px solid #ccc; border-bottom: 1px solid #eee;">&nbsp;</td>
                    @endif
                </tr>
            @endfor
            {{-- Bottom border --}}
            <tr>
                <td colspan="{{ $colCount }}" style="border-top: 2px solid #1e3a5f; height: 0; padding: 0;"></td>
            </tr>
            @if($showPrices)
                <tr>
                    <td colspan="4" style="padding: 0;"></td>
                    <td style="border: 1px solid #ccc; font-size: 9pt; padding: 5px 8px; text-align: right; background: #f7f9fc;">{{ $isVat ? 'ราคาก่อนภาษี:' : 'รวมเงิน:' }}</td>
                    <td style="border: 1px solid #ccc; font-size: 9pt; text-align: right; padding: 5px 8px;">{{ number_format($subtotal, 2) }}</td>
                </tr>
                <tr>
                    <td colspan="4" style="padding: 0;"></td>
                    <td style="border: 1px solid #ccc; font-size: 9pt; padding: 5px 8px; text-align: right; background: #f7f9fc;">ส่วนลด:</td>
                    <td style="border: 1px solid #ccc; font-size: 9pt; text-align: right; padding: 5px 8px;">{{ number_format($discountAmount, 2) }}</td>
                </tr>
                <tr>
                    <td colspan="4" style="padding: 0;"></td>
                    <td style="border: 1px solid #ccc; font-size: 9pt; padding: 5px 8px; text-align: right; background: #f7f9fc;">จำนวนหลังหักส่วนลด:</td>
                    <td style="border: 1px solid #ccc; font-size: 9pt; text-align: right; padding: 5px 8px;">{{ number_format($subtotal - $discountAmount, 2) }}</td>
                </tr>
                @if($isVat)
                <tr>
                    <td colspan="4" style="padding: 0;"></td>
                    <td style="border: 1px solid #ccc; font-size: 9pt; padding: 5px 8px; text-align: right; background: #f7f9fc;">ภาษีมูลค่าเพิ่ม:</td>
                    <td style="border: 1px solid #ccc; font-size: 9pt; text-align: right; padding: 5px 8px;">{{ number_format($vatAmount, 2) }}</td>
                </tr>
                @endif
                <tr>
                    <td colspan="4" style="padding: 0;"></td>
                    <td style="border: 1px solid #1e3a5f; font-size: 10pt; font-weight: bold; padding: 6px 8px; text-align: right; background: #1e3a5f; color: #fff;">จำนวนเงินทั้งสิ้น:</td>
                    <td style="border: 1px solid #1e3a5f; font-size: 10pt; font-weight: bold; text-align: right; padding: 6px 8px; background: #e8edf5;">{{ number_format($total, 2) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <br>

    {{-- ===== หมายเหตุ ===== --}}
    <div style="font-size: 9pt; margin-bottom: 6px;">
        <b>หมายเหตุ :</b>
    </div>
    <div style="border-bottom: 1px solid #999; margin-bottom: 20px; padding-bottom: 4px; font-size: 9pt; min-height: 14px;">
        {{ $delivery->notes ?? '' }}
    </div>

    {{-- ===== หมายเหตุการรับสินค้า (Footer - อยู่ล่างเสมอ) ===== --}}
    <htmlpagefooter name="deliveryfooter">
        <div style="font-size: 8pt; margin-bottom: 4px; color: #333; font-family: sarabun, sans-serif;">
            <b>หมายเหตุการรับสินค้า :</b> กรุณาตรวจสอบความถูกต้องของสินค้าและเซ็นรับสินค้าใบวันที่ได้รับ
        </div>
        <div style="font-size: 8pt; margin-bottom: 12px; color: #333; font-family: sarabun, sans-serif;">
            หากไม่มีการตรวจสอบหรือเซ็นรับสินค้า ทางบริษัทขอสงวนสิทธิ์ในการรับผิดชอบต่อความผิดพลาดทุกกรณี
        </div>
         <br>
        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td width="50%" style="text-align: center; font-family: sarabun, sans-serif;">
                    <div style="font-size: 9pt;">
                        ลงชื่อผู้รับสินค้า.......................................................ผู้รับสินค้า
                    </div>
                     <br>
                    <div style="font-size: 8pt; color: #999; margin-top: 4px;">
                        วันที่ ........./........../..........
                    </div>
                </td>
                <td width="50%" style="text-align: center; font-family: sarabun, sans-serif;">
                    <div style="font-size: 9pt;">
                        ลงชื่อผู้ส่งสินค้า.......................................................ผู้ส่งสินค้า
                    </div>
                    <br>
                    <div style="font-size: 8pt; color: #999; margin-top: 4px;">
                        วันที่ ........./........../..........
                    </div>
                </td>
            </tr>
        </table>
    </htmlpagefooter>
    <sethtmlpagefooter name="deliveryfooter" value="on" />

</body>
</html>
