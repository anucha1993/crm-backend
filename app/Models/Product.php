<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'code',
        'name',
        'category_id',
        'unit',
        'unit_custom',
        'cross_section',
        'length',
        'length_unit',
        'length_unit_custom',
        'thickness',
        'thickness_unit',
        'thickness_unit_custom',
        'steel_type',
        'side_steel',
        'size_type',
        'custom_note',
        'weight',
        'selling_price',
        'code_ref',
    ];

    protected function casts(): array
    {
        return [
            'length' => 'decimal:2',
            'thickness' => 'decimal:2',
            'weight' => 'decimal:2',
            'selling_price' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function sizes(): HasMany
    {
        return $this->hasMany(ProductSize::class)->orderBy('sort_order');
    }
}
