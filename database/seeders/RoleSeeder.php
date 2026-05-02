<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $allPermissions = Permission::all();

        // Admin — สิทธิ์ทั้งหมด
        $admin = Role::firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'ผู้ดูแลระบบ', 'description' => 'สิทธิ์เต็มรูปแบบในการจัดการระบบทั้งหมด']
        );
        $admin->permissions()->sync($allPermissions->pluck('id'));

        // Manager — ทุกอย่างยกเว้นจัดการ users/roles และแก้ไข settings
        $manager = Role::firstOrCreate(
            ['name' => 'manager'],
            ['display_name' => 'ผู้จัดการ', 'description' => 'จัดการลูกค้า ใบเสนอราคา คำสั่งซื้อ การชำระเงิน และดูรายงาน']
        );
        $managerPerms = $allPermissions->filter(function ($p) {
            return ! str_starts_with($p->name, 'users.')
                && ! str_starts_with($p->name, 'roles.')
                && $p->name !== 'settings.edit';
        })->pluck('id');
        $manager->permissions()->sync($managerPerms);

        // Sales — ดู/สร้างเอกสารขาย แต่ไม่อนุมัติการชำระเงินและไม่ลบ
        $sales = Role::firstOrCreate(
            ['name' => 'sales'],
            ['display_name' => 'พนักงานขาย', 'description' => 'จัดการลูกค้า ใบเสนอราคา และคำสั่งซื้อของตัวเอง']
        );
        $salesPerms = $allPermissions->filter(function ($p) {
            return in_array($p->name, [
                // Customers
                'customers.view', 'customers.create', 'customers.edit',
                'customer-levels.view',
                // Sales documents
                'quotations.view', 'quotations.create', 'quotations.edit',
                'orders.view', 'orders.edit',
                'payments.view', 'payments.create',
                'invoices.view', 'invoices.create',
                'deliveries.view', 'deliveries.create',
                // Catalog (read-only)
                'products.view', 'categories.view',
                'vehicle-types.view',
                // Reports
                'reports.view',
                // Settings
                'settings.view',
                // Accounts: default ให้เข้าได้ทั้งบิลเงินสดและใบกำกับ (admin ปรับใน UI ภายหลัง)
                'accounts.cash', 'accounts.tax',
            ]);
        })->pluck('id');
        $sales->permissions()->sync($salesPerms);
    }
}
