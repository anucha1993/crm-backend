<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissionGroups = [
            'customers' => [
                ['name' => 'customers.view', 'display_name' => 'ดูลูกค้า'],
                ['name' => 'customers.create', 'display_name' => 'สร้างลูกค้า'],
                ['name' => 'customers.edit', 'display_name' => 'แก้ไขลูกค้า'],
                ['name' => 'customers.delete', 'display_name' => 'ลบลูกค้า'],
            ],
            'customer-levels' => [
                ['name' => 'customer-levels.view', 'display_name' => 'ดูระดับลูกค้า'],
                ['name' => 'customer-levels.manage', 'display_name' => 'จัดการระดับลูกค้า'],
            ],
            'quotations' => [
                ['name' => 'quotations.view', 'display_name' => 'ดูใบเสนอราคา'],
                ['name' => 'quotations.create', 'display_name' => 'สร้างใบเสนอราคา'],
                ['name' => 'quotations.edit', 'display_name' => 'แก้ไขใบเสนอราคา'],
                ['name' => 'quotations.delete', 'display_name' => 'ลบใบเสนอราคา'],
            ],
            'orders' => [
                ['name' => 'orders.view', 'display_name' => 'ดูคำสั่งซื้อ'],
                ['name' => 'orders.edit', 'display_name' => 'แก้ไขคำสั่งซื้อ'],
            ],
            'payments' => [
                ['name' => 'payments.view', 'display_name' => 'ดูการชำระเงิน'],
                ['name' => 'payments.create', 'display_name' => 'บันทึกการชำระเงิน'],
                ['name' => 'payments.approve', 'display_name' => 'อนุมัติการชำระเงิน'],
                ['name' => 'payments.reject', 'display_name' => 'ปฏิเสธการชำระเงิน'],
            ],
            'invoices' => [
                ['name' => 'invoices.view', 'display_name' => 'ดูใบกำกับภาษี'],
                ['name' => 'invoices.create', 'display_name' => 'สร้างใบกำกับภาษี'],
                ['name' => 'invoices.cancel', 'display_name' => 'ยกเลิกใบกำกับภาษี'],
            ],
            'deliveries' => [
                ['name' => 'deliveries.view', 'display_name' => 'ดูใบส่งสินค้า'],
                ['name' => 'deliveries.create', 'display_name' => 'สร้างใบส่งสินค้า'],
                ['name' => 'deliveries.confirm', 'display_name' => 'ยืนยันการจัดส่ง'],
                ['name' => 'deliveries.cancel', 'display_name' => 'ยกเลิกใบส่งสินค้า'],
            ],
            'products' => [
                ['name' => 'products.view', 'display_name' => 'ดูสินค้า'],
                ['name' => 'products.create', 'display_name' => 'สร้างสินค้า'],
                ['name' => 'products.edit', 'display_name' => 'แก้ไขสินค้า'],
                ['name' => 'products.delete', 'display_name' => 'ลบสินค้า'],
            ],
            'categories' => [
                ['name' => 'categories.view', 'display_name' => 'ดูหมวดหมู่'],
                ['name' => 'categories.create', 'display_name' => 'สร้างหมวดหมู่'],
                ['name' => 'categories.edit', 'display_name' => 'แก้ไขหมวดหมู่'],
                ['name' => 'categories.delete', 'display_name' => 'ลบหมวดหมู่'],
            ],
            'vehicle-types' => [
                ['name' => 'vehicle-types.view', 'display_name' => 'ดูประเภทรถขนส่ง'],
                ['name' => 'vehicle-types.manage', 'display_name' => 'จัดการประเภทรถขนส่ง'],
            ],
            'reports' => [
                ['name' => 'reports.view', 'display_name' => 'ดูรายงาน'],
                ['name' => 'reports.export', 'display_name' => 'ส่งออกรายงาน'],
            ],
            'users' => [
                ['name' => 'users.view', 'display_name' => 'ดูผู้ใช้'],
                ['name' => 'users.create', 'display_name' => 'สร้างผู้ใช้'],
                ['name' => 'users.edit', 'display_name' => 'แก้ไขผู้ใช้'],
                ['name' => 'users.delete', 'display_name' => 'ลบผู้ใช้'],
            ],
            'roles' => [
                ['name' => 'roles.view', 'display_name' => 'ดูบทบาท'],
                ['name' => 'roles.manage', 'display_name' => 'จัดการบทบาทและสิทธิ์'],
            ],
            'settings' => [
                ['name' => 'settings.view', 'display_name' => 'ดูตั้งค่าบริษัท'],
                ['name' => 'settings.edit', 'display_name' => 'แก้ไขตั้งค่าบริษัท'],
            ],
        ];

        $validNames = [];
        foreach ($permissionGroups as $group => $permissions) {
            foreach ($permissions as $perm) {
                $validNames[] = $perm['name'];
                Permission::updateOrCreate(
                    ['name' => $perm['name']],
                    [
                        'display_name' => $perm['display_name'],
                        'group' => $group,
                    ]
                );
            }
        }

        // Remove obsolete permissions (e.g. deals.*, tasks.*)
        Permission::whereNotIn('name', $validNames)->delete();
    }
}
