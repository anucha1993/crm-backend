<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSize extends Model
{
    protected $fillable = [
        'product_id',
        'length',
        'length_unit',
        'length_unit_custom',
        'thickness',
        'thickness_unit',
        'thickness_unit_custom',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'length' => 'decimal:2',
            'thickness' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
