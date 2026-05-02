<?php

namespace App\Http\Middleware;

use App\Support\AccountContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AccountScope
{
    /**
     * Reads X-Account-Type from header (or ?account_type=) and validates that
     * the authenticated user has the matching permission (accounts.cash / accounts.tax).
     * The validated value is stored on the request for controllers to consume via:
     *     $request->attributes->get('account_type')
     */
    public function handle(Request $request, Closure $next): Response
    {
        $type = $request->header('X-Account-Type') ?? $request->query('account_type');

        if (!in_array($type, ['cash', 'tax'], true)) {
            return response()->json([
                'message' => 'กรุณาเลือกบัญชี (บิลเงินสด / ใบกำกับภาษี) ก่อนใช้งาน',
                'code' => 'account_required',
            ], 400);
        }

        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Admin bypasses
        $isAdmin = $user->roles()->where('name', 'admin')->exists();
        if (!$isAdmin) {
            $permissionName = $type === 'cash' ? 'accounts.cash' : 'accounts.tax';
            $hasPermission = $user->roles()
                ->whereHas('permissions', fn ($q) => $q->where('name', $permissionName))
                ->exists();

            if (!$hasPermission) {
                return response()->json([
                    'message' => 'คุณไม่มีสิทธิ์เข้าถึงบัญชีนี้',
                    'code' => 'account_forbidden',
                ], 403);
            }
        }

        $request->attributes->set('account_type', $type);
        AccountContext::set($type);

        try {
            return $next($request);
        } finally {
            AccountContext::set(null);
        }
    }
}
