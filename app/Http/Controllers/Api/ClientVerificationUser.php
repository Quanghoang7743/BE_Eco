<?php

namespace App\Http\Controllers\Api;

use App\Events\ClientUserVerificationUpdated;
use App\Http\Controllers\Controller;
use App\Models\ClientUserVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientVerificationUser extends Controller
{
    public function submitVerification(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Bạn cần đăng nhập để xác thực tài khoản.',
            ], 401);
        }

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'id_number' => ['required', 'string', 'regex:/^\d{9,12}$/', 'max:50'],
            'birth_date' => ['required', 'date', 'before_or_equal:' . now()->subYears(16)->format('Y-m-d')],
            'address' => ['required', 'string', 'max:2000'],
            'id_front_image' => ['required', 'string', 'max:2048'],
            'id_back_image' => ['required', 'string', 'max:2048'],
            'id_selfie_image' => ['required', 'string', 'max:2048'],
        ], [
            'id_number.regex' => 'Số CCCD/CMND phải gồm từ 9 đến 12 chữ số.',
            'birth_date.before_or_equal' => 'Bạn phải từ 16 tuổi trở lên mới được xác thực tài khoản.',
        ]);

        $verification = ClientUserVerification::query()
            ->where('user_id', $user->id)
            ->first();

        if ($verification && $verification->status === ClientUserVerification::STATUS_APPROVED) {
            return response()->json([
                'message' => 'Tài khoản của bạn đã được xác thực.',
                'data' => $this->formatVerification($verification),
            ], 409);
        }

        $duplicateIdNumberExists = ClientUserVerification::query()
            ->where('id_number', trim((string) $validated['id_number']))
            ->where('user_id', '!=', $user->id)
            ->whereIn('status', [
                ClientUserVerification::STATUS_PENDING,
                ClientUserVerification::STATUS_APPROVED,
            ])
            ->exists();

        if ($duplicateIdNumberExists) {
            return response()->json([
                'message' => 'Số CCCD này đã có người sử dụng',
                'reason' => 'duplicate_verification_id_number',
                'errors' => [
                    'id_number' => ['Số CCCD này đã có người sử dụng'],
                ],
            ], 422);
        }

        $payload = [
            'user_id' => $user->id,
            'full_name' => trim((string) $validated['full_name']),
            'id_number' => trim((string) $validated['id_number']),
            'birth_date' => $validated['birth_date'],
            'address' => trim((string) $validated['address']),
            'id_front_image' => trim((string) $validated['id_front_image']),
            'id_back_image' => trim((string) $validated['id_back_image']),
            'id_selfie_image' => trim((string) $validated['id_selfie_image']),
            'status' => ClientUserVerification::STATUS_PENDING,
            'rejection_reason' => null,
            'submitted_at' => now(),
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];

        if ($verification) {
            $verification->forceFill($payload)->save();
        } else {
            $verification = ClientUserVerification::query()->create($payload);
        }

        $verification = $verification->fresh();
        
        try {
            app(\App\Services\NotificationService::class)->create([
                'audience_scope' => 'admin_shared',
                'type' => 'system',
                'title' => 'Hồ sơ xác thực mới',
                'body' => 'Bạn có một hồ sơ xác thực mới cần duyệt từ ' . $verification->full_name . '.',
                'message' => 'Bạn có một hồ sơ xác thực mới cần duyệt từ ' . $verification->full_name . '.',
                'action_url' => '/admin/user-verifications/' . $verification->id,
            ]);
        } catch (\Throwable $e) {
            // ignore
        }

        ClientUserVerificationUpdated::dispatch(
            $verification,
            'submitted',
            $this->pendingVerificationCount()
        );

        return response()->json([
            'success' => true,
            'message' => 'Hồ sơ xác thực đã được gửi. Vui lòng chờ admin duyệt.',
            'data' => $this->formatVerification($verification),
        ], 201);
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Bạn cần đăng nhập để xem trạng thái xác thực.',
            ], 401);
        }

        $verification = ClientUserVerification::query()
            ->where('user_id', $user->id)
            ->first();
        $role = strtolower(trim((string) ($user->role ?? '')));

        return response()->json([
            'success' => true,
            'data' => $verification ? $this->formatVerification($verification) : null,
            'meta' => [
                'verification_exempt' => in_array($role, ['admin', 'super_admin'], true),
                'account_role' => $role,
            ],
        ]);
    }

    private function formatVerification(?ClientUserVerification $verification): ?array
    {
        if (! $verification) {
            return null;
        }

        return [
            'id' => $verification->id,
            'user_id' => $verification->user_id,
            'full_name' => $verification->full_name,
            'id_number' => $verification->id_number,
            'birth_date' => optional($verification->birth_date)->toDateString(),
            'address' => $verification->address,
            'id_front_image' => $verification->id_front_image,
            'id_back_image' => $verification->id_back_image,
            'id_selfie_image' => $verification->id_selfie_image,
            'status' => $verification->status,
            'rejection_reason' => $verification->rejection_reason,
            'submitted_at' => optional($verification->submitted_at)->toISOString(),
            'reviewed_at' => optional($verification->reviewed_at)->toISOString(),
        ];
    }

    private function pendingVerificationCount(): int
    {
        return ClientUserVerification::query()
            ->where('status', ClientUserVerification::STATUS_PENDING)
            ->count();
    }
}
