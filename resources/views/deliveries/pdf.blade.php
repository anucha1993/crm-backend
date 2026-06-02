@php
    $copies = ['', '', '', ''];
    $totalCopies = count($copies);
    $chunks = $delivery->items->chunk(8)->values();
    if ($chunks->isEmpty()) { $chunks = collect([collect()]); }
    $totalPages = $chunks->count() * $totalCopies;

    $cust = $delivery->customer ?? $order?->customer;
    $shipAddr = $delivery->shippingAddress ?? $order?->shippingAddress;

    $totalWeight = 0;
    foreach ($delivery->items as $it) {
        $w = (float) ($it->product?->weight ?? 0);
        $len = (float) ($it->length ?? $it->product?->length ?? 0);
        $totalWeight += $w * $len * (float) $it->quantity;
    }
@endphp

@foreach ($copies as $copyIndex => $copyName)
    @foreach ($chunks as $chunkIndex => $chunk)
        @php
            $isLastPage = ($copyIndex === $totalCopies - 1) && ($chunkIndex === $chunks->count() - 1);
            $showPrice = ($copyIndex >= 2);
            $pageNumber = $copyIndex * $chunks->count() + $chunkIndex + 1;
        @endphp

        @if ($copyIndex > 0 || $chunkIndex > 0)
            <pagebreak />
        @endif

        <div class="page-content">
            <!-- Header Section -->
            <table style="width: 100%; margin-bottom: 10px; border: none;">
                <tr>
                    <td style="border: none; width: 65%; vertical-align: top;">
                        <h1 style="margin: 0; font-size: 18pt; font-weight: bold;"><b>ใบส่งสินค้า/delivery</b></h1>
                        <p style="margin: 5px 0 0 0; font-size: 14pt; color: #000;"><strong>{{ $copyName }}</strong></p>
                    </td>
                    <td style="border: none; width: 35%; vertical-align: top; text-align: right;">
                        <p style="margin: 0; font-size: 12pt;">
                            <strong>หน้า/ที่:</strong> {{ $pageNumber }}/{{ $totalPages }}
                        </p>
                        <p style="margin: 0; padding: 0; font-size: 12pt;">
                            <strong>น้ำหนักรวม:</strong> {{ number_format($totalWeight, 2) }} Kgs.
                        </p>
                    </td>
                </tr>
            </table>

            <!-- Document Info Table -->
            <table style="width: 100%; border-collapse: collapse; font-size: 14pt; margin-bottom: 10px;">
                <tr>
                    <td style="border: 1px solid #000; padding: 8px; text-align: left; width: 60%; vertical-align: top;">
                        <div><b>ชื่อลูกค้า :</b> {{ $cust?->name }}</div>
                        <div><b>ที่อยู่จัดส่ง :</b> {{ $shipAddr?->address ?? $cust?->address }}</div>
                        <div><b>ชื่อผู้ติดต่อ:</b> {{ $shipAddr?->contact_name ?? $cust?->contact_name ?? $cust?->name }}</div>
                        <div><b>เบอร์ติดต่อ:</b> {{ $shipAddr?->phone ?? $cust?->phone }}</div>
                    </td>
                    <td style="border: 1px solid #000; padding: 8px; text-align: left; width: 30%; vertical-align: top;">
                        <div><b>วันที่จัดส่ง :</b> {{ $deliveryDate }}</div>
                        <div><b>เลขที่บิลหลัก :</b> {{ $order?->order_number }}</div>
                        <div><b>เลขที่บิลย่อย :</b> {{ $delivery->delivery_number }}</div>
                        @if($order?->quotation?->quotation_number)
                            <div><b>เลขที่ใบเสนอราคา :</b> {{ $order->quotation->quotation_number }}</div>
                        @endif
                        <div><b>สถานะ :</b> {{ $isCompleteDelivery ? 'ส่งครบแล้ว' : 'ยังไม่ครบ' }}</div>
                    </td>
                    <td style="border: 1px solid #000; padding: 8px; text-align: center; width: 10%; vertical-align: middle;">
                        <div style="margin-bottom: 10px;">
                            <img src="{{ $qrDataUri }}" style="width: 70px; height: 70px;" />
                        </div>
                        <div style="font-size: 12pt;">
                            <b>Billno :</b> 1
                        </div>
                    </td>
                </tr>
            </table>

            <!-- Items Table -->
            <table style="width: 100%; border: 1px solid #000; border-collapse: collapse; font-size: 14pt; margin-bottom: 20px;">
                <thead>
                    <tr style="background-color: #f0f0f0;">
                        <th style="border: 1px solid #000; padding: 8px; text-align: center; width: 8%;">ลำดับ</th>
                        <th style="border: 1px solid #000; padding: 8px; text-align: center; width: 10%;">จำนวน</th>
                        <th style="border: 1px solid #000; padding: 8px; text-align: center; width: 12%;">หน่วยนับ</th>
                        <th style="border: 1px solid #000; padding: 8px; text-align: center;">รายการสินค้า</th>
                        @if ($showPrice)
                            <th style="border: 1px solid #000; padding: 8px; text-align: center; width: 15%;">ราคาต่อหน่วย</th>
                            <th style="border: 1px solid #000; padding: 8px; text-align: center; width: 15%;">จำนวน</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($chunk as $key => $item)
                        @php
                            $unit = $item->unit ?: ($item->product?->unit ?? '');
                            $name = $item->product?->name ?? '';
                            $lengthUnit = $item->product?->sizes?->first()?->length_unit ?? 'เมตร';
                            $steelType = $item->product?->steel_type;
                            $isService = $unit === 'บริการ' || str_contains(mb_strtolower($name), 'บริการ');
                            $qtyStr = rtrim(rtrim(number_format((float) $item->quantity, 1, '.', ''), '0'), '.');
                            $lengthStr = number_format((float) $item->length, 2);
                            $thicknessStr = $item->thickness !== null ? number_format((float) $item->thickness, 2) : null;
                        @endphp
                        <tr>
                            <td style="border: none; padding: 6px; text-align: center;">{{ $chunkIndex * 8 + $key + 1 }}</td>
                            <td style="border: none; padding: 6px; text-align: center;">{{ $qtyStr }}</td>
                            <td style="border: none; padding: 6px; text-align: center;">{{ $unit }}</td>
                            <td style="border: none; padding: 6px; text-align: left;">
                                {{ $name }}
                                @if($item->length && !$isService)
                                    {{ $lengthStr }} {{ $lengthUnit }}
                                @endif
                                @if($steelType)
                                    {{ $steelType }}
                                @endif
                                @if($item->description)
                                    <br><span style="font-size: 12pt; color: #666;">[หมายเหตุ: {{ $item->description }}]</span>
                                @endif
                            </td>
                            @if ($showPrice)
                                <td style="border: none; padding: 6px; text-align: right;">{{ number_format((float) $item->unit_price, 2) }}</td>
                                <td style="border: none; padding: 6px; text-align: right;">{{ number_format((float) $item->amount, 2) }}</td>
                            @endif
                        </tr>
                    @endforeach

                    @php $emptyRows = max(0, 7 - $chunk->count()); @endphp
                    @for ($i = 1; $i <= $emptyRows; $i++)
                        <tr>
                            <td style="border: none; padding: 6px; text-align: center; color: #fff;">{{ $chunk->count() + $i }}</td>
                            @if ($showPrice)
                                <td style="border: none; padding: 6px;" colspan="5">&nbsp;</td>
                            @else
                                <td style="border: none; padding: 6px;" colspan="3">&nbsp;</td>
                            @endif
                        </tr>
                    @endfor

                    @if ($showPrice)
                        <tr>
                            <td colspan="4" style="border: none;"></td>
                            <td style="border: 1px solid #000; padding: 6px; text-align: right; background-color: #f0f0f0; width: 20%; white-space: nowrap;"><strong>ราคาก่อนภาษี:</strong></td>
                            <td style="border: 1px solid #000; padding: 6px; text-align: right; width: 15%;">{{ number_format($subtotal, 2) }}</td>
                        </tr>
                        <tr>
                            <td colspan="4" style="border: none;"></td>
                            <td style="border: 1px solid #000; padding: 6px; text-align: right; background-color: #f0f0f0; white-space: nowrap;"><strong>ส่วนลด:</strong></td>
                            <td style="border: 1px solid #000; padding: 6px; text-align: right;">{{ number_format($discountAmount, 2) }}</td>
                        </tr>
                        <tr>
                            <td colspan="4" style="border: none;"></td>
                            <td style="border: 1px solid #000; padding: 6px; text-align: right; background-color: #f0f0f0; white-space: nowrap;"><strong>จำนวนหลังหักส่วนลด:</strong></td>
                            <td style="border: 1px solid #000; padding: 6px; text-align: right;">{{ number_format($subtotal - $discountAmount, 2) }}</td>
                        </tr>
                        @if ($isVat)
                        <tr>
                            <td colspan="4" style="border: none;"></td>
                            <td style="border: 1px solid #000; padding: 6px; text-align: right; background-color: #f0f0f0; white-space: nowrap;"><strong>ภาษีมูลค่าเพิ่ม:</strong></td>
                            <td style="border: 1px solid #000; padding: 6px; text-align: right;">{{ number_format($vatAmount, 2) }}</td>
                        </tr>
                        @endif
                        <tr>
                            <td colspan="4" style="border: none;"></td>
                            <td style="border: 1px solid #000; padding: 6px; text-align: right; background-color: #f0f0f0; white-space: nowrap;"><strong>จำนวนเงินทั้งสิ้น:</strong></td>
                            <td style="border: 1px solid #000; padding: 6px; text-align: right; font-weight: bold;">{{ number_format($total, 2) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    @endforeach
@endforeach
