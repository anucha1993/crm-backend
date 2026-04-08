Authentication
---------------
ตัวอย่าง JavaScript
const axios = require('axios')
let config = {
  method: 'GET',
  url: 'http://{apiUrl}/api/account/info ,
  headers: {
    'Authorization': 'Bearer {secretKey}',
    'Content-Type': 'application/json'
  }
};
axios.request(config).then((response) => }
  console.log(JSON.stringify(response.data))
}).catch((error) => }
  console.log(error)
})

ตัวอย่าง Curl
curl -iL -X POST '{apiUrl}/api/verify-slip/qr-code/info' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer {secretKey}' \
  --json '{"payload":{
    "qrCode":"004100060000000402TH9104xxxx"
  }
}'

API Endpoint : https://connect.slip2go.com/api/verify-slip/qr-image/info

ตรวจสอบสลิปด้วยรูปภาพ
วิธีการใช้งาน
จะต้องระบุ API Secret มากับ Header ทุกครั้ง ที่เรียกใช้งาน API*ดูวิธีใช้งานได้ที่หน้า Authentication
สามารถระบุ IP Whitelist เพื่อกำหนดการเข้าถึง API จาก IP ที่กำหนดได้
หากต้องการตรวจสอบเงื่อนไขสลิป ให้ส่งข้อมูลเฉพาะ Key ที่ต้องการตรวจสอบเท่านั้น*ดูวิธีใช้งานที่หน้า ตัวอย่างการใช้งาน
Request Data (Multipart/Form-data)
key
Type
Description
Example
Required
file
File
File รูปสลิปที่ต้องการตรวจสอบ
slip.png, slip.jpg, slip.jpeg
payload
JSON
ส่งเมื่อต้องการตรวจสอบเงื่อนไขสลิป
{ ...รายละเอียดตามตารางด้านล่าง... }
Request Body (JSON)
key
Type
Description
Example
Required
checkDuplicate
Boolean
Flag สำหรับตรวจสอบสลิปซ้ำ
*ระบุหากต้องการตรวจสอบสลิปซ้ำ
true | false
checkReceiver
Array
Array การตรวจสอบบัญชีผู้รับ
*ระบุได้มากกว่า 1 Object เพื่อตรวจสอบ หลายเงื่อนไขในคราวเดียว
*หากข้อมูลตรงกับเงื่อนไขอย่างน้อย 1 เงื่อนไขจะถือว่าสลิปถูกต้อง
[{ receiver1 }, { receiever2 }, ...]
checkReceiver.accountType
String
ตรวจสอบประเภทบัญชี
*ระบุหากต้องการตรวจสอบประเภทบัญชี
"01002" =
ธนาคารกรุงเทพ
(Bangkok Bank)
"01004" =
ธนาคารกสิกรไทย
(Kasikorn Bank)
"01006" =
ธนาคารกรุงไทย
(Krung Thai Bank)
"01011" =
ธนาคารทหารไทยธนชาต
(TMB Thanachart Bank)
"01014" =
ธนาคารไทยพาณิชย์
(SCB)
"01025" =
ธนาคารกรุงศรีอยุธยา
(Krungsri Bank)
"01069" =
ธนาคารเกียรตินาคินภัทร
(Kiatnakin Bank)
"01022" =
ธนาคารซีไอเอ็มบีไทย
(CIMB Thai Bank)
"01067" =
ธนาคารทิสโก้
(TISCO Bank)
"01024" =
ธนาคารยูโอบี
(UOB)
"01071" =
ธนาคารไทยเครดิต
(Thai Credit Bank)
"01073" =
ธนาคารแลนด์ แอนด์ เฮ้าส์
(LH Bank)
"01070" =
ธนาคารไอซีบีซี (ไทย)
(ICBC Thai)
"01098" =
ธนาคารพัฒนาวิสาหกิจขนาดกลางและขนาดย่อมแห่งประเทศไทย
(SME Bank)
"01034" =
ธนาคารเพื่อการเกษตรและสหกรณ์การเกษตร
(BAAC)
"01035" =
ธนาคารเพื่อการส่งออกและนำเข้าแห่งประเทศไทย
(EXIM Bank)
"01030" =
ธนาคารออมสิน
(GSB)
"01033" =
ธนาคารอาคารสงเคราะห์
(GHB)
"01066" =
ธนาคารอิสลามแห่งประเทศไทย
(Islamic Bank)
"02001" =
PromptPay เบอร์โทรศัพท์
"02003" =
PromptPay บัตรประชาชน/เลขประจำตัวผู้เสียภาษี
"02004" =
PromptPay รหัส E-Wallet
"03000" =
K+ Shop (KBANK), แม่มณี (SCB), Be Merchant NextGen (BBL), TTB Smart Shop (TTB)
"04000" =
True Money Wallet
checkReceiver.accountNameTH
String
ชื่อบัญชีผู้รับภาษาไทย
*ระบุหากต้องการตรวจสอบชื่อบัญชีภาษาไทย
"สมชาย สลิปทูโก"
*ห้ามใส่คำนำหน้า
checkReceiver.accountNameEN
String
ชื่อบัญชีผู้รับภาษาอังกฤษ
*ระบุหากต้องการตรวจสอบชื่อบัญชีภาษาอังกฤษ
"Somchay Slip2go"
*ห้ามใส่คำนำหน้า
checkReceiver.accountNumber
String
เลขที่บัญชี
*ระบุหากต้องการตรวจสอบเลขที่บัญชี
"xxxxxx1234"
*ห้ามเว้นวรรคหรือใส่สัญลักษณ์
checkAmount
Object
Object การตรวจสอบจำนวนเงิน
*ระบุหากต้องการตรวจสอบจำนวนเงินโอน
{ ... }
checkAmount.type
String
รูปแบบการตรวจสอบจำนวนเงิน
*ระบุรูปแบบการตรวจสอบ
"lte" = น้อยกว่าหรือเท่ากับ
"eq" = เท่ากับ
"gte" = มากกว่าหรือเท่ากับ
*หากไม่ระบุจะมีค่าเท่ากับ "eq"
checkAmount.amount
String
จำนวนเงิน
*ระบุจำนวนเงินที่ต้องการตรวจสอบ
"10000"
*ห้ามใส่ 0 และลูกน้ำ
checkDate
Object
Object การตรวจสอบวันที่โอน
*ระบุหากต้องการตรวจสอบวันที่โอน
{ ... }
checkDate.type
String
รูปแบบการตรวจสอบวันที่
*ระบุรูปแบบการตรวจสอบ
"lte" = น้อยกว่าหรือเท่ากับ
"eq" = เท่ากับ
"gte" = มากกว่าหรือเท่ากับ
*หากไม่ระบุจะมีค่าเท่ากับ "eq"
checkDate.date
DateISO
วันที่โอน
*ระบุวันที่โอน
"2025-10-05T14:48:00.000Z"
*ระบุเป็นเวลา GMT
Request Data (Multipart/Form-data)
Key
Type
file
[File Object]

