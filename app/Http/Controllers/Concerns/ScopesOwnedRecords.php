<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait ScopesOwnedRecords
{
    /**
     * A user CAN see every record when they either have the explicit
     * "records.view_all" permission or the "admin" role.
     */
    public function canViewAllRecords(Request $request): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        return $user->hasRole('admin') || $user->hasPermission('records.view_all');
    }

    /**
     * Back-compat wrapper: TRUE when the current user must be limited to their
     * own records (i.e. they do NOT have records.view_all AND are not admin).
     */
    public function isSalesRestricted(Request $request): bool
    {
        return !$this->canViewAllRecords($request);
    }

    /**
     * Restrict the query to records created by the current user when either:
     *   1. the user does NOT have permission to view others' records, OR
     *   2. the user opted-in via the "?owner=me" query filter.
     */
    public function scopeToOwner(Builder $query, Request $request, string $column = 'created_by'): Builder
    {
        $user = $request->user();
        if (!$user) {
            return $query;
        }

        $forceOwn = !$this->canViewAllRecords($request);
        $optIn = strtolower((string) $request->query('owner', '')) === 'me';

        if ($forceOwn || $optIn) {
            $query->where($column, $user->id);
        }

        return $query;
    }
}
