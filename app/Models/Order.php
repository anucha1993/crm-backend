<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'order_number', 'quotation_id', 'customer_id', 'customer_address_id',
        'status', 'delivery_status', 'notes', 'subtotal', 'discount_type', 'discount_value',
        'discount_amount', 'vat_rate', 'vat_amount', 'total',
        'paid_amount', 'remaining_amount', 'created_by', 'updated_by',
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
            'paid_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
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
        return $this->hasMany(OrderItem::class)->orderBy('sort_order');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function paymentLogs(): HasMany
    {
        return $this->hasMany(PaymentLog::class)->orderByDesc('created_at');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function recalculatePaid(): void
    {
        $paid = $this->payments()->where('status', 'approved')->sum('amount');
        $this->update([
            'paid_amount' => $paid,
            'remaining_amount' => max(0, (float) $this->total - $paid),
        ]);
    }

    public static function generateNumber(): string
    {
        $prefix = 'ORD-' . date('Ym') . '-';
        $last = static::where('order_number', 'like', $prefix . '%')
            ->orderBy('order_number', 'desc')
            ->first();
        $nextNum = $last ? ((int) substr($last->order_number, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
    }
}
