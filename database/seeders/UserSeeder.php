<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'Admin', 'email' => 'admin@crm.com', 'role' => 'admin'],
            ['name' => 'สมชาย ผู้จัดการ', 'email' => 'manager@crm.com', 'role' => 'manager'],
            ['name' => 'สมหญิง ฝ่ายขาย', 'email' => 'sales@crm.com', 'role' => 'sales'],
            ['name' => 'วิชัย ฝ่ายขาย', 'email' => 'sales2@crm.com', 'role' => 'sales'],
        ];

        foreach ($users as $u) {
            $user = User::firstOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'password' => 'password',
                ]
            );

            $role = Role::where('name', $u['role'])->first();
            if ($role) {
                $user->roles()->syncWithoutDetaching([$role->id]);
            }
        }
    }
}
