<?php

namespace App\Http\Controllers\Api;

use App\Events\AffiliateMemberRegistered;
use App\Models\CommissionUser;
use App\Models\Order;
use App\Models\SpecialistAuditLog;
use App\Models\User;
use App\Services\AffiliateCustomerService;
use App\Support\SafeEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;

class ClientUserController extends ClientController
{
    public function login(Request $request)
    {
        $validated = $request->validate([
            'phone' => 'required',
            'password' => 'required',
        ]);
        $user = User::where('phone', $validated['phone'])->first();
        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Thong tin dang nhap khong chinh xac.'
            ], 401);
        }

        if (! $user->isActiveForAuth()) {
            return response()->json([
                'message' => 'Tai khoan dang bi khoa hoac khong con hoat dong.'
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    public function register(Request $request)
    {
        $input = $request->all();
        if (array_key_exists('email', $input)) {
            $input['email'] = SafeEmail::normalizeForValidation($input['email']);
        }

        $validator = Validator::make($input, [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20|unique:users,phone',
            'password' => 'required|string|min:6',
            'email' => ['nullable', 'string', 'max:255', SafeEmail::validationRule(), Rule::unique('users', 'email')],
            'full_name' => 'nullable|string|max:255',
            'referrer_id'      => 'nullable|string|max:50',
            'referral_source_type' => 'nullable|string|max:100',
            'referral_source_url' => 'nullable|string|max:2048',
            'referral_clicked_at' => 'nullable|string|max:100',
        ], [
            'name.required' => 'Vui long nhap ten.',
            'phone.required' => 'Vui long nhap so dien thoai.',
            'phone.unique' => 'So dien thoai da ton tai.',
            'password.required' => 'Vui long nhap mat khau.',
            'password.min' => 'Mat khau phai co it nhat 6 ky tu.',
            'email.email' => 'Email khong dung dinh dang.',
            'email.unique' => 'Email da ton tai.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Du lieu khong hop le.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $data['email'] = SafeEmail::normalizeNullable($data['email'] ?? null);

        // Get referrer_id from body or from 'ref' query parameter
        $referrerInput = $request->input('referrer_id') ?? $request->query('ref');

        // Validate referrer exists and resolve it
        $referrerId = User::resolveReferrerId($referrerInput);

        $user = User::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'password' => $data['password'],
            'referrer_id' => $referrerId,
        ]);

        $assignment = null;
        if ($referrerId) {
            $assignment = app(AffiliateCustomerService::class)->createRegistrationAssignment($user, (int) $referrerId, [
                'source_type' => $request->input('referral_source_type'),
                'source_id' => $referrerInput,
                'source_url' => $request->input('referral_source_url'),
                'clicked_at' => $request->input('referral_clicked_at'),
            ], $request);
        }

        if ($referrerId && $assignment) {
            event(new AffiliateMemberRegistered((int) $referrerId, [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'referrer_id' => $user->referrer_id,
                'assignment_id' => $assignment->id,
                'source_type' => $assignment->source_type,
                'source_id' => $assignment->source_id,
                'created_at' => optional($user->created_at)?->toIso8601String(),
            ]));
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Dang ky thanh cong.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ], 201);
    }

    public function getMembers(Request $request)
    {
        $authHeader = $request->header('Authorization');
        $user = null;

        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken) {
                $user = $accessToken->tokenable;
            }
        }

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $query = $request->input('q');
        $allRelatedUsers = collect();

        $currentLevelUsers = collect([$user]);
        $maxDepth = 1;
        for ($depth = 1; $depth <= $maxDepth; $depth++) {
            $nextLevelUsers = User::whereIn('referrer_id', $currentLevelUsers->pluck('id'))->get();

            foreach ($nextLevelUsers as $refUser) {
                $allRelatedUsers->push((object) [
                    'user' => $refUser,
                    'level' => 'F' . $depth,
                    'direction' => 'down'
                ]);
            }

            if ($nextLevelUsers->isEmpty()) break;
            $currentLevelUsers = $nextLevelUsers;
        }

        if ($query) {
            $allRelatedUsers = $allRelatedUsers->filter(function ($item) use ($query) {
                $u = $item->user;
                return str_contains($u->full_name, $query)
                    || str_contains($u->phone, $query)
                    || (string) $u->id === $query;
            })->values();
        }

        // Batch all aggregate lookups into grouped queries instead of running
        // 2 queries per related user (fixes N+1).
        $memberIds = $allRelatedUsers->map(fn ($item) => (int) $item->user->id)->all();
        $salesMap = $this->calculateAffiliateSalesAmountForUsers($memberIds);
        $commissionDirectMap = $this->calculateAffiliateCommissionAmountForUsers($memberIds, true);
        $commissionHistoryMap = $this->calculateAffiliateCommissionFromOrderHistoryForUsers($memberIds, true);

        $members = $allRelatedUsers->map(function ($item) use ($salesMap, $commissionDirectMap, $commissionHistoryMap) {
            $user = $item->user;
            $uid = (int) $user->id;

            $userSaleCompleted = $salesMap[$uid] ?? 0.0;

            $userSale = $userSaleCompleted;

            $commissionCompleted = $item->direction === 'self'
                ? ($commissionDirectMap[$uid] ?? 0.0)
                : ($commissionHistoryMap[$uid] ?? 0.0);

            return (object) [
                'id' => $user->id,
                'name' => $user->full_name ?? $user->name,
                'phone' => $user->phone,
                'avatar' => $user->avatar ?? null,
                'joined_at' => $user->created_at,
                'personal_sales_completed' => $userSaleCompleted,
                'personal_sales' => $userSale,
                'commission' => $commissionCompleted,
                'level' => $item->level,
                'direction' => $item->direction,
            ];
        });

        return response()->json([
            'members' => $members->values(),
            'query' => $query,
        ]);
    }

    public function updateUser(Request $request)
    {
        $user = null;
        $authHeader = $request->header('Authorization');
        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken) {
                $user = $accessToken->tokenable;
            }
        }

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $user = User::where('id', $user->id)->first();

        $input = $request->all();
        if (array_key_exists('email', $input)) {
            $input['email'] = SafeEmail::normalizeForValidation($input['email']);
        }

        $validated = Validator::make($input, [
            'full_name'                => 'nullable|string|max:255',
            'email'                    => ['nullable', 'string', 'max:255', SafeEmail::validationRule(), Rule::unique('users', 'email')->ignore($user->id)],
            'phone'                    => 'nullable|string|max:20',
            'bio'                      => 'nullable|string|max:500',
            'gender'                   => 'nullable|in:male,female,other',
            'birthday'                 => 'nullable|date',
            'personal_full_name'       => 'nullable|string|max:255',
            'personal_identity_number' => 'nullable|string|max:50',
            'personal_address'         => 'nullable|string|max:255',
            'avatar'                   => 'nullable|image|max:2048',
        ])->validate();

        if (array_key_exists('email', $validated)) {
            $validated['email'] = SafeEmail::normalizeNullable($validated['email']);
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }
            $validated['avatar'] = $request->file('avatar')->store('avatars', 'public');
        }

        // Xóa key 'avatar' nếu không có file upload (tránh lưu null/string rỗng đè ảnh cũ)
        if (!$request->hasFile('avatar')) {
            unset($validated['avatar']);
        }

        $user->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật thành công',
            'data'    => $this->formatUserResponse($user->fresh()),
        ]);
    }

    public function updateAvatar(Request $request, $userId)
    {
        $authUser = null;
        $authHeader = $request->header('Authorization');
        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken) {
                $authUser = $accessToken->tokenable;
            }
        }

        if (!$authUser || (string) $authUser->id !== (string) $userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'avatar' => 'required|image|max:4096',
        ]);

        $user = User::findOrFail($userId);

        // Xóa ảnh cũ
        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->avatar = $path;
        $user->save();

        return response()->json([
            'success'   => true,
            'message'   => 'Cập nhật ảnh đại diện thành công',
            'data'      => $this->formatUserResponse($user->fresh()),
        ]);
    }

    private function formatUserResponse(User $user): array
    {
        return [
            'id'                       => $user->id,
            'name'                     => $user->name,
            'full_name'                => $user->full_name,
            'display_name'             => $user->full_name ?? $user->name,
            'email'                    => $user->email,
            'phone'                    => $user->phone,
            'avatar_url'               => $user->avatar ?? null,
            'bio'                      => $user->bio,
            'gender'                   => $user->gender,
            'birthday'                 => $user->birthday,
            'personal_full_name'       => $user->personal_full_name,
            'personal_identity_number' => $user->personal_identity_number,
            'personal_address'         => $user->personal_address,
            'zalo_id'                  => $user->zalo_id,
            'total_orders'             => $user->total_orders ?? 0,
            'total_wins'               => $user->total_wins ?? 0,
            'ticket_balance'           => $user->ticket_balance ?? 0,
            'joined_at'                => $user->created_at,
        ];
    }

    public function zaloSignup(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'id' => 'nullable|string|max:255',
            'avatar' => 'nullable|string',
            'phone' => 'nullable|string',
            'referrer_id' => 'nullable|string|max:50',
            'referral_source_type' => 'nullable|string|max:100',
            'referral_source_url' => 'nullable|string|max:2048',
            'referral_clicked_at' => 'nullable|string|max:100',
        ]);

        if (!empty($validated['phone'])) {
            $user = User::where('phone', $validated['phone'])->first();
            if ($user) {
                if (! $user->isActiveForAuth()) {
                    return response()->json([
                        'message' => 'Tai khoan dang bi khoa hoac khong con hoat dong.'
                    ], 403);
                }

                $token = $user->createToken('zalo-login')->plainTextToken;
                return response()->json([
                    'token' => $token,
                    'user' => $user,
                    'message' => 'Dang nhap thanh cong!',
                ]);
            }
        }

        if (isset($validated['name']) && str_starts_with($validated['name'], 'User')) {
            $validated['name'] = 'user name';
        }

        $referrerId = User::resolveReferrerId($request->referrer_id);
        $validated['referrer_id'] = $referrerId;

        $user = User::create([
            'name' => 'user' . ($validated['id'] ?? uniqid()),
            'full_name' => $validated['name'],
            'phone' => $validated['phone'],
            'avatar' => $validated['avatar'],
            'referrer_id' => $validated['referrer_id'],
            'role' => 2,
            'password' => Hash::make('123456'),
        ]);

        $assignment = null;
        if ($validated['referrer_id']) {
            $assignment = app(AffiliateCustomerService::class)->createRegistrationAssignment($user, (int) $validated['referrer_id'], [
                'source_type' => $request->input('referral_source_type'),
                'source_id' => $request->input('referrer_id'),
                'source_url' => $request->input('referral_source_url'),
                'clicked_at' => $request->input('referral_clicked_at'),
            ], $request);
        }

        if ($validated['referrer_id'] && $assignment) {
            event(new AffiliateMemberRegistered((int) $validated['referrer_id'], [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'referrer_id' => $user->referrer_id,
                'assignment_id' => $assignment->id,
                'source_type' => $assignment->source_type,
                'source_id' => $assignment->source_id,
                'created_at' => optional($user->created_at)?->toIso8601String(),
            ]));
        }

        if ($validated['referrer_id']) {
            $userF2 = User::find($validated['referrer_id']);

            if ($userF2) {
                $hasHighValueCombo = DB::table('orders')
                    ->join('order_items', 'orders.id', '=', 'order_items.order_id')
                    ->join('products', 'order_items.product_id', '=', 'products.id')
                    ->where('orders.user_id', $userF2->id)
                    ->where('orders.status', 'completed')
                    ->where('products.category_id', 2)
                    ->where('products.sale_price', '>=', 1000000)
                    ->exists();

                CommissionUser::create([
                    'user_id' => $userF2->id,
                    'referred_user_id' => $user->id,
                    'level' => 1,
                    'amount' => 350000,
                    'status' => 'pending',
                    'agency_rights' => $hasHighValueCombo,
                ]);

            }
        }

        $token = $user->createToken('zalo-login')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
            'message' => 'Dang ky va dang nhap thanh cong',
        ]);
    }

    public function me(Request $request)
    {
        $user = $this->resolveApiUser($request);

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json($this->formatUserResponse($user));
    }

    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'string'],
            'new_password' => [
                'required',
                'string',
                'max:255',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
                'confirmed',
            ],
            'new_password_confirmation' => ['required', 'string'],
        ], [
            'current_password.required' => 'Vui lòng nhập mật khẩu hiện tại.',
            'new_password.required' => 'Vui lòng nhập mật khẩu mới.',
            'new_password.max' => 'Mật khẩu mới không được vượt quá 255 ký tự.',
            'new_password.confirmed' => 'Mật khẩu xác nhận không khớp.',
            'new_password_confirmation.required' => 'Vui lòng xác nhận mật khẩu mới.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu đầu vào không hợp lệ.',
                'errors' => $validator->errors(),
            ], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $currentPassword = (string) $request->input('current_password');
        $newPassword = (string) $request->input('new_password');

        if (! Hash::check($currentPassword, (string) $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Mật khẩu hiện tại không chính xác.',
                'errors' => [
                    'current_password' => ['Mật khẩu hiện tại không chính xác.'],
                ],
            ], 422);
        }

        if (Hash::check($newPassword, (string) $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Mật khẩu mới phải khác mật khẩu hiện tại.',
                'errors' => [
                    'new_password' => ['Mật khẩu mới phải khác mật khẩu hiện tại.'],
                ],
            ], 422);
        }

        $currentAccessToken = $user->currentAccessToken();
        $revokedTokenCount = 0;

        DB::transaction(function () use (
            $request,
            $user,
            $newPassword,
            $currentAccessToken,
            &$revokedTokenCount
        ): void {
            $user->forceFill([
                'password' => Hash::make($newPassword),
            ])->save();

            $tokenQuery = $user->tokens();
            if ($currentAccessToken instanceof PersonalAccessToken) {
                $tokenQuery->where('id', '!=', $currentAccessToken->getKey());
            }
            $revokedTokenCount = $tokenQuery->delete();

            if (
                in_array(strtolower((string) $user->role), ['sale', 'specialist'], true)
                && Schema::hasTable('specialist_audit_logs')
            ) {
                SpecialistAuditLog::query()->create([
                    'specialist_id' => $user->id,
                    'actor_id' => $user->id,
                    'action' => 'password_changed',
                    'metadata' => [
                        'revoked_token_count' => $revokedTokenCount,
                        'current_token_retained' => $currentAccessToken instanceof PersonalAccessToken,
                    ],
                    'ip_address' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                ]);
            }
        });

        Log::info('User changed password', [
            'user_id' => $user->id,
            'role' => $user->role,
            'revoked_token_count' => $revokedTokenCount,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Đổi mật khẩu thành công. Các thiết bị khác đã được đăng xuất.',
        ]);
    }

    public function checkReceiver(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ], [
            'phone.required' => 'Số điện thoại là bắt buộc.',
            'phone.max' => 'Số điện thoại không được vượt quá 20 ký tự.',
        ]);

        $phoneCandidates = $this->phoneLookupCandidates($validated['phone']);
        if (empty($phoneCandidates)) {
            return response()->json([
                'success' => false,
                'message' => 'Số điện thoại không hợp lệ.',
            ], 422);
        }

        $receiver = User::query()
            ->whereIn('phone', $phoneCandidates)
            ->first();

        if (!$receiver) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy người nhận.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (string) $receiver->id,
                'name' => (string) ($receiver->full_name ?? $receiver->name ?? ''),
                'phone' => (string) ($receiver->phone ?? ''),
                'avatar' => $receiver->avatar ?? $receiver->avatar_url ?? null,
            ],
        ]);
    }

    public function getZaloUserInfo(Request $request)
    {
        $accessToken = $request->input('access_token');
        $code = $request->input('code');

        if (!$accessToken || !$code) {
            return response()->json(['message' => 'Thieu access_token hoac code'], 400);
        }

        $response = Http::withHeaders([
            'access_token' => $accessToken,
            'code' => $code,
            'secret_key' => env('ZALO_SECRET_KEY'),
        ])->get('https://graph.zalo.me/v2.0/me/info');

        if ($response->successful()) {
            return response()->json($response->json());
        }

        return response()->json([
            'message' => 'Loi khi goi Zalo API',
            'error' => $response->body(),
        ], $response->status());
    }
}
