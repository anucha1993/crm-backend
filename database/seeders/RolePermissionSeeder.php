<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // สร้าง Permissions
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
            'reports' => [
                ['name' => 'reports.view', 'display_name' => 'ดูรายงาน'],
                ['name' => 'reports.export', 'display_name' => 'ส่งออกรายงาน'],
            ],
            'settings' => [
                ['name' => 'settings.view', 'display_name' => 'ดูตั้งค่า'],
                ['name' => 'settings.edit', 'display_name' => 'แก้ไขตั้งค่า'],
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
            'users' => [
                ['name' => 'users.view', 'display_name' => 'ดูผู้ใช้'],
                ['name' => 'users.create', 'display_name' => 'สร้างผู้ใช้'],
                ['name' => 'users.edit', 'display_name' => 'แก้ไขผู้ใช้'],
                ['name' => 'users.delete', 'display_name' => 'ลบผู้ใช้'],
            ],
        ];

        $allPermissions = [];
        foreach ($permissionGroups as $group => $permissions) {
            foreach ($permissions as $perm) {
                $allPermissions[] = Permission::create([
                    'name' => $perm['name'],
                    'display_name' => $perm['display_name'],
                    'group' => $group,
                ]);
            }
        }

        // สร้าง Roles
        $admin = Role::create([
            'name' => 'admin',
            'display_name' => 'ผู้ดูแลระบบ',
            'description' => 'สิทธิ์เต็มรูปแบบในการจัดการระบบทั้งหมด',
        ]);
        $admin->permissions()->attach(collect($allPermissions)->pluck('id'));

        $manager = Role::create([
            'name' => 'manager',
            'display_name' => 'ผู้จัดการ',
            'description' => 'จัดการลูกค้า ดีล งาน และดูรายงาน',
        ]);
        $managerPerms = collect($allPermissions)->filter(function ($p) {
            return ! str_starts_with($p->name, 'users.') && ! str_starts_with($p->name, 'settings.edit');
        })->pluck('id');
        $manager->permissions()->attach($managerPerms);

        $sales = Role::create([
            'name' => 'sales',
            'display_name' => 'พนักงานขาย',
            'description' => 'จัดการลูกค้าและดีลของตัวเอง',
        ]);
        $salesPerms = collect($allPermissions)->filter(function ($p) {
            return in_array($p->name, [
                'customers.view', 'customers.create', 'customers.edit',
                'deals.view', 'deals.create', 'deals.edit',
                'tasks.view', 'tasks.create', 'tasks.edit',
                'products.view', 'products.create', 'products.edit',
                'categories.view',
                'reports.view',
            ]);
        })->pluck('id');
        $sales->permissions()->attach($salesPerms);

        // สร้าง Users สำหรับ Dev
        $users = [
            ['name' => 'Admin', 'email' => 'admin@crm.com', 'role' => $admin],
            ['name' => 'สมชาย ผู้จัดการ', 'email' => 'manager@crm.com', 'role' => $manager],
            ['name' => 'สมหญิง ฝ่ายขาย', 'email' => 'sales@crm.com', 'role' => $sales],
            ['name' => 'วิชัย ฝ่ายขาย', 'email' => 'sales2@crm.com', 'role' => $sales],
        ];

        foreach ($users as $u) {
            $user = User::firstOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'password' => 'password',
                ]
            );
            $user->roles()->sync([$u['role']->id]);
        }
    }
}