รายละเอียด Response
key
Type
Description
Example
code
String
รหัสผลลัพธ์ของการทำรายการ
"200000"
message
String
ข้อความผลลัพธ์ของการทำรายการ
"Slip found"
data
Object | undefined
ผลลัพธ์ข้อมูลทั้งหมด
{ ... }
data.referenceId
String
รหัสอ้างอิงสลิป
"92887bd5-60d3-4744-9a98-b8574eaxxxxx-xx",
data.decode
String
รหัสอ้างอิง QR Code
"0041000600000101030040220014242082547BPM049885102TH9104xxxx",
data.transRef
String
รหัสอ้างอิงของข้อมูลชุดนี้
"015073144041ATF00999",
data.dateTime
String
วันที่โอน
"2025-10-05T14:48:00.000Z",
data.amount
Number
จำนวนเงินที่โอน
1
data.ref1
String | NULL
รหัสอ้างอิง 1
"xxx" | null
data.ref2
String | NULL
รหัสอ้างอิง 2
"xxx" | null
data.ref3
String | NULL
รหัสอ้างอิง 3
"xxx" | null
data.receiver
Object
ข้อมูลผู้รับ
{ ... }
data.receiver.account
Object
ข้อมูลบัญชีผู้รับ
{ ... }
data.receiver.account.name
String
ชื่อบัญชีผู้รับ
"บริษัท สลิปทูโก จำกัด"
data.receiver.account.bank
Object
ข้อมูลธนาคารผู้รับ
{ ... }
data.receiver.account.bank.account
String | NULL
เลขบัญชีธนาคารผู้รับ
"xxx-x-x5366-x"
data.receiver.account.proxy
Object | NULL
ข้อมูลตัวแทนบัญชีผู้รับ
{ ... } | null
data.receiver.account.proxy.type
String | NULL
NATID, MSISDN, EWALLTID, EMAIL, BILLERID
"NATID"
data.receiver.account.proxy.account
String | NULL
เลขตัวแทนบัญชีผู้รับ
"xxx-x-x5366-x"
data.receiver.bank
Object
ข้อมูลธนาคารผู้รับ
{ ... }
data.receiver.bank.id
String
เลขธนาคารผู้รับ
"004"
data.receiver.bank.name
String | NULL
ชื่อธนาคารผู้รับ
"ธนาคารกสิกรไทย"
data.sender
Object
ข้อมูลผู้ส่ง
{ ... }
data.sender.account
Object
ข้อมูลบัญชีผู้ส่ง
{ ... }
data.sender.account.name
String
ชื่อผู้ส่ง
"บริษัท สลิปทูโก จำกัด",
data.sender.account.bank
Object
ข้อมูลบัญชีผู้ส่ง
{ ... }
data.sender.account.bank.account
String
เลขที่บัญชีผู้ส่ง
"xxx-x-x9866-x"
data.sender.bank
Object
ข้อมูลธนาคารผู้ส่ง
{ ... }
data.sender.bank.id
String
เลขธนาคารผู้ส่ง
"004"
data.sender.bank.name
String | NULL
ชื่อธนาคารผู้ส่ง
"ธนาคารกสิกรไทย"

