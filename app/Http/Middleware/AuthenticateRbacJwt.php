<?php

namespace App\Http\Middleware;

use App\Services\Auth\JwtTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateRbacJwt
{
    public function __construct(private readonly JwtTokenService $jwtTokenService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = (string) $request->header('Authorization', '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return response()->json(['message' => 'Unauthorized. Missing Bearer token.'], 401);
        }

        try {
            $claims = $this->jwtTokenService->decode($matches[1]);
        } catch (Throwable) {
            return response()->json(['message' => 'Unauthorized. Invalid token.'], 401);
        }

        $request->attributes->set('rbac_claims', $claims);

        return $next($request);
    }
}
