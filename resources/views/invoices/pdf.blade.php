<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<style>
    body {
        font-family: garuda, sans-serif;
        font-size: 10pt;
        color: #000;
        line-height: 1.35;
    }
    /* NOTE: "div" intentionally excluded — divs have no default browser margin,
       and keeping them out of this reset lets per-div inline margin/line-height
       (used for header spacing) actually take effect. mPDF cannot override a
       shorthand "margin:0 !important" tied to the div selector with any later
       rule/inline style regardless of specificity. */
    p, h3, h4, h5, h6, td, th, span, b, strong, small, address {
        margin: 0 !important;
        padding: 0 !important;
        line-height: 1.35 !important;
    }
    div { margin: 0; padding: 0; line-height: 1.35; }
    address { font-style: normal; }
    .items {
        border-collapse: collapse;
        width: 100%;
        font-size: 9pt;
    }
    /* Base: vertical dividers only. NO !important so inline styles can override */
    .items th, .items td {
        border-left: 1px solid #000;
        border-right: 1px solid #000;
        border-top: none;
        border-bottom: none;
        padding: 5px 6px;
        vertical-align: top;
    }
    /* Header: bold + full horizontal borders */
    .items thead th {
        border-top: 1px solid #000;
        border-bottom: 1px solid #000;
        text-align: center;
        font-weight: bold;
        padding: 6px 6px;
    }
    /* Cols 5-6 totals rows: border-top gives row dividers (cols 1-4 use inline styles) */
    .items tr.totals-row td {
        border-top: 1px solid #000;
    }
    /* Last totals row cols 5-6: bottom border to close */
    .items tr.totals-last td {
        border-bottom: 1px solid #000;
    }
    .text-center { text-align: center !important; }
    .text-end { text-align: right !important; }
    .fs-14 { font-size: 14pt; }
    .fs-12 { font-size: 12pt; }
    .fs-11 { font-size: 11pt; }
    .fs-10 { font-size: 10pt; }
    .fs-9  { font-size: 9pt; }
    .title { text-align: center; font-size: 14pt; font-weight: bold; }
    .sig-line { border-top: 1px dotted #000; margin-top: 30px; }
</style>
</head>
<body>

@php
    // Number of item rows that fill an A4 page nicely (tuned so the items box
    // reaches the totals area on the last page)
    $rowsPerPage = 22;   // used for both prior pages and as the target fill for last page
    $lastPageMax = 22;   // max items on the last page (leave room for totals + signature)
    $items = $invoice->items;
    $total = $items->count();
    if ($total <= $lastPageMax) {
        $chunks = collect([$items]);
    } else {
        $lastCount = min($lastPageMax, $total);
        $remaining = $total - $lastCount;
        $priorPages = (int) ceil($remaining / $rowsPerPage);
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
    $loopIndex = 1;

    // Net (before VAT) — subtotal minus discount
    $netAfterDiscount = (float) $invoice->subtotal - (float) $invoice->discount_amount;

    // Blank filler rows for LAST page so the items box always reaches the totals
    $lastChunkCount = $chunks->last()->count();
    $fillerRowsLast = max(0, $lastPageMax - $lastChunkCount);
    $fillerRowsOther = 0; // don't fill middle pages
@endphp

@foreach($chunks as $chunkIndex => $chunk)

    {{-- ===== Title ===== --}}
    <div class="title">ใบเสร็จรับเงิน / ใบกำกับภาษี</div>

    {{-- ===== Header: Company block (top row) + Customer block (bottom row), ONE table.
         "เลขที่" aligns with the company row; "วันที่" aligns with the customer row.
         NOTE: mPDF silently ignores margin/line-height on block children inside a <td>, so
         line gaps use "<br><span style='font-size:5pt'>&nbsp;</span><br>" (a small blank
         line) instead — plain "<br><br>" was measured too tall (~27pt); this gives ~20pt.
         A trailing break placed right before </td> gets collapsed/ignored by mPDF, so the
         gap between the two <tr> rows uses a matching small-font spacer <tr> instead. --}}
    @php $lineGap = '<br><span style="font-size:5pt; line-height:5pt !important;">&nbsp;</span><br>'; @endphp
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 8px !important;">
        <tr>
            <td valign="top" width="70%" class="fs-10">
                <span class="fs-11"><b>{{ $company['name'] ?? 'บริษัท' }}@if(!empty($company['branch'])) ({{ $company['branch'] }})@endif</b></span>{!! $lineGap !!}
                @php
                    $companyAddr = trim((string)($company['address'] ?? ''));
                    // Strip a leading "ที่อยู่" if the field already contains it, so we don't double-print it
                    $companyAddrClean = preg_replace('/^\s*ที่อยู่\s*:?\s*/u', '', $companyAddr);
                @endphp
                @if($companyAddrClean !== '')
                    ที่อยู่ : {{ $companyAddrClean }}{!! $lineGap !!}
                @endif
                @if(!empty($company['phone']))
                    โทร: {{ $company['phone'] }}{!! $lineGap !!}
                @endif
                สาขาที่/Branch {{ $company['branch'] ?? 'สำนักงานใหญ่' }}
                @if(!empty($company['tax_id']))
                    &nbsp;&nbsp;เลขประจำตัวผู้เสียภาษี/Tax ID. {{ $company['tax_id'] }}
                @endif
            </td>
            <td valign="top" width="30%" style="text-align: right;">
                <div class="fs-10">เลขที่ &nbsp; {{ $invoice->invoice_number }}</div>
            </td>
        </tr>
        {{-- Spacer row: small blank line, matches the reduced $lineGap used between lines above --}}
        <tr>
            <td colspan="2" style="font-size: 5pt; line-height: 5pt !important;">&nbsp;</td>
        </tr>
        <tr>
            <td valign="top" width="70%" class="fs-10">
                ได้รับเงินจาก &nbsp;&nbsp;{{ $invoice->customer->name ?? '-' }}{!! $lineGap !!}
                @php
                    $custAddr = trim((string)($invoice->customer?->address ?? ''));
                    $custAddrClean = preg_replace('/^\s*ที่อยู่\s*:?\s*/u', '', $custAddr);
                @endphp
                @if($custAddrClean !== '')
                    ที่อยู่ &nbsp;&nbsp;{{ $custAddrClean }}{!! $lineGap !!}
                @endif
                @if($invoice->customer?->tax_id)
                    เลขประจำตัวผู้เสียภาษีอากร &nbsp;&nbsp;{{ $invoice->customer->tax_id }}
                @endif
                
            </td>
            
            <td valign="top" width="30%" class="fs-10" style="text-align: right;">
                <div>วันที่ &nbsp; {{ $issueDate }}</div>
            </td>
        </tr>
    </table>

    {{-- ===== Items Table ===== --}}
    <br>
    
    <table class="items" style="margin-top: 10px !important;">
        <thead>
            <tr>
                <th width="6%">ลำดับ</th>
                <th width="10%">จำนวน</th>
                <th width="10%">หน่วยนับ</th>
                <th>รายการสินค้า</th>
                <th width="17%">ราคาต่อหน่วย</th>
                <th width="18%">จำนวนเงินไม่รวมภาษี</th>
            </tr>
        </thead>
        <tbody>
            @foreach($chunk as $item)
                @php
                    $rawUnit = trim((string)($item->unit ?? ''));
                    $lengthUnitRaw = $item->product?->sizes?->first()?->length_unit ?? '';
                    $displayLengthUnit = $lengthUnitRaw;
                    $thickness = (float)($item->thickness ?? 0);
                    $productUnit = trim((string)($item->product->unit ?? ''));
                    $isSheet = $rawUnit === 'แผ่น' || $productUnit === 'แผ่น';
                    $totalArea = ($isSheet && $thickness > 0 && (float)$item->length > 0)
                        ? (float)$item->quantity * $thickness * (float)$item->length
                        : null;
                    $pricePerPiece = ($isSheet && $thickness > 0 && (float)$item->length > 0)
                        ? $thickness * (float)$item->unit_price * (float)$item->length
                        : null;
                @endphp
                <tr>
                    <td class="text-center">{{ $loopIndex++ }}</td>
                    <td class="text-center">{{ number_format((float)$item->quantity, 2) }}</td>
                    <td class="text-center">{{ $rawUnit }}</td>
                    <td>
                        {{ $item->product->name ?? $item->description }}
                        @if((float)$item->length > 0)
                            &nbsp;&nbsp;ยาว {{ number_format((float)$item->length, 2) }} {{ $displayLengthUnit ?: '' }}
                        @endif
                        @if($thickness > 0)
                            &nbsp;&nbsp;กว้าง {{ number_format($thickness, 2) }}@if($item->product?->thickness_unit) {{ $item->product->thickness_unit }}@endif
                        @endif
                        @if($item->description && $item->product && $item->description !== $item->product->name)
                            <br><span class="fs-9">{{ $item->description }}</span>
                        @endif
                    </td>
                    <td class="text-end">
                        @if($pricePerPiece !== null)
                            {{ number_format($pricePerPiece, 2) }}/แผ่น
                        @else
                            {{ number_format((float)$item->unit_price, 2) }}@if($displayLengthUnit)/{{ $displayLengthUnit }}@endif
                        @endif
                    </td>
                    <td class="text-end">{{ number_format((float)$item->amount, 2) }}</td>
                </tr>
            @endforeach

            {{-- Filler blank row on the LAST page — one row with a computed height so
                 the items table always reaches the totals area regardless of item count.
                 Column dividers stay visible because we emit 6 separate <td>s (no colspan). --}}
            @if($chunkIndex === $totalPages - 1)
                @php
                    // A4 usable = 806pt (297mm - 5mm top - 8mm bottom margins).
                    // Header grew taller after adding the row-spacer + wider <br><br> gaps
                    // (measured: ~583pt available before header+totals+sig eat into it).
                    $itemRowH = 30;
                    $availItems = 545;
                    $itemsHeightEst = $chunk->count() * $itemRowH;
                    $fillerHeight = max(0, $availItems - $itemsHeightEst);
                @endphp
                @if($fillerHeight > 0)
                    <tr>
                        <td style="height: {{ $fillerHeight }}px">&nbsp;</td>
                        <td style="height: {{ $fillerHeight }}px">&nbsp;</td>
                        <td style="height: {{ $fillerHeight }}px">&nbsp;</td>
                        <td style="height: {{ $fillerHeight }}px">&nbsp;</td>
                        <td style="height: {{ $fillerHeight }}px">&nbsp;</td>
                        <td style="height: {{ $fillerHeight }}px">&nbsp;</td>
                    </tr>
                @endif

                {{-- Totals rows — LAST 5 rows embedded inside the items table.
                     Cols 1-4 use explicit "0 solid" (mPDF quirk: "none" sometimes ignored). --}}
                <tr class="totals-row totals-first">
                    <td style="border-left: 0 solid transparent; border-right: 0 solid transparent; border-bottom: 0 solid transparent; border-top: 1px solid #000;">&nbsp;</td>
                    <td style="border-left: 0 solid transparent; border-right: 0 solid transparent; border-bottom: 0 solid transparent; border-top: 1px solid #000;">&nbsp;</td>
                    <td style="border-left: 0 solid transparent; border-right: 0 solid transparent; border-bottom: 0 solid transparent; border-top: 1px solid #000;">&nbsp;</td>
                    <td style="border-left: 0 solid transparent; border-right: 0 solid transparent; border-bottom: 0 solid transparent; border-top: 1px solid #000;">&nbsp;</td>
                    <td class="text-end"><b>รวมเงิน</b></td>
                    <td class="text-end">{{ number_format((float)$invoice->subtotal, 2) }}</td>
                </tr>
                <tr class="totals-row">
                    <td style="border: 0 solid transparent;">&nbsp;</td>
                    <td style="border: 0 solid transparent;">&nbsp;</td>
                    <td style="border: 0 solid transparent;">&nbsp;</td>
                    <td style="border: 0 solid transparent;">&nbsp;</td>
                    <td class="text-end"><b>ส่วนลด</b></td>
                    <td class="text-end">{{ (float)$invoice->discount_amount > 0 ? number_format((float)$invoice->discount_amount, 2) : '' }}</td>
                </tr>
                <tr class="totals-row">
                    <td style="border: 0 solid transparent;">&nbsp;</td>
                    <td style="border: 0 solid transparent;">&nbsp;</td>
                    <td style="border: 0 solid transparent;">&nbsp;</td>
                    <td style="border: 0 solid transparent;">&nbsp;</td>
                    <td class="text-end"><b>เงินหลังหักส่วนลด</b></td>
                    <td class="text-end">{{ number_format($netAfterDiscount, 2) }}</td>
                </tr>
                @if($isVat)
                    <tr class="totals-row">
                        <td style="border: 0 solid transparent;">&nbsp;</td>
                        <td style="border: 0 solid transparent;">&nbsp;</td>
                        <td style="border: 0 solid transparent;">&nbsp;</td>
                        <td style="border: 0 solid transparent;">&nbsp;</td>
                        <td class="text-end"><b>ภาษีมูลค่าเพิ่ม 7%</b></td>
                        <td class="text-end">{{ number_format((float)$invoice->vat_amount, 2) }}</td>
                    </tr>
                @endif
                <tr class="totals-row totals-last">
                    <td style="border: 0 solid transparent;">&nbsp;</td>
                    <td style="border: 0 solid transparent;">&nbsp;</td>
                    <td style="border: 0 solid transparent;">&nbsp;</td>
                    <td style="border: 0 solid transparent;">&nbsp;</td>
                    <td class="text-end"><b>ยอดเงินสุทธิ</b></td>
                    <td class="text-end"><b>{{ number_format((float)$invoice->total, 2) }}</b></td>
                </tr>
            @endif
        </tbody>
    </table>

    {{-- ===== Amount in words + Signatures (LAST page only) ===== --}}
    @if($chunkIndex === $totalPages - 1)
        <div class="fs-10" style="margin-top: 6px !important;">
            <b>จำนวนเงินรวมทั้งสิ้น (ตัวอักษร)</b> &nbsp; ({{ $bahtText }})
        </div>

        <table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 18px !important;">
            <tr>
                <td width="50%" class="text-center fs-10">
                    <div class="sig-line">&nbsp;</div>
                    <br>
                    ลงชื่อ..........................................ผู้รับสินค้า
                </td>
                <td width="50%" class="text-center fs-10">
                    <div class="sig-line">&nbsp;</div>
                    <br>
                    ลงชื่อ..........................................ผู้รับเงิน
                </td>
            </tr>
        </table>
    @endif

    {{-- Page footer (page number) --}}
    @if($totalPages > 1)
        <div style="text-align: right; margin-top: 4px !important;" class="fs-9">
            หน้า {{ $chunkIndex + 1 }}/{{ $totalPages }}
        </div>
    @endif

    @if($chunkIndex < $totalPages - 1)
        <pagebreak />
    @endif
@endforeach

</body>
</html>
