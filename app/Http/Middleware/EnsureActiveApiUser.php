<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveApiUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user && $request->bearerToken()) {
            $accessToken = PersonalAccessToken::findToken((string) $request->bearerToken());
            $tokenable = $accessToken?->tokenable;
            $user = $tokenable instanceof User ? $tokenable : null;
        }

        if ($user instanceof User && ! $user->isActiveForAuth()) {
            return response()->json([
                'success' => false,
                'message' => 'Tai khoan dang bi khoa hoac khong con hoat dong.',
                'errors' => [[
                    'code' => 'ACCOUNT_INACTIVE',
                    'message' => 'Account is inactive.',
                ]],
            ], 403);
        }

        return $next($request);
    }
}
