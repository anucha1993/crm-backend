<div>
    <style>
        @font-face {
            font-family: 'THSarabunNew';
            font-style: normal;
            font-weight: normal;
            src: url('{{ asset('fonts/THSarabunNew.ttf') }}') format("truetype");
        }

        @font-face {
            font-family: 'THSarabunNew';
            font-style: normal;
            font-weight: bold;
            src: url('{{ asset('fonts/THSarabunNew Bold.ttf') }}') format("truetype");
        }

        @font-face {
            font-family: 'THSarabunNew';
            font-style: italic;
            font-weight: normal;
            src: url('{{ asset('fonts/THSarabunNew Italic.ttf') }}') format("truetype");
        }

        @font-face {
            font-family: 'THSarabunNew';
            font-style: italic;
            font-weight: bold;
            src: url('{{ asset('fonts/THSarabunNew BoldItalic.ttf') }}') format("truetype");
        }

        body {
            font-family: 'THSarabunNew', sans-serif;
            font-size: 15pt;
        }

        p,
        h4,
        h6,
        .fs-20,
        address,
        span,
        small,
        b,
        strong,
        td,
        th,
        div,
        .clearfix {
            margin-top: 0 !important;
            margin-bottom: 0 !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
            line-height: 1.1 !important;
        }

        .table th,
        .table td {
            padding-top: 2px !important;
            padding-bottom: 2px !important;
            border: 1px solid #dee2e6 !important;
        }

        .row,
        .col-6,
        .col-sm-6,
        .col-sm-4,
        .col-sm-12,
        .col-12 {
            margin-top: 0 !important;
            margin-bottom: 0 !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
        }

        .mt-1,
        .mt-0,
        .mb-0,
        .mb-1,
        .pt-3,
        .mt-sm-0,
        .mb-3,
        .mt-4,
        .mb-4,
        .pt-3,
        .pb-3,
        .pt-0,
        .pb-0 {
            margin-top: 0 !important;
            margin-bottom: 0 !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
        }
        .text-center { text-align: center !important; }
    </style>



    @php
        $totalPages = ceil($quotation->items->count() / 8);
        $loopIndex = 1;
    @endphp

    @foreach ($quotation->items->chunk(8) as $chunkIndex => $chunk)
        <div class="card row text-black" style="top: -20px">
            <div class="card-body">
                <!-- Invoice Detail-->
                <div class="clearfix">
                    <div class="float-start">
                        <img src="/images/logo-cmc.png" class="mb-1" alt="dark logo" height="60">
                        <h3 class="m-0 mb-3">Quotation / ใบเสนอราคา</h3>
                    </div>

                    <div class="float-center">
                        {{-- <div class="text-center">
                        <h4 class="m-0 ">Quotation / ใบเสนอราคา</h4>
           
                    </div> --}}

                        <div class="float-end">

                            <img src="{{ route('qr.quotation', $quotation->id) }}" alt="QR"
                                style="height:100px;"><br>
                            <small class="float-end">หน้า {{ $chunkIndex + 1 }}/{{ $totalPages }}</small>
                        </div>

                    </div>

                </div>


                <div class="row text-black">
                    <div class="col-sm-6">
                        <div class="float-start">
                            <p><b>บริษัท เจริญมั่น คอนกรีต จำกัด(สำนักงานใหญ่)</b></p>
                            <p class="fs-30" style="margin-top: -10px">ที่อยู่ 99/35 หมู่ 9 ตำบลละหาร อำเภอบางบัวทอง
                                จังหวัดนนทบุรี 11110 โทร
                                082-4789197 </br>
                                เลขประจำตัวผู้เสียภาษี 0125560015546
                            </p>
                        </div>


                    </div><!-- end col -->
                    <div class="col-sm-4 offset-sm-2 float-end">
                        <div class="mt-0 float-sm-end">
                            <p class="fs-25"><strong>วันที่เสนอราคา: </strong> &nbsp;&nbsp;&nbsp;
                                {{ date('d/m/Y', strtotime($quotation->quote_date)) }}</p>
                            <p class="fs-25"><strong>เลขที่ใบเสนอราคา </strong>{{ $quotation->quote_number }}</p>
                            <p class="fs-25"><strong>ชื่อผู้ขาย (Sale) </strong> <span
                                    class="float-end">{{ $quotation->sale->name }}</span></p>
                        </div>
                    </div><!-- end col -->
                </div>
                <!-- end row -->
                <hr>

                <div class="row mt-1 ">
                    <div class="col-6">
                        <h3 class="fs-50">ข้อมูลลูกค้า</h3>
                        <address>
                            {{ $quotation->customer->customer_name }}<br>
                            {{ $quotation->customer->customer_address }}<br>
                            {{ $quotation->customer->customer_district_name .
                                ' ' .
                                $quotation->customer->customer_amphur_name .
                                ' ' .
                                $quotation->customer->customer_province_name .
                                ' ' .
                                $quotation->customer->customer_zipcode }}<br>
                            {{ $quotation->customer->customer_phone }}
                        </address>
                    </div> <!-- end col-->

                    <div class="col-6">
                        <h3 class="fs-30">ที่อยู่จัดส่ง</h3>
                        <address>
                            @if ($quotation->deliveryAddress)
                                {{ $quotation->deliveryAddress->delivery_contact_name }}
                                ({{ $quotation->deliveryAddress->delivery_phone }})<br>
                                {{ $quotation->deliveryAddress->delivery_address }}<br>
                            @else
                                {{ $quotation->customer->customer_name }}<br>
                                {{ $quotation->customer->customer_address }}<br>
                                {{ $quotation->customer->customer_district_name .
                                    ' ' .
                                    $quotation->customer->customer_amphur_name .
                                    ' ' .
                                    $quotation->customer->customer_province_name .
                                    ' ' .
                                    $quotation->customer->customer_zipcode }}<br>
                                (+66) {{ $quotation->customer->customer_phone }}
                            @endif
                        </address>
                    </div> <!-- end col-->
                </div>
                <!-- end row -->

                <div class="row">
                    <div class="col-12">
                        <div class="table-responsive">
                            <table class="table table-sm table-centered table-hover  mb-0 mt-0 border">
                                <thead class="border-top border-bottom  border-start-1 border-end-1 border-primary">
                                    <tr>
                                        <th>ลำดับ</th>
                                        <th>จำนวน</th>
                                        <th>หน่วยนับ</th>
                                        <th style="width: 30%">รายการสินค้า</th>
                                        <th>ความยาว</th>
                                        <th>ราคาต่อหน่วย</th>
                                        <th class="text-end">จำนวนเงินรวม</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    @foreach ($chunk as $item)
                                        @if ($item->product_unit === 'แผ่น')
                                            @php
                                                $width = $item->quantity; // ที่เก็บในฐานข้อมูล
                                                // $widthInMeter = $width / 10; // → 0.035 เมตร
                                                $widthInMeter = $width*$item->product_length*$item->product_calculation;
                                            @endphp
                                            <tr>
                                                <td class="text-center">{{ $loopIndex++ }}</td>
                                                <td class="text-center">{{ $item->quantity }}</td>

                                                <td class="text-center">{{ $item->product_unit }}</td>

                                                <td>

                                                    <b>{{ $item->product_name }} </b>
                                                   
                                                    ({{ $widthInMeter . '/ตรม.' }}<br />
                                                    <p>{{ 'ความกว้าง:'.$item->product_calculation}}<br /></p>
                                                     
                                                    {{ $item->globalSetValue()?->value ?? '' }}
                                                    @if ($item->product_note)
                                                        <br /> {{ $item->product_note }}
                                                    @endif

                                                </td>
                                                <td class="text-center">{{ $item->product_length }}
                                                    {{ $item->productMeasure?->value ?? '' }}</td>
                                                <td class="text-center">
                                                    {{ number_format(($item->unit_price*$item->product_length*$item->product_calculation), 2) }}/{{ $item->product_unit }}
                                                </td>

                                                <td class="text-end">{{ number_format($item->total, 2) }}</td>
                                            </tr>
                                        @else
                                            <tr>
                                                <td class="text-center">{{ $loopIndex++ }}</td>
                                                <td class="text-center">{{ $item->quantity }}</td>

                                                <td class="text-center">{{ $item->product_unit }}</td>

                                                <td>

                                                    <b>{{ $item->product_name }} </b>
                                                    {{ ($item->product_calculation ?? 1) != 1 ? $item->product_calculation : '' }}
                                                    ({{ number_format($item->unit_price) . '/' . ($item->productMeasure?->value ?? '') }})<br />
                                                    {{ $item->globalSetValue()?->value ?? '' }}
                                                    @if ($item->product_note)
                                                        <br /> {{ $item->product_note }}
                                                    @endif

                                                </td>
                                                <td class="text-center">{{ $item->product_length }}
                                                    {{ $item->productMeasure?->value ?? '' }}</td>
                                                <td>{{ number_format($item->unit_price * $item->product_length, 2) }}/ {{ $item->product_unit }}
                                                </td>
                                                <td class="text-end">{{ number_format($item->total, 2) }}</td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div> <!-- end table-responsive-->
                    </div> <!-- end col -->
                </div>
                <!-- end row -->
                <br>

                <div class="row ">
                    <div class="col-sm-6">
                        <div class="clearfix pt-3">
                            <h6 class="text-muted fs-20">หมายเหตุ:</h6>
                            <small>
                                {{ $quotation->quote_note }}
                            </small>
                        </div>
                    </div> <!-- end col -->
                    <div class="col-sm-6">
                        <div class="float-end mt-sm-0">
                            <p><b>จำนวนเงินรวม :</b> <span
                                    class="float-end">{{ number_format($quotation->quote_subtotal, 2) }}</span></p>
                            <p><b>ส่วนลด:</b> <span
                                    class="float-end">{{ number_format($quotation->quote_discount, 2) }}</span></p>
                            <p><b>ภาษีมูลค่าเพิ่ม:</b> <span
                                    class="float-end">{{ number_format($quotation->quote_vat, 2) }}</span></p>
                            <p><b>จำนวนเงินทั้งสิ้น: &nbsp; </b> <span
                                    class="float-end">{{ number_format($quotation->quote_grand_total, 2) }}</span></p>

                        </div>
                        <div class="clearfix"></div>
                    </div> <!-- end col -->
                </div>
                <!-- end row-->
                <hr>
                <div class="row ">
                    <div class="col-sm-6">
                        <div class=" mt-sm-0">
                            <span>หมายเหตุ:เงื่อนไขการชำระเงิน</span><br>
                            <span>1. โอนก่อนจัดส่งสินค้า</span><br>
                            <span>2. ชำระเป็นเงินสด เมื่อตรวจรับสินค้าเรียบร้อย</span><br>
                        </div>
                        <div class="clearfix"></div>
                    </div> <!-- end col -->
                    <div class="col-sm-6">
                        <div class="float-end text-center clearfix pt-3">
                            <span>ผู้เสนอราคา</span><br>
                            <span>{{ $quotation->sale->name }}</span><br>

                        </div>
                    </div> <!-- end col -->

                </div>

                <div class="d-print-none mt-4">
                    <div class="text-center">
                        <a href="javascript:window.print()" class="btn btn-danger"><i class="ri-printer-line"></i>
                            Print</a>

                    </div>
                </div>
                <!-- end buttons -->

            </div> <!-- end card-body-->
        </div> <!-- end card -->
    @endforeach
    <!-- end row -->
</div>


<script>
    // เรียกเมื่อหน้าโหลดเสร็จ
    document.addEventListener('DOMContentLoaded', () => {

        /** ฟังก์ชันกลับหน้าเดิม */
        const goBack = () => {
            // วิธี A: กลับหน้าเดิม
            history.back();

            // หรือ วิธี B: redirect ไป index โดยตรง
            // location.href = "{{ route('quotations.index') }}";
        };

        /* 1) เรียก dialog พิมพ์ทันที */
        window.print();

        /* 2) หลังกล่องพิมพ์ปิดแล้ว (กดพิมพ์หรือยกเลิก) → เรียก goBack */
        window.addEventListener('afterprint', goBack); // Chrome/Edge
        window.onafterprint = goBack; // Safari/Firefox fallback
    });
</script>
