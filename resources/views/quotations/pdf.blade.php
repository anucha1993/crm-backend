<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ใบเสนอราคา {{ $quotation->quotation_number }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @font-face {
            font-family: 'THSarabunNew';
            font-style: normal;
            font-weight: normal;
            src: url('{{ asset('fonts/THSarabunNew.ttf') }}') format('truetype');
        }
        @font-face {
            font-family: 'THSarabunNew';
            font-style: normal;
            font-weight: bold;
            src: url('{{ asset('fonts/THSarabunNew Bold.ttf') }}') format('truetype');
        }
        @font-face {
            font-family: 'THSarabunNew';
            font-style: italic;
            font-weight: normal;
            src: url('{{ asset('fonts/THSarabunNew Italic.ttf') }}') format('truetype');
        }
        @font-face {
            font-family: 'THSarabunNew';
            font-style: italic;
            font-weight: bold;
            src: url('{{ asset('fonts/THSarabunNew BoldItalic.ttf') }}') format('truetype');
        }

        body {
            font-family: 'THSarabunNew', 'Sarabun', sans-serif;
            font-size: 15pt;
            color: #000;
            background: #fff;
            word-break: keep-all;
            overflow-wrap: break-word;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        p, h3, h4, h6, address, span, small, b, strong, td, th, div, .clearfix {
            margin-top: 0 !important;
            margin-bottom: 0 !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
            line-height: 1.1 !important;
        }

        .card {
            border: none;
        }

        .table th, .table td {
            padding-top: 2px !important;
            padding-bottom: 2px !important;
            border: 1px solid #dee2e6 !important;
            font-size: 14pt;
        }

        .row, .col-6, .col-sm-6, .col-sm-4, .col-sm-12, .col-12 {
            margin-top: 0 !important;
            margin-bottom: 0 !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
        }

        .text-center { text-align: center !important; }
        .text-end    { text-align: right  !important; }

        .page-wrap {
            width: 210mm;
            min-height: 297mm;
            padding: 8mm 10mm;
            margin: 0 auto;
            box-sizing: border-box;
            position: relative;
            background: #fff;
            word-break: keep-all;
        }
        .page-wrap + .page-wrap { page-break-before: always; break-before: page; }

        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 90pt;
            color: rgba(220, 38, 38, 0.12);
            font-weight: bold;
            pointer-events: none;
            z-index: 9999;
            white-space: nowrap;
        }

        .fs-11 { font-size: 11pt; }
        .fs-12 { font-size: 12pt; }
        .fs-13 { font-size: 13pt; }
        .fs-14 { font-size: 14pt; }
        .fs-16 { font-size: 16pt; }
        .fs-18 { font-size: 18pt; }
        .fs-20 { font-size: 20pt; }

        .qr-img { height: 100px; width: 100px; }

        @media print {
            html, body { width: 210mm; margin: 0 !important; padding: 0 !important; background: #fff !important; }
            .page-wrap { width: 210mm !important; min-height: 0 !important; max-width: 210mm !important; margin: 0 !important; padding: 8mm 10mm !important; box-shadow: none !important; }
            .page-wrap + .page-wrap { page-break-before: always !important; break-before: page !important; }
            .no-print { display: none !important; }
        }

        @media screen {
            body { background: #e5e7eb; }
            .page-wrap { box-shadow: 0 0 6px rgba(0,0,0,0.15); margin: 12px auto; }
        }

        @page {
            size: 210mm 297mm;
            margin: 0;
        }
    </style>
</head>
<body>

@php
    $perPage = 8;
    $itemsCollection = $quotation->items;
    $chunks = $itemsCollection->chunk($perPage)->values();
    if ($chunks->isEmpty()) {
        $chunks = collect([collect()]);
    }
    $totalPages = $chunks->count();
    $loopIndex = 1;
@endphp

@if(!empty($watermark))
    <div class="watermark">{{ $watermark }}</div>
@endif

@foreach($chunks as $chunkIndex => $chunk)
<div class="page-wrap">
    <div class="card text-black">
        <div class="card-body p-0">

            {{-- ===== Header: Logo + Title | QR + Page ===== --}}
            <div class="clearfix">
                <div class="float-start">
                    @if($logoDataUri)
                        <img src="{{ $logoDataUri }}" alt="logo" height="60" class="mb-1">
                    @endif
                    <h3 class="m-0 mb-1"><b>Quotation / ใบเสนอราคา</b></h3>
                </div>
                <div class="float-end text-end">
                    <img src="{{ $qrDataUri }}" alt="QR" class="qr-img"><br>
                    <small>หน้า {{ $chunkIndex + 1 }}/{{ $totalPages }}@if($quotation->revision_number > 0) <span style="color:#2563eb; font-weight:bold;">(Rev.{{ str_pad($quotation->revision_number, 2, '0', STR_PAD_LEFT) }})</span>@endif</small>
                </div>
            </div>

            {{-- ===== Company + Document Meta ===== --}}
            <div class="row text-black mt-1">
                <div class="col-sm-6">
                    <div class="float-start">
                        <p><b>{{ $company['name'] ?? 'บริษัท' }}@if(!empty($company['branch'])) ({{ $company['branch'] }})@endif</b></p>
                        <p class="fs-14" style="margin-top: -2px">
                            @if(!empty($company['address']))ที่อยู่ {{ $company['address'] }}@endif
                            @if(!empty($company['phone'])) โทร {{ $company['phone'] }}@endif
                            @if(!empty($company['tax_id']))<br>เลขประจำตัวผู้เสียภาษี {{ $company['tax_id'] }}@endif
                        </p>
                    </div>
                </div>
                <div class="col-sm-4 offset-sm-2">
                    <div class="float-sm-end mt-0">
                        <p class="fs-14"><strong>วันที่เสนอราคา:</strong> &nbsp; {{ $createdDate }}</p>
                        <p class="fs-14"><strong>เลขที่ใบเสนอราคา:</strong> {{ $quotation->quotation_number }}</p>
                        @if($quotation->valid_until)
                            @php $isExpired = \Carbon\Carbon::parse($quotation->valid_until)->endOfDay()->isPast(); @endphp
                            <p class="fs-14"><strong>ยืนราคาถึง:</strong> {{ \Carbon\Carbon::parse($quotation->valid_until)->locale('th')->translatedFormat('d M Y') }}@if($isExpired) <span style="color:#dc2626; font-weight:bold;">(เลยกำหนด)</span>@endif</p>
                        @endif
                        @if($quotation->creator)
                            <p class="fs-14"><strong>ชื่อผู้ขาย (Sale):</strong> {{ $quotation->creator->name }}</p>
                        @endif
                    </div>
                </div>
            </div>

            <hr>

            {{-- ===== Customer + Shipping ===== --}}
            <div class="row mt-1">
                <div class="col-6">
                    <h3 class="fs-18">ข้อมูลลูกค้า</h3>
                    <address class="fs-14">
                        {{ $quotation->customer->name ?? '-' }}<br>
                        @if($quotation->customer?->address){{ $quotation->customer->address }}<br>@endif
                        @if($quotation->customer?->phone)โทร {{ $quotation->customer->phone }}@endif
                        @if($quotation->customer?->tax_id)<br>เลขประจำตัวผู้เสียภาษี {{ $quotation->customer->tax_id }}@endif
                    </address>
                </div>
                <div class="col-6">
                    <h3 class="fs-18">ที่อยู่จัดส่ง</h3>
                    <address class="fs-14">
                        @if($quotation->shippingAddress)
                            @if($quotation->shippingAddress->contact_name || $quotation->shippingAddress->phone)
                                {{ $quotation->shippingAddress->contact_name ?? '' }}@if($quotation->shippingAddress->phone) ({{ $quotation->shippingAddress->phone }})@endif<br>
                            @endif
                            {{ $quotation->shippingAddress->address }}
                        @else
                            {{ $quotation->customer->name ?? '' }}<br>
                            @if($quotation->customer?->address){{ $quotation->customer->address }}<br>@endif
                            @if($quotation->customer?->phone)(+66) {{ $quotation->customer->phone }}@endif
                        @endif
                    </address>
                </div>
            </div>

            {{-- ===== Items Table ===== --}}
            <div class="row mt-2">
                <div class="col-12">
                    <table class="table table-sm border mb-0 mt-0">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 5%; white-space: nowrap;">ลำดับ</th>
                                <th class="text-center" style="width: 7%; white-space: nowrap;">จำนวน</th>
                                <th class="text-center" style="width: 8%; white-space: nowrap;">หน่วยนับ</th>
                                <th style="width: 36%;" class="text-center">รายการสินค้า</th>
                                <th class="text-center" style="width: 13%; white-space: nowrap;">ความยาว</th>
                                <th class="text-center" style="width: 14%; white-space: nowrap;">ราคาต่อหน่วย</th>
                                <th class="text-end" style="width: 17%; white-space: nowrap;">จำนวนเงินรวม</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($chunk as $item)
                                @php
                                    $rawUnit = trim((string)($item->unit ?? ''));
                                    $productUnit = trim((string)($item->product->unit ?? ''));
                                    $isSheet = $rawUnit === 'แผ่น' || $productUnit === 'แผ่น';
                                    $displayUnit = $rawUnit;
                                    $lengthUnitRaw = $item->product?->sizes?->first()?->length_unit ?? '';
                                    $thickness = (float)($item->thickness ?? 0);
                                    $totalArea = ($isSheet && $thickness > 0 && (float)$item->length > 0)
                                        ? (float)$item->quantity * $thickness * (float)$item->length
                                        : null;
                                    $pricePerPiece = ($isSheet && $thickness > 0 && (float)$item->length > 0)
                                        ? $thickness * (float)$item->unit_price * (float)$item->length
                                        : null;
                                @endphp
                                <tr>
                                    <td class="text-center">{{ $loopIndex++ }}</td>
                                    <td class="text-center">{{ number_format((float)$item->quantity) }}</td>
                                    <td class="text-center">{{ $displayUnit }}</td>
                                    <td>
                                        <b>{{ $item->product?->name }}</b>
                                        @if($totalArea !== null)
                                            ({{ number_format($totalArea, 2) }}/ตรม.)
                                        @else
                                            ({{ number_format((float)$item->unit_price, 2) }}/{{ $lengthUnitRaw }})
                                        @endif
                                        @if($thickness > 0)
                                            <br>ความหนา: {{ number_format($thickness, 2) }}@if($item->product?->thickness_unit) {{ $item->product->thickness_unit }}@endif
                                        @endif
                                        @if(!empty($item->product?->steel_type))
                                            <br>ลวด: {{ $item->product->steel_type }}
                                        @endif
                                        @if($item->description)
                                            <br>{{ $item->description }}
                                        @endif
                                    </td>
                                    <td class="text-center">{{ $item->length ? number_format((float)$item->length, 2) . ' ' . $lengthUnitRaw : '-' }}</td>
                                    <td class="text-center">
                                        @if($pricePerPiece !== null)
                                            {{ number_format($pricePerPiece, 2) }}/แผ่น
                                        @elseif((float)$item->length > 0)
                                            {{ number_format((float)$item->unit_price * (float)$item->length, 2) }}/{{ $displayUnit }}
                                        @else
                                            {{ number_format((float)$item->unit_price, 2) }}/{{ $displayUnit }}
                                        @endif
                                    </td>
                                    <td class="text-end">{{ number_format((float)$item->amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- ===== Totals (only on last page) ===== --}}
            @if($chunkIndex + 1 === $totalPages)
                <br>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="clearfix pt-3">
                            <h6 class="fs-14" style="color:#6b7280;">หมายเหตุ:</h6>
                            <small class="fs-14">{{ $quotation->notes }}</small>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="float-end mt-sm-0">
                            <p><b>จำนวนเงินรวม :</b> <span class="float-end">&nbsp;{{ number_format((float)$quotation->subtotal, 2) }}</span></p>
                            <p><b>ส่วนลด :</b> <span class="float-end">&nbsp;{{ number_format((float)$quotation->discount_amount, 2) }}</span></p>
                            @if($isVat)
                                <p><b>ภาษีมูลค่าเพิ่ม :</b> <span class="float-end">&nbsp;{{ number_format((float)$quotation->vat_amount, 2) }}</span></p>
                            @endif
                            <p><b>จำนวนเงินทั้งสิ้น : &nbsp;</b> <span class="float-end">&nbsp;{{ number_format((float)$quotation->total, 2) }}</span></p>
                            <p class="fs-12" style="color:#6b7280;"><span class="float-end">({{ $bahtText }})</span></p>
                        </div>
                        <div class="clearfix"></div>
                    </div>
                </div>

                <hr>

                <div class="row">
                    <div class="col-sm-6">
                        <div class="mt-sm-0 fs-14">
                            <span>หมายเหตุ: เงื่อนไขการชำระเงิน</span><br>
                            <span>1. โอนก่อนจัดส่งสินค้า</span><br>
                            <span>2. ชำระเป็นเงินสด เมื่อตรวจรับสินค้าเรียบร้อย</span><br>
                            <span>3. รบกวนลูกค้าตรวจสอบรายการสินค้า ก่อนคอนเฟิร์มการสั่งซื้อ หากผิดพลาด ทางบริษัท ขอสงวนสิทธิ์รับผิดชอบทุกกรณี</span><br>
                            <span>4. หากเป็นสินค้าไซต์พิเศษ เมื่อสั่งผลิตแล้ว ไม่สามารถเปลี่ยนแปลงได้</span>
                        </div>
                        <div class="clearfix"></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="float-end text-center clearfix pt-3 fs-14">
                            <span>ผู้เสนอราคา</span><br>
                            @if($quotation->creator)
                                <span>{{ $quotation->creator->name }}</span><br>
                            @endif
                            <span style="color:#9ca3af;">วันที่ {{ $createdDate }}</span>
                        </div>
                    </div>
                </div>
            @endif

            <div class="no-print mt-4 text-center">
                <a href="javascript:window.print()" class="btn btn-danger">พิมพ์ / Print</a>
            </div>

        </div>
    </div>
</div>
@endforeach

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // wait briefly for fonts/images
        setTimeout(() => window.print(), 400);
    });
</script>

</body>
</html>
