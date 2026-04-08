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
            'deals' => [
                ['name' => 'deals.view', 'display_name' => 'ดูดีล'],
                ['name' => 'deals.create', 'display_name' => 'สร้างดีล'],
                ['name' => 'deals.edit', 'display_name' => 'แก้ไขดีล'],
                ['name' => 'deals.delete', 'display_name' => 'ลบดีล'],
            ],
            'tasks' => [
                ['name' => 'tasks.view', 'display_name' => 'ดูงาน'],
                ['name' => 'tasks.create', 'display_name' => 'สร้างงาน'],
                ['name' => 'tasks.edit', 'display_name' => 'แก้ไขงาน'],
                ['name' => 'tasks.delete', 'display_name' => 'ลบงาน'],
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
            'reports' => [
                ['name' => 'reports.view', 'display_name' => 'ดูรายงาน'],
                ['name' => 'reports.export', 'display_name' => 'ส่งออกรายงาน'],
            ],
            'settings' => [
                ['name' => 'settings.view', 'display_name' => 'ดูตั้งค่า'],
                ['name' => 'settings.edit', 'display_name' => 'แก้ไขตั้งค่า'],
            ],
            'users' => [
                ['name' => 'users.view', 'display_name' => 'ดูผู้ใช้'],
                ['name' => 'users.create', 'display_name' => 'สร้างผู้ใช้'],
                ['name' => 'users.edit', 'display_name' => 'แก้ไขผู้ใช้'],
                ['name' => 'users.delete', 'display_name' => 'ลบผู้ใช้'],
            ],
        ];

        foreach ($permissionGroups as $group => $permissions) {
            foreach ($permissions as $perm) {
                Permission::firstOrCreate(
                    ['name' => $perm['name']],
                    [
                        'display_name' => $perm['display_name'],
                        'group' => $group,
                    ]
                );
            }
        }
    }
}
