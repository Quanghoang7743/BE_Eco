<?php

namespace App\Http\Middleware;

use App\Models\ClientUserVerification;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApprovedClientVerification
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Bạn chưa đăng nhập hoặc phiên đăng nhập đã hết hạn.',
            ], 401);
        }

        $role = strtolower(trim((string) ($user->role ?? '')));
        if (in_array($role, ['admin', 'super_admin'], true)) {
            return $next($request);
        }

        $verificationStatus = ClientUserVerification::query()
            ->where('user_id', $user->id)
            ->value('status');

        if ($verificationStatus === ClientUserVerification::STATUS_APPROVED) {
            return $next($request);
        }

        $blockedStatus = match ($verificationStatus) {
            ClientUserVerification::STATUS_PENDING => ClientUserVerification::STATUS_PENDING,
            ClientUserVerification::STATUS_REJECTED => ClientUserVerification::STATUS_REJECTED,
            default => 'missing',
        };
        $details = ['verification_status' => $blockedStatus];
        $message = 'Tài khoản cần hoàn tất xác thực danh tính trước khi truy cập cửa hàng.';

        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'ACCOUNT_VERIFICATION_REQUIRED',
            'details' => $details,
            'data' => null,
            'meta' => [
                'authenticated' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [[
                'code' => 'ACCOUNT_VERIFICATION_REQUIRED',
                'message' => $message,
                'details' => $details,
            ]],
        ], 403);
    }
}
