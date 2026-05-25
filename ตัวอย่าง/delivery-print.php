<?php

namespace App\Services;

use Mpdf\Mpdf;
use App\Models\Orders\OrderDeliverysModel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\Logo\LogoInterface;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

class DeliveryPdfService
{
    protected $delivery;
    protected $mpdf;

    public function __construct(OrderDeliverysModel $delivery)
    {
        $this->delivery = $delivery->load(['order.customer', 'deliveryItems.orderItem.product', 'deliveryAddress']);
        
        // กำหนดค่า mPDF
        $this->mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 15,
            'margin_bottom' => 40,
            'margin_header' => 5,
            'margin_footer' => 25,
            'default_font' => 'thsarabunnew',
            'fontDir' => [public_path('fonts')],
            'fontdata' => [
                'thsarabunnew' => [
                    'R' => 'THSarabunNew.ttf',
                    'B' => 'THSarabunNew Bold.ttf',
                    'I' => 'THSarabunNew Italic.ttf',
                    'BI' => 'THSarabunNew BoldItalic.ttf'
                ]
            ]
        ]);
        
        // กำหนด CSS
        $this->mpdf->WriteHTML($this->getCSS(), 1);
        
        // ตั้งค่า Footer ให้อยู่ล่างสุดเสมอ (รวมหมายเหตุ)
        $footerHtml = '
        <div style="font-size: 20px; margin-top: 20px; padding-top: 10px;">
            <span><b>หมายเหตุ :</b></span>
            <span>' . ($this->delivery->order_delivery_note ?? '') . '</span>
        </div>

        <hr style="margin: 15px 0;">
        <div style="font-size: 20px; margin-bottom: 0px; padding-top: 10px;">
            <span><b>หมายเหตุการรับสินค้า :</b></span>
            <span>กรุณาตรวจสอบความถูกต้องของสินค้าและเซ็นรับสินค้าในวันที่ได้รับ หากไม่มีการตรวจสอบหรือเซ็นรับสินค้า
                ทางบริษัทขอสงวนสิทธิ์ในการรับผิดชอบต่อความผิดพลาดทุกกรณี</span>
        </div>
        <table style="width: 100%; font-size: 16pt; border: none; margin-top: 50px;">
            <tr>
                <td style="width: 50%; vertical-align: top; padding-right: 20px; border: none; padding-top: 20px;">
                    <p><strong>ลงชื่อผู้รับสินค้า...................................................ผู้รับสินค้า</strong></p>
                </td>
                <td style="width: 50%; vertical-align: top; text-align: right; border: none; padding-top: 20px;">
                    <p><strong>ลงชื่อผู้ส่งสินค้า...................................................ผู้ส่งสินค้า</strong></p>
                </td>
            </tr>
        </table>';
        $this->mpdf->SetHTMLFooter($footerHtml);
    }

    public function generatePDF($copies = null)
    {
        try {
            if (!$copies) {
                $copies = ['', '', '', ''];
                //$copies = [''];
            }

            $totalCopies = count($copies);
            
            foreach ($copies as $copyIndex => $copyName) {
                $chunks = $this->delivery->deliveryItems->chunk(8);
                
                foreach ($chunks as $chunkIndex => $chunk) {
                    $isLastPage = ($copyIndex === $totalCopies - 1) && ($chunkIndex === $chunks->count() - 1);
                    $showPrice = ($copyIndex >= 2); // แสดงราคาในหน้า 3 และ 4
                    
                    $html = $this->generatePageHTML($chunk, $copyName, $copyIndex + 1, $totalCopies, $showPrice, $isLastPage);
                    
                    if ($copyIndex > 0 || $chunkIndex > 0) {
                        $this->mpdf->AddPage();
                    }
                    
                    $this->mpdf->WriteHTML($html, 2);
                }
            }
            
            return $this->mpdf;
        } catch (\Exception $e) {
            Log::error('PDF Generation failed: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            throw new \Exception('เกิดข้อผิดพลาดในการสร้าง PDF: ' . $e->getMessage());
        }
    }

    protected function generateQrCode()
    {
        try {
            // สร้าง QR Code ด้วย Endroid QR Code
            $qrText = $this->delivery->order_delivery_number;
            
            Log::info('Attempting to generate QR Code for: ' . $qrText);
            
            // ใช้ Endroid QR Code Builder
            $result = Builder::create()
                ->writer(new PngWriter())
                ->data($qrText)
                ->encoding(new Encoding('UTF-8'))
                ->errorCorrectionLevel(ErrorCorrectionLevel::Low)
                ->size(200)
                ->margin(2)
                ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
                ->build();
            
            $qrCodeData = $result->getString();
            
            Log::info('QR Code generated successfully, length: ' . strlen($qrCodeData));
            
            if ($qrCodeData && strlen($qrCodeData) > 0) {
                return 'data:image/png;base64,' . base64_encode($qrCodeData);
            } else {
                throw new \Exception('QR Code generation returned empty data');
            }
            
        } catch (\Exception $e) {
            // Log error สำหรับ debug
            Log::error('QR Code generation failed: ' . $e->getMessage());
            Log::error('QR Text: ' . ($qrText ?? 'undefined'));
            Log::error('Exception trace: ' . $e->getTraceAsString());
            
            // ลองใช้วิธี fallback
            Log::info('Falling back to alternative QR generation method');
            return $this->generateQrCodeFallback();
        }
    }
    
    protected function generateQrCodeFallback()
    {
        try {
            // วิธี fallback ใช้ Google Charts API (ที่เชื่อถือได้)
            $qrText = urlencode($this->delivery->order_delivery_number);
            $qrUrl = "https://chart.googleapis.com/chart?chs=80x80&cht=qr&chl=" . $qrText;
            
            // ลองดึงภาพจาก Google Charts
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'method' => 'GET',
                    'header' => 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]
            ]);
            
            $qrImageData = @file_get_contents($qrUrl, false, $context);
            
            if ($qrImageData !== false && strlen($qrImageData) > 100) {
                return 'data:image/png;base64,' . base64_encode($qrImageData);
            } else {
                throw new \Exception('Google Charts QR service failed or returned invalid data');
            }
            
        } catch (\Exception $e) {
            Log::error('QR Code fallback failed: ' . $e->getMessage());
            // วิธีสุดท้าย - สร้าง QR Code แบบง่ายๆ ด้วย PHP
            return $this->generateSimpleQrCode();
        }
    }
    
    protected function generateSimpleQrCode()
    {
        try {
            // สร้าง QR Code pattern แบบง่ายๆ (mock QR code)
            // ใช้สำหรับการแสดงผลเท่านั้น
            $svg = '<svg width="80" height="80" xmlns="http://www.w3.org/2000/svg">
                        <rect width="80" height="80" fill="#fff" stroke="#000" stroke-width="1"/>
                        <!-- QR Pattern simulation -->
                        <rect x="5" y="5" width="8" height="8" fill="#000"/>
                        <rect x="15" y="5" width="2" height="2" fill="#000"/>
                        <rect x="19" y="5" width="2" height="2" fill="#000"/>
                        <rect x="23" y="5" width="8" height="8" fill="#000"/>
                        <rect x="67" y="5" width="8" height="8" fill="#000"/>
                        
                        <rect x="5" y="13" width="2" height="2" fill="#000"/>
                        <rect x="9" y="13" width="2" height="2" fill="#000"/>
                        <rect x="15" y="13" width="2" height="2" fill="#000"/>
                        <rect x="67" y="13" width="2" height="2" fill="#000"/>
                        <rect x="71" y="13" width="2" height="2" fill="#000"/>
                        
                        <rect x="5" y="67" width="8" height="8" fill="#000"/>
                        <rect x="15" y="67" width="2" height="2" fill="#000"/>
                        <rect x="19" y="67" width="2" height="2" fill="#000"/>
                        <rect x="23" y="67" width="2" height="2" fill="#000"/>
                        
                        <!-- Center pattern -->
                        <rect x="35" y="35" width="10" height="10" fill="#000"/>
                        <rect x="37" y="37" width="6" height="6" fill="#fff"/>
                        <rect x="39" y="39" width="2" height="2" fill="#000"/>
                        
                        <!-- Random dots for QR pattern -->
                        <rect x="25" y="15" width="2" height="2" fill="#000"/>
                        <rect x="29" y="17" width="2" height="2" fill="#000"/>
                        <rect x="33" y="19" width="2" height="2" fill="#000"/>
                        <rect x="51" y="15" width="2" height="2" fill="#000"/>
                        <rect x="55" y="17" width="2" height="2" fill="#000"/>
                        <rect x="59" y="19" width="2" height="2" fill="#000"/>
                        
                        <!-- Info text -->
                        <text x="40" y="60" text-anchor="middle" font-size="6" fill="#666">ID:' . $this->delivery->id . '</text>
                    </svg>';
            
            return 'data:image/svg+xml;base64,' . base64_encode($svg);
            
        } catch (\Exception $e) {
            Log::error('Simple QR Code generation failed: ' . $e->getMessage());
            // Return basic placeholder สุดท้าย
            return $this->generatePlaceholderQrCode();
        }
    }

    protected function generatePlaceholderQrCode()
    {
        // สร้าง placeholder QR Code เป็น SVG
        $svg = '<svg width="80" height="80" xmlns="http://www.w3.org/2000/svg" style="border: 1px solid #000;">
                    <rect width="80" height="80" fill="#f8f9fa"/>
                    <text x="40" y="35" text-anchor="middle" font-size="8" fill="#666">QR Code</text>
                    <text x="40" y="50" text-anchor="middle" font-size="6" fill="#666">ID: ' . $this->delivery->id . '</text>
                </svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    protected function generatePageHTML($items, $copyName, $pageNumber, $totalPages, $showPrice, $isLastPage)
    {
        // ตรวจสอบข้อมูลพื้นฐานก่อน
        if (!$this->delivery || !$this->delivery->order || !$this->delivery->order->customer) {
            throw new \Exception('ข้อมูล delivery หรือ customer ไม่ครบถ้วน');
        }
        
        $isCompleteDelivery = $this->delivery->isCompleteDelivery();
        $qrCodeImage = $this->generateQrCode();
        
        $html = '
        <div class="page-content">
            <!-- Header Section -->
            <div class="header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div style="width: 65%; float: left;">
                    <h1 style="margin: 0; font-size: 18pt; font-weight: bold;"><b>ใบส่งสินค้า/delivery</b></h1>
                    <p style="margin: 5px 0 0 0; font-size: 14pt; color: #000;"><strong>' . $copyName . '</strong></p>
                </div>
                <div style="text-align: right; padding: 0; margin: 0; width: 35%;">
                    <p style="margin: 0; font-size: 12pt;">
                        <strong>หน้า/ที่:</strong> ' . $pageNumber . '/' . $totalPages . '
                    </p>
                    <p style="margin: 0; padding: 0; font-size: 12pt;">
                        <strong>น้ำหนักรวม:</strong> 759 Kgs.
                    </p>
                </div>
                <div style="clear: both;"></div>
            </div>

            <!-- Document Info Table -->
            <table style="width: 100%; border-collapse: collapse; font-size: 14pt; margin-bottom: 10px;">
                <tr>
                    <td style="border: 1px solid #000; padding: 8px; text-align: left; width: 60%; vertical-align: top;">
                        <div><b>ชื่อลูกค้า :</b> ' . $this->delivery->order->customer->customer_name . '</div>
                        <div><b>ที่อยู่จัดส่ง :</b> ';
                            
        if ($this->delivery->deliveryAddress) {
            $html .= $this->delivery->deliveryAddress->delivery_address;
        } else {
            $html .= $this->delivery->order->customer->customer_address;
        }
        
        $html .= '</div>
                        <div><b>ชื่อผู้ติดต่อ:</b> ';
                            
        if ($this->delivery->deliveryAddress) {
            $html .= $this->delivery->deliveryAddress->delivery_contact_name;
        } else {
            $html .= $this->delivery->order->customer->customer_contract_name;
        }
        
        $html .= '</div>
                        <div><b>เบอร์ติดต่อ:</b> ';
                            
        if ($this->delivery->deliveryAddress) {
            $html .= $this->delivery->deliveryAddress->delivery_phone;
        } else {
            $html .= $this->delivery->order->customer->customer_phone;
        }
        
        $html .= '</div>
                    </td>
                    <td style="border: 1px solid #000; padding: 8px; text-align: left; width: 30%; vertical-align: top;">
                        <div><b>วันที่จัดส่ง :</b> ' . date('d/m/Y', strtotime($this->delivery->order_delivery_date)) . '</div>
                        <div><b>เลขที่บิลหลัก :</b> ' . $this->delivery->order->order_number . '</div>
                        <div><b>เลขที่บิลย่อย :</b> ' . $this->delivery->order_delivery_number . '</div>
                        <div><b>สถานะ :</b> ' . ($isCompleteDelivery ? 'ส่งครบ' : 'ยังไม่ครบ') . '</div>
                    </td>
                    <td style="border: 1px solid #000; padding: 8px; text-align: center; width: 10%; vertical-align: middle;">
                        <div style="margin-bottom: 10px;">
                            <img src="' . $qrCodeImage . '" style="width: 70px; height: 70px;" />
                        </div>
                        <div style="font-size: 12pt;">
                            <b>Billno :</b> ' . ($this->delivery->prints()->count() + 1) . '
                        </div>
                    </td>
                </tr>
            </table>



            <!-- Items Table -->
            <table style="width: 100%; border: 1px solid #000; border-collapse: collapse; font-size: 14pt; margin-bottom: 20px;">
                <thead>
                    <tr style="background-color: #f0f0f0;">
                        <th style="border-left: 1px solid #000; border-right: 1px solid #000; border-bottom: 1px solid #000; border-top: 1px solid #000; padding: 8px; text-align: center; width: 8%;">ลำดับ</th>
                        <th style="border-left: 1px solid #000; border-right: 1px solid #000; border-bottom: 1px solid #000; border-top: 1px solid #000; padding: 8px; text-align: center; width: 10%;">จำนวน</th>
                        <th style="border-left: 1px solid #000; border-right: 1px solid #000; border-bottom: 1px solid #000; border-top: 1px solid #000; padding: 8px; text-align: center; width: 12%;">หน่วยนับ</th>
                        <th style="border-left: 1px solid #000; border-right: 1px solid #000; border-bottom: 1px solid #000; border-top: 1px solid #000; padding: 8px; text-align: center;">รายการสินค้า</th>';
                        
        if ($showPrice) {
            $html .= '
                        <th style="border-left: 1px solid #000; border-right: 1px solid #000; border-bottom: 1px solid #000; border-top: 1px solid #000; padding: 8px; text-align: center; width: 15%;">ราคาต่อหน่วย</th>
                        <th style="border-left: 1px solid #000; border-right: 1px solid #000; border-bottom: 1px solid #000; border-top: 1px solid #000; padding: 8px; text-align: center; width: 15%;">จำนวน</th>';
        }
        
        $html .= '
                    </tr>
                </thead>
                <tbody>';
                
        // เพิ่มรายการสินค้า
        foreach ($items as $key => $item) {
            $html .= '
                    <tr>
                        <td style="border: none; padding: 6px; text-align: center;">' . ($key + 1) . '</td>
                        <td style="border: none; padding: 6px; text-align: center;">' . $item->quantity . '</td>
                        <td style="border: none; padding: 6px; text-align: center;">' . ($item->orderItem->product_unit ?? '') . '</td>
                        <td style="border: none; padding: 6px; text-align: left;">' . ($item->orderItem->product_name ?? '');
                        
            // ตรวจสอบว่าเป็นสินค้าที่ต้องแสดงความยาวหรือไม่ (ไม่ใช่บริการ)
            if ($item->orderItem->product_length && 
                $item->orderItem->product_unit !== 'บริการ' && 
                !str_contains(strtolower($item->orderItem->product_name ?? ''), 'บริการ') &&
                !str_contains(strtolower($item->orderItem->product_name ?? ''), 'ค่าบริการ')) {
                $html .= ' ' . $item->orderItem->product_length . ' ' . ($item->orderItem->productMeasure?->value ?? 'เมตร');
            }
            
            if (isset($item->orderItem->product) && $item->orderItem->product->productWireType?->value) {
                $html .= ' ' . $item->orderItem->product->productWireType->value;
            }
            
            // เพิ่มหมายเหตุสินค้า
            if ($item->product_note) {
                $html .= '<br><span style="font-size: 12pt; color: #666;">[หมายเหตุ: ' . $item->product_note . ']</span>';
            }
            
            $html .= '</td>';
            
            if ($showPrice) {
                $html .= '
                        <td style="border: none; padding: 6px; text-align: right;">' . number_format($item->unit_price, 2) . '</td>
                        <td style="border: none; padding: 6px; text-align: right;">' . number_format($item->total, 2) . '</td>';
            }
            
            $html .= '</tr>';
        }
        
        // เพิ่มแถวว่างให้ครบ 7 แถว (ไม่ใส่เส้นล่าง)
        $emptyRows = 7 - count($items);
        for ($i = 1; $i <= $emptyRows; $i++) {
            $html .= '
                    <tr>
                        <td style="border: none; padding: 6px; text-align: center; color: #fff;">' . (count($items) + $i) . '</td>';
            
            if ($showPrice) {
                $html .= '<td style="border: none; padding: 6px;" colspan="5">&nbsp;</td>';
            } else {
                $html .= '<td style="border: none; padding: 6px;" colspan="3">&nbsp;</td>';
            }
            
            $html .= '</tr>';
        }
        
        // เพิ่มสรุปราคา (เฉพาะเมื่อแสดงราคา)
        if ($showPrice) {
            $html .= '
                    <tr>
                        <td colspan="4" style="border: none;"></td>
                        <td style="border: 1px solid #000; padding: 6px; text-align: right; background-color: #f0f0f0; width: 20%; white-space: nowrap;"><strong>ราคาก่อนภาษี:</strong></td>
                        <td style="border: 1px solid #000; padding: 6px; text-align: right; width: 15%;">' . number_format($this->delivery->order_delivery_subtotal, 2) . '</td>
                    </tr>
                    <tr>
                        <td colspan="4" style="border: none;"></td>
                        <td style="border: 1px solid #000; padding: 6px; text-align: right; background-color: #f0f0f0; white-space: nowrap;"><strong>ส่วนลด:</strong></td>
                        <td style="border: 1px solid #000; padding: 6px; text-align: right;">' . number_format($this->delivery->order_delivery_discount, 2) . '</td>
                    </tr>
                    <tr>
                        <td colspan="4" style="border: none;"></td>
                        <td style="border: 1px solid #000; padding: 6px; text-align: right; background-color: #f0f0f0; white-space: nowrap;"><strong>จำนวนหลังหักส่วนลด:</strong></td>
                        <td style="border: 1px solid #000; padding: 6px; text-align: right;">' . number_format($this->delivery->order_delivery_subtotal - $this->delivery->order_delivery_discount, 2) . '</td>
                    </tr>
                    <tr>
                        <td colspan="4" style="border: none;"></td>
                        <td style="border: 1px solid #000; padding: 6px; text-align: right; background-color: #f0f0f0; white-space: nowrap;"><strong>ภาษีมูลค่าเพิ่ม:</strong></td>
                        <td style="border: 1px solid #000; padding: 6px; text-align: right;">' . number_format($this->delivery->order_delivery_vat, 2) . '</td>
                    </tr>
                    <tr>
                        <td colspan="4" style="border: none;"></td>
                        <td style="border: 1px solid #000; padding: 6px; text-align: right; background-color: #f0f0f0; white-space: nowrap;"><strong>จำนวนเงินทั้งสิ้น:</strong></td>
                        <td style="border: 1px solid #000; padding: 6px; text-align: right; font-weight: bold;">' . number_format($this->delivery->order_delivery_grand_total, 2) . '</td>
                    </tr>';
        }
        
        $html .= '
                </tbody>
            </table>';

        // หมายเหตุ
        // $html .= '
        // <div style="font-size: 20px;">
        //     <br>
        //     <span><b>หมายเหตุ :</b></span>
        //     <span>' . ($this->delivery->order_delivery_note ?? '') . '</span>
        // </div>
        // <hr>';

        // Footer - ย้ายไปใช้ SetHTMLFooter แล้ว (รวมหมายเหตุ)
        $html .= '
        </div>';

        return $html;
    }

    protected function getCSS()
    {
        return '
        <style>
            @font-face {
                font-family: "thsarabunnew";
                src: url("' . public_path('fonts/THSarabunNew.ttf') . '");
            }
            
            body {
                font-family: "thsarabunnew", Arial, sans-serif;
                font-size: 14pt;
                line-height: 1.4;
                margin: 0;
                padding: 15px;
                color: #000;
                background: #fff;
            }
            
            .header {
                margin-bottom: 15px;
                width: 100%;
            }
            
            h1 {
                font-size: 18pt;
                font-weight: bold;
                margin: 0;
                padding: 0;
                color: #000;
            }
            
            .page-content {
                width: 100%;
                margin: 0;
                padding: 0;
            }
            
            table {
                border-collapse: collapse;
                width: 100%;
            }
            
            th {
                border: 1px solid #000;
                padding: 8px;
                background-color: #f0f0f0;
                font-weight: bold;
                text-align: center;
                font-size: 14pt;
            }
            
            td {
                border: 1px solid #000;
                padding: 6px;
                text-align: left;
                font-size: 14pt;
                vertical-align: top;
            }
            
            hr {
                border: none;
                border-top: 1px solid #000;
                margin: 15px 0;
                width: 100%;
            }
            
            strong, b {
                font-weight: bold;
            }
            
            .text-center {
                text-align: center;
            }
            
            .text-right {
                text-align: right;
            }
            
            div {
                margin: 2px 0;
                line-height: 1.3;
            }
            
            p {
                margin: 8px 0;
                line-height: 1.3;
            }
        </style>';
    }

    public function output($filename = null, $destination = 'I')
    {
        if (!$filename) {
            $filename = 'delivery-' . $this->delivery->order_delivery_number . '.pdf';
        }
        
        return $this->mpdf->Output($filename, $destination);
    }

    public function save($path)
    {
        return $this->mpdf->Output($path, 'F');
    }
}