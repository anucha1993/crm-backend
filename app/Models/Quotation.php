<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    protected $fillable = [
        'quotation_number',
        'customer_id',
        'customer_address_id',
        'status',
        'revision_number',
        'notes',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'vat_rate',
        'vat_amount',
        'total',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class, 'customer_address_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(QuotationRevision::class)->orderByDesc('created_at');
    }

    public static function generateNumber(): string
    {
        $prefix = 'QT-' . date('Ym') . '-';
        $last = static::where('quotation_number', 'like', $prefix . '%')
            ->orderBy('quotation_number', 'desc')
            ->first();
        $nextNum = $last ? ((int) substr($last->quotation_number, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
    }
}
