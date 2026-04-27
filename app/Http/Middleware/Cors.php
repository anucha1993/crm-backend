<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Cors
{
    public function handle(Request $request, Closure $next): Response
    {
        // อ่านรายการ origin ที่อนุญาตจาก ENV (คั่นด้วย ,) — รองรับทั้ง dev และ production
        $configured = env('CORS_ALLOWED_ORIGINS', 'http://localhost:2000,http://127.0.0.1:2000');
        $allowedOrigins = array_filter(array_map('trim', explode(',', $configured)));

        // รองรับ wildcard เช่น https://*.example.com
        $allowAll = in_array('*', $allowedOrigins, true);

        $origin = $request->header('Origin');

        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        $isAllowed = $origin && ($allowAll || in_array($origin, $allowedOrigins, true) || $this->matchesWildcard($origin, $allowedOrigins));

        if ($isAllowed) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, Accept, X-Requested-With');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Max-Age', '86400');
        }

        return $response;
    }

    private function matchesWildcard(string $origin, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (! str_contains($pattern, '*')) {
                continue;
            }
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
            if (preg_match($regex, $origin)) {
                return true;
            }
        }

        return false;
    }
}
