<?php

namespace Database\Seeders;

use App\Models\CustomerLevel;
use Illuminate\Database\Seeder;

class CustomerLevelSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [
            [
                'name' => 'VIP',
                'color' => '#EAB308',
                'inactive_days' => 90,
                'sort_order' => 1,
            ],
            [
                'name' => 'ทอง',
                'color' => '#F59E0B',
                'inactive_days' => 60,
                'sort_order' => 2,
            ],
            [
                'name' => 'เงิน',
                'color' => '#6B7280',
                'inactive_days' => 45,
                'sort_order' => 3,
            ],
            [
                'name' => 'ทั่วไป',
                'color' => '#3B82F6',
                'inactive_days' => 30,
                'sort_order' => 4,
            ],
        ];

        foreach ($levels as $level) {
            CustomerLevel::updateOrCreate(
                ['name' => $level['name']],
                $level
            );
        }
    }
}
