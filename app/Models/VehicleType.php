<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleType extends Model
{
    protected $fillable = [
        'code',
        'name',
        'min_weight',
        'max_weight',
        'weight_unit',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'min_weight' => 'decimal:2',
            'max_weight' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
