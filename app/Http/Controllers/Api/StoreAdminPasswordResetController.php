<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\SafeEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StoreAdminPasswordResetController extends Controller
{
    private const TOKEN_EXPIRY_MINUTES = 60;

    /**
     * POST /api/store-admin/forgot-password
     * Gửi yêu cầu đặt lại mật khẩu
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'max:255', $this->safeEmailRule()],
        ]);

        if ($validator->fails()) {
            return $this->secureResponse('VALIDATION_ERROR', 'Dữ liệu đầu vào không hợp lệ.', 422, $validator->errors()->toArray());
        }

        $email = SafeEmail::normalize($request->input('email'));

        if (!Schema::hasTable('users')) {
            return $this->secureResponse('USER_TABLE_NOT_FOUND', 'Hệ thống chưa sẵn sàng.', 500);
        }

        $user = User::query()->where('email', $email)->first();

        if ($user) {
            $this->generateAndStoreResetToken($email);
            $this->sendPasswordResetNotification($email);
        }

        return $this->secureResponse('SUCCESS', 'Nếu email tồn tại trong hệ thống, bạn sẽ nhận được hướng dẫn đặt lại mật khẩu.', 200);
    }

    /**
     * POST /api/store-admin/reset-password
     * Đặt lại mật khẩu mới
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'max:255', $this->safeEmailRule()],
            'token' => ['required', 'string', 'min:10', 'max:255'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'password_confirmation' => ['required', 'string', 'same:password'],
        ]);

        if ($validator->fails()) {
            return $this->secureResponse('VALIDATION_ERROR', 'Dữ liệu đầu vào không hợp lệ.', 422, $validator->errors()->toArray());
        }

        $email = SafeEmail::normalize($request->input('email'));
        $token = (string) $request->input('token');
        $password = (string) $request->input('password');

        if (!Schema::hasTable('password_reset_tokens') || !Schema::hasTable('users')) {
            return $this->secureResponse('SYSTEM_NOT_READY', 'Hệ thống chưa sẵn sàng.', 500);
        }

        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('token', $token)
            ->first();

        if (!$resetRecord) {
            return $this->secureResponse('INVALID_TOKEN', 'Mã đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.', 400);
        }

        if (!$this->isTokenValid($resetRecord->created_at)) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            return $this->secureResponse('EXPIRED_TOKEN', 'Mã đặt lại mật khẩu đã hết hạn. Vui lòng tạo yêu cầu mới.', 400);
        }

        $updated = false;
        DB::transaction(function () use ($email, $password, &$updated) {
            $user = User::query()->where('email', $email)->first();

            if ($user) {
                $user->password = Hash::make($password);
                $user->save();
                $updated = true;
            }

            DB::table('password_reset_tokens')->where('email', $email)->delete();
        });

        if (!$updated) {
            return $this->secureResponse('USER_NOT_FOUND', 'Không tìm thấy tài khoản.', 404);
        }

        return response()->json([
            'data' => [
                'message' => 'Mật khẩu đã được đặt lại thành công. Vui lòng đăng nhập với mật khẩu mới.',
            ],
            'meta' => [
                'authenticated' => false,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 200);
    }

    private function generateAndStoreResetToken(string $email): void
    {
        if (! Schema::hasTable('password_reset_tokens') || ! SafeEmail::isValid($email)) {
            return;
        }

        $token = Str::random(60);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => $token,
                'created_at' => now(),
            ]
        );
    }

    private function isTokenValid(?string $createdAt): bool
    {
        if (!$createdAt) {
            return false;
        }

        try {
            $createdTime = \Illuminate\Support\Carbon::parse($createdAt);
            $expiryTime = $createdTime->addMinutes(self::TOKEN_EXPIRY_MINUTES);
            return now()->lessThanOrEqualTo($expiryTime);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function sendPasswordResetNotification(string $email): void
    {
        if (! Schema::hasTable('password_reset_tokens') || ! SafeEmail::isValid($email)) {
            return;
        }

        $email = SafeEmail::normalize($email);

        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if (!$resetRecord) {
            return;
        }

        $token = (string) $resetRecord->token;

        try {
            if (Schema::hasTable('notifications')) {
                $title = 'Yêu cầu đặt lại mật khẩu';
                $message = "Bạn đã yêu cầu đặt lại mật khẩu. Mã đặt lại của bạn là: {$token}. Mã này sẽ hết hạn sau " . self::TOKEN_EXPIRY_MINUTES . " phút.";

                DB::table('notifications')->insert([
                    'title' => $title,
                    'message' => $message,
                    'readable_status' => 'unread',
                    'notifiable_id' => null,
                    'notifiable_type' => 'email',
                    'channel' => 'email',
                    'email' => $email,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send password reset notification: ' . $e->getMessage());
        }

        Log::info("Password reset token generated for email: {$email}");
    }

    private function safeEmailRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! SafeEmail::isValid($value)) {
                $fail('Email khong hop le.');
            }
        };
    }

    private function secureResponse(
        string $code,
        string $message,
        int $status,
        ?array $details = null
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json([
            'data' => null,
            'meta' => [
                'authenticated' => false,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [$error],
        ], $status);
    }
}
