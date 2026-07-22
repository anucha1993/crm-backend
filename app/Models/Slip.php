<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Slip extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_type',
        'slip_ref', 'slip_image', 'slip_verified', 'slip_status_code', 'slip_data',
        'amount',
        'sender_name', 'sender_bank', 'sender_account',
        'receiver_name', 'receiver_bank', 'receiver_account',
        'transfer_date', 'uploaded_by',
    ];

    protected $appends = ['used_amount', 'remaining_amount'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'slip_verified' => 'boolean',
            'slip_data' => 'array',
            'transfer_date' => 'datetime',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Amount of this slip already allocated to (non-rejected) payments.
     */
    public function getUsedAmountAttribute(): float
    {
        // Use loaded relation when available to avoid N+1 during listing.
        if ($this->relationLoaded('payments')) {
            return (float) $this->payments
                ->where('status', '!=', 'rejected')
                ->sum('amount');
        }

        return (float) $this->payments()
            ->where('status', '!=', 'rejected')
            ->sum('amount');
    }

    /**
     * Remaining slip value that can still be split into other orders.
     */
    public function getRemainingAmountAttribute(): float
    {
        return max(0, (float) $this->amount - $this->used_amount);
    }
}
