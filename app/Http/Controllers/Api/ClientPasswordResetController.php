<?php

namespace App\Http\Controllers\Api;

use App\Mail\PasswordResetOtpMail;
use App\Models\User;
use App\Support\SafeEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ClientPasswordResetController extends ClientController
{
    private const OTP_EXPIRY_MINUTES = 10;
    private const FORGOT_RATE_LIMIT_MAX_ATTEMPTS = 3;
    private const FORGOT_RATE_LIMIT_DECAY_SECONDS = 600;

    public function lookupEmail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'identity' => ['required', 'string', 'max:255'],
        ], [
            'identity.required' => 'Vui lòng nhập email hoặc số điện thoại.',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Dữ liệu đầu vào không hợp lệ.', 422, $validator->errors()->toArray());
        }

        if (!Schema::hasTable('users')) {
            return $this->errorResponse('SYSTEM_NOT_READY', 'Hệ thống chưa sẵn sàng.', 500);
        }

        $identity = $this->normalizeIdentity((string) $request->input('identity'));

        if (!$this->isSupportedIdentity($identity)) {
            return $this->errorResponse('VALIDATION_ERROR', 'Email hoặc số điện thoại không đúng định dạng.', 422, [
                'identity' => ['Email hoặc số điện thoại không đúng định dạng.'],
            ]);
        }

        $user = $this->findUserByIdentity($identity);
        if (!$user) {
            return $this->errorResponse(
                'ACCOUNT_NOT_FOUND',
                $this->isEmailIdentity($identity)
                    ? 'Không tìm thấy tài khoản với email này.'
                    : 'Không tìm thấy tài khoản với số điện thoại này.',
                404
            );
        }

        if ($this->isEmailIdentity($identity)) {
            $phone = trim((string) ($user->phone ?? ''));
            if (!$this->hasValidPhone($phone)) {
                return $this->errorResponse('PHONE_NOT_FOUND', 'Tài khoản này chưa cập nhật số điện thoại trong hồ sơ.', 422);
            }

            return $this->successResponse([
                'identity' => $identity,
                'masked_phone' => $this->maskPhoneIdentity($phone),
                'requires_phone_confirmation' => true,
                'verification_target' => 'phone',
            ], 'Vui lòng xác nhận số điện thoại đã liên kết trước khi gửi mã OTP.');
        }

        $email = trim((string) ($user->email ?? ''));
        if (! SafeEmail::isValid($email)) {
            return $this->errorResponse('EMAIL_NOT_FOUND', 'Tài khoản này chưa cập nhật email trong hồ sơ.', 422);
        }

        return $this->successResponse([
            'identity' => $identity,
            'masked_email' => $this->maskEmail($email),
            'requires_email_confirmation' => true,
            'verification_target' => 'email',
        ], 'Vui lòng xác nhận email khôi phục trước khi gửi mã OTP.');
    }

    public function forgot(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'identity' => ['required', 'string', 'max:255'],
            'confirmed_email' => ['nullable', 'boolean'],
            'email_confirmation' => ['nullable', 'string', 'max:255'],
            'confirmed_phone' => ['nullable', 'boolean'],
            'phone_confirmation' => ['nullable', 'string', 'max:50'],
        ], [
            'identity.required' => 'Vui lòng nhập email hoặc số điện thoại.',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Dữ liệu đầu vào không hợp lệ.', 422, $validator->errors()->toArray());
        }

        $identity = $this->normalizeIdentity((string) $request->input('identity'));

        if (!$this->isSupportedIdentity($identity)) {
            return $this->errorResponse('VALIDATION_ERROR', 'Email hoặc số điện thoại không đúng định dạng.', 422, [
                'identity' => ['Email hoặc số điện thoại không đúng định dạng.'],
            ]);
        }

        if (!Schema::hasTable('users') || !Schema::hasTable('password_reset_tokens')) {
            return $this->errorResponse('SYSTEM_NOT_READY', 'Hệ thống chưa sẵn sàng.', 500);
        }

        $user = $this->findUserByIdentity($identity);
        $rateLimitIdentity = $user ? $this->resetIdentityKey($identity, $user) : $identity;
        $rateLimitKey = $this->forgotRateLimitKey($rateLimitIdentity);

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::FORGOT_RATE_LIMIT_MAX_ATTEMPTS)) {
            return $this->errorResponse('RATE_LIMITED', 'Bạn đã gửi mã quá nhiều lần. Vui lòng thử lại sau ít phút.', 429, [
                'retry_after_seconds' => RateLimiter::availableIn($rateLimitKey),
            ]);
        }

        RateLimiter::hit($rateLimitKey, self::FORGOT_RATE_LIMIT_DECAY_SECONDS);

        if (!$user) {
            return $this->errorResponse(
                'ACCOUNT_NOT_FOUND',
                $this->isEmailIdentity($identity)
                    ? 'Không tìm thấy tài khoản với email này.'
                    : 'Không tìm thấy tài khoản với số điện thoại này.',
                404
            );
        }

        if ($this->isEmailIdentity($identity)) {
            $phone = trim((string) ($user->phone ?? ''));

            if (!$this->hasValidPhone($phone)) {
                return $this->errorResponse('PHONE_NOT_FOUND', 'Tài khoản này chưa cập nhật số điện thoại trong hồ sơ.', 422);
            }

            if (!$request->boolean('confirmed_phone')) {
                return $this->errorResponse('CONFIRM_PHONE_REQUIRED', 'Vui lòng xác nhận số điện thoại đã liên kết trước khi gửi mã OTP.', 409, [
                    'masked_phone' => $this->maskPhoneIdentity($phone),
                ]);
            }

            $phoneConfirmation = trim((string) $request->input('phone_confirmation', ''));
            if ($phoneConfirmation === '') {
                return $this->errorResponse('PHONE_CONFIRMATION_REQUIRED', 'Vui lòng nhập đầy đủ số điện thoại đã liên kết với tài khoản.', 422, [
                    'masked_phone' => $this->maskPhoneIdentity($phone),
                ]);
            }

            if (!$this->hasValidPhone($phoneConfirmation)) {
                return $this->errorResponse('VALIDATION_ERROR', 'Số điện thoại xác nhận không đúng định dạng.', 422, [
                    'phone_confirmation' => ['Số điện thoại xác nhận không đúng định dạng.'],
                ]);
            }

            if (!$this->phoneMatches($phone, $phoneConfirmation)) {
                return $this->errorResponse('PHONE_CONFIRMATION_MISMATCH', 'Số điện thoại xác nhận không khớp với số điện thoại đã liên kết.', 422, [
                    'masked_phone' => $this->maskPhoneIdentity($phone),
                ]);
            }
        } else {
            $email = trim((string) ($user->email ?? ''));

            if (! SafeEmail::isValid($email)) {
                return $this->errorResponse('EMAIL_NOT_FOUND', 'Tài khoản này chưa cập nhật email trong hồ sơ.', 422);
            }

            if (!$request->boolean('confirmed_email')) {
                return $this->errorResponse('CONFIRM_EMAIL_REQUIRED', 'Vui lòng xác nhận email khôi phục trước khi gửi mã OTP.', 409, [
                    'masked_email' => $this->maskEmail($email),
                ]);
            }

            $emailConfirmation = trim((string) $request->input('email_confirmation', ''));
            if ($emailConfirmation === '') {
                return $this->errorResponse('EMAIL_CONFIRMATION_REQUIRED', 'Vui lòng nhập đầy đủ email đã liên kết với tài khoản.', 422, [
                    'masked_email' => $this->maskEmail($email),
                ]);
            }

            if (! SafeEmail::isValid($emailConfirmation)) {
                return $this->errorResponse('VALIDATION_ERROR', 'Email xác nhận không đúng định dạng.', 422, [
                    'email_confirmation' => ['Email xác nhận không đúng định dạng.'],
                ]);
            }

            if (! hash_equals(SafeEmail::normalize($email), SafeEmail::normalize($emailConfirmation))) {
                return $this->errorResponse('EMAIL_CONFIRMATION_MISMATCH', 'Email xác nhận không khớp với email đã liên kết.', 422, [
                    'masked_email' => $this->maskEmail($email),
                ]);
            }
        }

        $otp = $this->generateOtp();
        $resetIdentity = $this->resetIdentityKey($identity, $user);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $resetIdentity],
            [
                'token' => $otp,
                'created_at' => now(),
            ]
        );

        if (!$this->sendOtp($user, $identity, $otp)) {
            DB::table('password_reset_tokens')->where('email', $resetIdentity)->delete();

            return $this->errorResponse(
                'OTP_DELIVERY_FAILED',
                'Không thể gửi mã xác nhận lúc này. Vui lòng thử lại sau.',
                500
            );
        }

        return $this->successResponse([
            'message' => 'Mã xác nhận đã được gửi đến email đã đăng ký.',
            'expires_in_minutes' => self::OTP_EXPIRY_MINUTES,
        ], 'Mã xác nhận đã được gửi đến email đã đăng ký.');
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'identity' => ['required', 'string', 'max:255'],
            'otp' => ['required', 'digits:6'],
        ], [
            'identity.required' => 'Vui lòng nhập email hoặc số điện thoại.',
            'otp.required' => 'Vui lòng nhập mã OTP.',
            'otp.digits' => 'Mã OTP phải gồm 6 chữ số.',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Dữ liệu đầu vào không hợp lệ.', 422, $validator->errors()->toArray());
        }

        $identity = $this->normalizeIdentity((string) $request->input('identity'));
        $otp = (string) $request->input('otp');

        $verification = $this->validateOtp($identity, $otp);

        if (!$verification['ok']) {
            return $this->otpErrorResponse($verification);
        }

        return $this->successResponse([
            'message' => 'Mã OTP hợp lệ.',
            'identity' => $identity,
            'temporary_token' => $this->temporaryToken($verification['reset_identity'], $otp, (string) $verification['record']->created_at),
            'expires_in_minutes' => self::OTP_EXPIRY_MINUTES,
        ], 'Mã OTP hợp lệ.');
    }

    public function reset(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'identity' => ['required', 'string', 'max:255'],
            'otp' => ['required', 'digits:6'],
            'temporary_token' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'password_confirmation' => ['required', 'string', 'same:password'],
        ], [
            'identity.required' => 'Vui lòng nhập email hoặc số điện thoại.',
            'otp.required' => 'Vui lòng nhập mã OTP.',
            'otp.digits' => 'Mã OTP phải gồm 6 chữ số.',
            'password.required' => 'Vui lòng nhập mật khẩu mới.',
            'password.min' => 'Mật khẩu mới phải có ít nhất 6 ký tự.',
            'password_confirmation.required' => 'Vui lòng xác nhận mật khẩu mới.',
            'password_confirmation.same' => 'Mật khẩu xác nhận không khớp.',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Dữ liệu đầu vào không hợp lệ.', 422, $validator->errors()->toArray());
        }

        $identity = $this->normalizeIdentity((string) $request->input('identity'));
        $otp = (string) $request->input('otp');
        $temporaryToken = $request->filled('temporary_token') ? (string) $request->input('temporary_token') : null;

        $verification = $this->validateOtp($identity, $otp);

        if (!$verification['ok']) {
            return $this->otpErrorResponse($verification);
        }

        if ($temporaryToken !== null) {
            $expectedTemporaryToken = $this->temporaryToken(
                $verification['reset_identity'],
                $otp,
                (string) $verification['record']->created_at
            );

            if (!hash_equals($expectedTemporaryToken, $temporaryToken)) {
                return $this->errorResponse('INVALID_TEMPORARY_TOKEN', 'Phiên xác thực OTP không hợp lệ.', 400);
            }
        }

        DB::transaction(function () use ($verification, $request): void {
            /** @var User $user */
            $user = $verification['user'];
            $user->password = Hash::make((string) $request->input('password'));
            $user->save();

            $user->tokens()->delete();

            DB::table('password_reset_tokens')
                ->where('email', $verification['reset_identity'])
                ->delete();
        });

        return $this->successResponse([
            'message' => 'Mật khẩu đã được đổi thành công. Vui lòng đăng nhập lại.',
        ], 'Mật khẩu đã được đổi thành công. Vui lòng đăng nhập lại.');
    }

    private function validateOtp(string $identity, string $otp): array
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('password_reset_tokens')) {
            return [
                'ok' => false,
                'code' => 'SYSTEM_NOT_READY',
                'message' => 'Hệ thống chưa sẵn sàng.',
                'status' => 500,
            ];
        }

        $user = $this->findUserByIdentity($identity);
        if (!$user) {
            return [
                'ok' => false,
                'code' => 'INVALID_OTP',
                'message' => 'Mã OTP không hợp lệ hoặc đã hết hạn.',
                'status' => 400,
            ];
        }

        $resetIdentity = $this->resetIdentityKey($identity, $user);
        $record = DB::table('password_reset_tokens')
            ->where('email', $resetIdentity)
            ->first();

        if (!$record || !hash_equals((string) $record->token, $otp)) {
            return [
                'ok' => false,
                'code' => 'INVALID_OTP',
                'message' => 'Mã OTP không hợp lệ hoặc đã hết hạn.',
                'status' => 400,
            ];
        }

        if (!$this->isOtpFresh($record->created_at)) {
            DB::table('password_reset_tokens')->where('email', $resetIdentity)->delete();

            return [
                'ok' => false,
                'code' => 'EXPIRED_OTP',
                'message' => 'Mã OTP đã hết hạn. Vui lòng gửi lại mã mới.',
                'status' => 400,
            ];
        }

        return [
            'ok' => true,
            'user' => $user,
            'record' => $record,
            'reset_identity' => $resetIdentity,
        ];
    }

    private function findUserByIdentity(string $identity): ?User
    {
        if ($this->isEmailIdentity($identity)) {
            return User::query()
                ->whereRaw('LOWER(email) = ?', [SafeEmail::normalize($identity)])
                ->first();
        }

        $phoneCandidates = $this->phoneLookupCandidates($identity);
        if (empty($phoneCandidates)) {
            return null;
        }

        return User::query()
            ->whereIn('phone', $phoneCandidates)
            ->first();
    }

    protected function phoneLookupCandidates(mixed $identity): array
    {
        $raw = trim((string) $identity);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return [];
        }

        $candidates = [$raw, $digits, '+' . $digits];

        if (Str::startsWith($digits, '84') && strlen($digits) > 2) {
            $withoutCountryCode = substr($digits, 2);
            $candidates[] = '0' . $withoutCountryCode;
            $candidates[] = '+84' . $withoutCountryCode;
        }

        if (Str::startsWith($digits, '0') && strlen($digits) > 1) {
            $withoutLeadingZero = substr($digits, 1);
            $candidates[] = '84' . $withoutLeadingZero;
            $candidates[] = '+84' . $withoutLeadingZero;
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $value): bool => $value !== '')));
    }

    private function hasValidPhone(string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) >= 9 && strlen($digits) <= 15;
    }

    private function phoneMatches(string $expectedPhone, string $actualPhone): bool
    {
        $expectedCandidates = $this->phoneLookupCandidates($expectedPhone);
        $actualCandidates = $this->phoneLookupCandidates($actualPhone);

        return count(array_intersect($expectedCandidates, $actualCandidates)) > 0;
    }

    private function maskPhoneIdentity(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }

        return str_repeat('*', max(1, strlen($digits) - 4)) . substr($digits, -4);
    }

    private function sendOtp(User $user, string $identity, string $otp): bool
    {
        $targetEmail = $this->deliveryEmailFor($user, $identity);

        if (SafeEmail::isValid($targetEmail)) {
            $targetEmail = SafeEmail::normalize($targetEmail);
            try {
                Mail::to($targetEmail)->send(new PasswordResetOtpMail(
                    $otp,
                    self::OTP_EXPIRY_MINUTES,
                    $this->maskIdentity($identity)
                ));

                return true;
            } catch (\Throwable $e) {
                Log::warning('Failed to send password reset OTP email.', [
                    'identity' => $this->maskIdentity($identity),
                    'email' => $this->maskIdentity($targetEmail),
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
        }

        Log::warning('Password reset OTP cannot be delivered because the account has no valid email.', [
            'identity' => $this->maskIdentity($identity),
        ]);

        return false;
    }

    private function deliveryEmailFor(User $user, string $identity): string
    {
        return $this->isEmailIdentity($identity)
            ? $identity
            : trim((string) ($user->email ?? ''));
    }

    private function isOtpFresh(mixed $createdAt): bool
    {
        if (!$createdAt) {
            return false;
        }

        try {
            return Carbon::parse($createdAt)
                ->addMinutes(self::OTP_EXPIRY_MINUTES)
                ->greaterThanOrEqualTo(now());
        } catch (\Throwable) {
            return false;
        }
    }

    private function generateOtp(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function normalizeIdentity(string $identity): string
    {
        $identity = trim($identity);

        return $this->isEmailIdentity($identity)
            ? SafeEmail::normalize($identity)
            : $identity;
    }

    private function isSupportedIdentity(string $identity): bool
    {
        if ($this->isEmailIdentity($identity)) {
            return true;
        }

        $digits = preg_replace('/\D+/', '', $identity) ?? '';

        return strlen($digits) >= 9 && strlen($digits) <= 15;
    }

    private function isEmailIdentity(string $identity): bool
    {
        return SafeEmail::isValid($identity);
    }

    private function resetIdentityKey(string $identity, User $user): string
    {
        if ($this->isEmailIdentity($identity)) {
            return SafeEmail::normalize($identity);
        }

        $phone = trim((string) ($user->phone ?? ''));

        return $phone !== '' ? $phone : $identity;
    }

    private function temporaryToken(string $resetIdentity, string $otp, string $createdAt): string
    {
        return hash_hmac(
            'sha256',
            $resetIdentity . '|' . $otp . '|' . $createdAt,
            (string) config('app.key')
        );
    }

    private function forgotRateLimitKey(string $identity): string
    {
        return 'password-reset:' . sha1(Str::lower($identity));
    }

    private function maskIdentity(string $identity): string
    {
        if ($this->isEmailIdentity($identity)) {
            return $this->maskEmail($identity);
        }

        $digits = preg_replace('/\D+/', '', $identity) ?? '';
        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }

        return $this->maskPhoneIdentity($identity);
    }

    private function maskEmail(string $email): string
    {
        if (!$this->isEmailIdentity($email)) {
            return '';
        }

        [$name, $domain] = explode('@', $email, 2);
        $nameLength = Str::length($name);

        if ($nameLength <= 2) {
            return Str::substr($name, 0, 1) . '***@' . $domain;
        }

        $prefix = Str::substr($name, 0, 2);
        $suffix = $nameLength > 5 ? Str::substr($name, -2) : '';
        $stars = str_repeat('*', max(3, $nameLength - Str::length($prefix) - Str::length($suffix)));

        return $prefix . $stars . $suffix . '@' . $domain;
    }

    private function otpErrorResponse(array $verification): JsonResponse
    {
        return $this->errorResponse(
            (string) $verification['code'],
            (string) $verification['message'],
            (int) $verification['status']
        );
    }

    private function successResponse(array $data, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => $data,
            'meta' => [
                'authenticated' => false,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], $status);
    }

    private function errorResponse(
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
            'message' => $message,
            'data' => null,
            'meta' => [
                'authenticated' => false,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [$error],
        ], $status);
    }
}
