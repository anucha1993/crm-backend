<?php

namespace Database\Seeders;

use App\Models\VehicleType;
use Illuminate\Database\Seeder;

class VehicleTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'code' => 'SIX_WHEEL_LARGE',
                'name' => 'หกล้อใหญ่',
                'min_weight' => 1,
                'max_weight' => 5,
                'weight_unit' => 'ตัน',
                'sort_order' => 1,
            ],
            [
                'code' => 'SIX_WHEEL_SMALL',
                'name' => 'หกล้อเล็ก',
                'min_weight' => 1,
                'max_weight' => 6.5,
                'weight_unit' => 'ตัน',
                'sort_order' => 2,
            ],
            [
                'code' => 'SIX_WHEEL_MEDIUM',
                'name' => 'หกล้อกลาง',
                'min_weight' => 1,
                'max_weight' => 8,
                'weight_unit' => 'ตัน',
                'sort_order' => 3,
            ],
            [
                'code' => 'TEN_WHEEL',
                'name' => 'รถสิบล้อ',
                'min_weight' => 1,
                'max_weight' => 14,
                'weight_unit' => 'ตัน',
                'sort_order' => 4,
            ],
        ];

        foreach ($types as $type) {
            VehicleType::updateOrCreate(
                ['code' => $type['code']],
                $type
            );
        }
    }
}
