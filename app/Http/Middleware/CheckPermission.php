<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'กรุณาเข้าสู่ระบบ'], 401);
        }

        // Admin มีสิทธิ์ทุกอย่าง
        if ($user->hasRole('admin')) {
            return $next($request);
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'คุณไม่มีสิทธิ์เข้าถึง'], 403);
    }
}