ตัวอย่าง Request Body (JSON)

{    
  "code": "200000",    
  "message": "Slip found",    
  "data": {        
    "referenceId": "92887bd5-60d3-4744-9a98-b8574eaxxxxx-xx",        
    "decode": "0041000600000101030040220014242082547BPM049885102TH9104xxxx",        
    "transRef": "015073144041ATF00999",        
    "dateTime": "2025-04-23T08:32:45.123Z",        
    "amount": 1,        
    "ref1": null,        
    "ref2": null,        
    "ref3": null,        
    "receiver": {            
    "account": {                
      "name": "บริษัท สลิปทูโก จำกัด",                
      "bank": {                    
        "account": "xxx-x-x5366-x"                
      },                
      "proxy": {                   
        "type": "NATID",                    
        "account": "xxx-x-x5366-x"                
      },            
    },            
    "bank": {               
      "id": "004",                
      "name": "ธนาคารกสิกรไทย"            
    }        
  },        
  "sender": {            
      "account": {                
        "name": "บริษัท สลิปทูโก จำกัด",                
        "bank": {                    
          "account": "xxx-x-x9866-x"                
        }            
      },            
      "bank": {                
        "id": "004",                
        "name": "ธนาคารกสิกรไทย"            
      }        
    },    
  }
}
Response
--------------
Success Code ทั้งหมด
สถานะ
รายละเอียด
HTTP
Response
Slip Found
ข้อมูลสลิปแสดงในระบบธนาคารอย่างถูกต้อง
200
200000
Get Info Success
ขอข้อมูลสำเร็จ
200
200001
Slip is Valid
ข้อมูลสลิปถูกต้อง
200
200200
Recipient Account Not Match
บัญชีผู้รับไม่ถูกต้อง
200
200401
Transfer Amount Not Match
ยอดโอนเงินไม่ตรงเงื่อนไข
200
200402
Transfer Date Not Match
วันที่โอนไม่ตรงเงื่อนไข
200
200403
Slip Not Found
ไม่พบข้อมูลสลิปในระบบธนาคาร
200
200404
Slip is Fraud
สลิปเสีย/สลิปปลอม
200
200500
Slip is Duplicated
สลิปซ้ำ
200
200501

รายละเอียดและตัวอย่าง Response
