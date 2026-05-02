<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_type',
        'delivery_number', 'order_id', 'customer_id', 'customer_address_id',
        'status', 'delivery_date', 'delivered_at', 'notes',
        'total_weight', 'suggested_vehicle',
        'delivered_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
            'delivered_at' => 'datetime',
            'total_weight' => 'decimal:4',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
        return $this->hasMany(DeliveryItem::class)->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public static function generateNumber(): string
    {
        $prefix = 'DLV-' . date('Ym') . '-';
        $last = static::withoutGlobalScope('account')
            ->where('delivery_number', 'like', $prefix . '%')
            ->orderBy('delivery_number', 'desc')
            ->first();
        $nextNum = $last ? ((int) substr($last->delivery_number, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
    }
}
