<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'invoice_number', 'order_id', 'customer_id', 'customer_address_id',
        'status', 'issue_date', 'subtotal', 'discount_type', 'discount_value',
        'discount_amount', 'vat_rate', 'vat_amount', 'total',
        'notes', 'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'cancelled_at' => 'datetime',
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
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Generate invoice number: IV YYMM XXXXXX
     * YY = Buddhist year last 2 digits, MM = month
     */
    public static function generateNumber(): string
    {
        $buddhistYear = date('Y') + 543;
        $yy = substr((string) $buddhistYear, -2);
        $mm = date('m');
        $prefix = 'IV' . $yy . $mm;

        $last = static::where('invoice_number', 'like', $prefix . '%')
            ->orderBy('invoice_number', 'desc')
            ->first();

        $nextNum = 1;
        if ($last) {
            $lastNum = (int) substr($last->invoice_number, strlen($prefix));
            $nextNum = $lastNum + 1;
        }

        return $prefix . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
    }
}
