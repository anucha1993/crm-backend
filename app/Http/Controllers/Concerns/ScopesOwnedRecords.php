<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait ScopesOwnedRecords
{
    /**
     * A "sales" user (who is neither admin nor manager) may only see their own
     * records. Everyone else sees all records within the account scope.
     */
    public function isSalesRestricted(Request $request): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        return $user->hasRole('sales')
            && !$user->hasRole('admin')
            && !$user->hasRole('manager');
    }

    /**
     * Restrict the query to records created by the current user when they are a
     * sales-restricted user.
     */
    public function scopeToOwner(Builder $query, Request $request, string $column = 'created_by'): Builder
    {
        if ($this->isSalesRestricted($request)) {
            $query->where($column, $request->user()->id);
        }

        return $query;
    }
}
