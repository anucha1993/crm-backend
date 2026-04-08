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

        // Manager — ทุกอย่างยกเว้นจัดการ users และแก้ไข settings
        $manager = Role::firstOrCreate(
            ['name' => 'manager'],
            ['display_name' => 'ผู้จัดการ', 'description' => 'จัดการลูกค้า ดีล งาน สินค้า และดูรายงาน']
        );
        $managerPerms = $allPermissions->filter(function ($p) {
            return ! str_starts_with($p->name, 'users.') && $p->name !== 'settings.edit';
        })->pluck('id');
        $manager->permissions()->sync($managerPerms);

        // Sales — ดู/สร้าง/แก้ไข ลูกค้า ดีล งาน สินค้า + ดูรายงาน + ดูหมวดหมู่
        $sales = Role::firstOrCreate(
            ['name' => 'sales'],
            ['display_name' => 'พนักงานขาย', 'description' => 'จัดการลูกค้าและดีลของตัวเอง']
        );
        $salesPerms = $allPermissions->filter(function ($p) {
            return in_array($p->name, [
                'customers.view', 'customers.create', 'customers.edit',
                'deals.view', 'deals.create', 'deals.edit',
                'tasks.view', 'tasks.create', 'tasks.edit',
                'products.view', 'products.create', 'products.edit',
                'categories.view',
                'reports.view',
            ]);
        })->pluck('id');
        $sales->permissions()->sync($salesPerms);
    }
}
