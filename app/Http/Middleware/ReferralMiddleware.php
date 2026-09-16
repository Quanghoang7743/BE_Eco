<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReferralMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->has('ref')) {
            $refId = (int) $request->query('ref');

            // Nếu chưa đăng nhập hoặc không phải tự giới thiệu chính mình
            if (!auth()->check() || auth()->id() !== $refId) {
                session(['ref' => $refId]);
            }
        }

        return $next($request);
    }

}
