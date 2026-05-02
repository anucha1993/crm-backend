<?php

namespace App\Models\Concerns;

use App\Support\AccountContext;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToAccount
{
    protected static function bootBelongsToAccount(): void
    {
        static::addGlobalScope('account', function (Builder $builder): void {
            $type = AccountContext::get();
            if ($type !== null) {
                $builder->where($builder->getModel()->getTable() . '.account_type', $type);
            }
        });

        static::creating(function ($model) {
            if (empty($model->account_type)) {
                $type = AccountContext::get();
                if ($type !== null) {
                    $model->account_type = $type;
                }
            }
        });
    }
}
