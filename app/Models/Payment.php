<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_type',
        'payment_number', 'order_id', 'customer_id', 'method', 'amount',
        'is_deposit', 'status', 'notes', 'reject_reason',
        'slip_image', 'slip_verified', 'slip_ref', 'slip_data', 'slip_status_code',
        'sender_name', 'sender_bank', 'sender_account',
        'receiver_name', 'receiver_bank', 'receiver_account',
        'transfer_amount', 'transfer_date',
        'approved_by', 'approved_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transfer_amount' => 'decimal:2',
            'is_deposit' => 'boolean',
            'slip_verified' => 'boolean',
            'slip_data' => 'array',
            'transfer_date' => 'datetime',
            'approved_at' => 'datetime',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PaymentLog::class)->orderByDesc('created_at');
    }

    public static function generateNumber(): string
    {
        $prefix = 'PAY-' . date('Ym') . '-';
        $last = static::withoutGlobalScope('account')
            ->where('payment_number', 'like', $prefix . '%')
            ->orderBy('payment_number', 'desc')
            ->first();
        $nextNum = $last ? ((int) substr($last->payment_number, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
    }
}
