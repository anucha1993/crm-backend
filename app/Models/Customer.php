<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'code',
        'type',
        'customer_level_id',
        'name',
        'tax_id',
        'contact_name',
        'phone',
        'email',
        'line_id',
        'address',
        'pocket_money',
        'last_activity_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
        ];
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(CustomerLevel::class, 'customer_level_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public static function generateCode(): string
    {
        $last = static::orderBy('id', 'desc')->first();
        $nextNum = $last ? ((int) substr($last->code, 4)) + 1 : 1;
        return 'CUS-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
    }
}
