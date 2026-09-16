<?php

namespace App\Http\Controllers\Api;

use App\Models\Complaint;
use App\Models\ComplaintMessage;
use App\Models\CommissionUser;
use App\Models\AffiliateProfile;
use App\Models\AffiliateWithdrawalRequest;
use App\Models\BankAccount;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\AffiliateCustomerService;
use App\Services\AffiliateProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ClientAffiliateController extends ClientController
{
    public function __construct(
        private readonly AffiliateProfileService $affiliateProfileService,
        private readonly AffiliateCustomerService $affiliateCustomerService,
    ) {
    }

    public function upload(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'file' => ['required', 'file', 'image', 'max:5120'],
            'type' => ['nullable', 'in:id_front,id_back,id_selfie,other'],
        ]);

        $type = (string) ($validated['type'] ?? 'other');
        $file = $validated['file'];

        $directory = 'uploads/affiliate/' . (string) $user->id . '/' . $type;
        $path = $file->store($directory, 'public');

        return response()->json([
            'success' => true,
            'message' => 'Upload ảnh thành công.',
            'data' => [
                'type' => $type,
                'path' => $path,
                'url' => asset('storage/' . $path),
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ],
        ], 201);
    }

    public function checkIdNumber(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'id_number' => ['required', 'string', 'regex:/^\d{9,12}$/', 'max:50'],
        ], [
            'id_number.required' => 'Vui lòng nhập số CCCD/CMND.',
            'id_number.regex' => 'Số CCCD/CMND phải gồm từ 9 đến 12 chữ số.',
        ]);

        $idNumber = trim((string) $validated['id_number']);
        $duplicateExists = AffiliateProfile::query()
            ->whereIn('status', [
                AffiliateProfile::STATUS_APPROVED,
                AffiliateProfile::STATUS_LOCKED,
            ])
            ->where('id_number', $idNumber)
            ->where('user_id', '!=', $user->id)
            ->exists();

        if ($duplicateExists) {
            return response()->json([
                'success' => true,
                'message' => 'Số CCCD này đã có người sử dụng',
                'data' => [
                    'available' => false,
                    'reason' => 'duplicate_affiliate_id_number',
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'available' => true,
            ],
        ]);
    }

    public function register(Request $request)
    {
        $user = $request->user();

        $existingProfile = AffiliateProfile::query()->where('user_id', $user->id)->first();
        if ($existingProfile && $existingProfile->status === AffiliateProfile::STATUS_UNDER_REVIEW) {
            return response()->json([
                'message' => 'Hồ sơ Affiliate của bạn đang được admin kiểm tra và tạm thời không thể chỉnh sửa.',
                'data' => [
                    'status' => $existingProfile->status,
                    'profile' => $this->affiliateProfileService->formatProfile($existingProfile),
                ],
            ], 409);
        }

        if ($existingProfile && $existingProfile->status === AffiliateProfile::STATUS_APPROVED) {
            return response()->json([
                'message' => 'Hồ sơ Affiliate của bạn đã được duyệt.',
                'data' => [
                    'status' => $existingProfile->status,
                    'profile' => $this->affiliateProfileService->formatProfile($existingProfile),
                ],
            ], 409);
        }

        if ($existingProfile && in_array($existingProfile->status, [
            AffiliateProfile::STATUS_SUSPENDED,
            AffiliateProfile::STATUS_LOCKED,
            AffiliateProfile::STATUS_TERMINATED,
        ], true)) {
            return response()->json([
                'message' => 'Hồ sơ Affiliate của bạn đang bị khóa hoặc tạm dừng. Vui lòng liên hệ Admin.',
            ], 423);
        }

        $validated = $request->validate([
            'submission_action' => ['nullable', 'in:draft,submit'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'birthday' => ['nullable', 'date'],
            'contact_address' => ['nullable', 'string', 'max:255'],
            'permanent_address' => ['nullable', 'string', 'max:255'],
            'id_number' => ['nullable', 'string', 'max:50'],
            'id_front_image' => ['nullable', 'string', 'max:255'],
            'id_back_image' => ['nullable', 'string', 'max:255'],
            'id_selfie_image' => ['nullable', 'string', 'max:255'],
            'personal_tax_code' => ['nullable', 'string', 'max:50'],
            'social_media_links' => ['nullable', 'array', 'max:10'],
            'social_media_links.*' => ['nullable', 'string', 'max:255'],
            'bank_id' => ['nullable', 'integer', 'exists:banks,id'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'accepted_policy' => ['nullable', 'boolean'],
            'accepted_no_fake_order_commitment' => ['nullable', 'boolean'],
            'accepted_tax_bank_accuracy_commitment' => ['nullable', 'boolean'],
            'accepted_communication_commitment' => ['nullable', 'boolean'],
            'policy_version' => ['nullable', 'string', 'max:50'],
        ]);

        $submissionAction = (string) ($validated['submission_action'] ?? 'submit');
        $existingFormattedProfile = $existingProfile
            ? $this->affiliateProfileService->formatProfile($existingProfile)
            : null;
        if ($submissionAction === 'submit') {
            $validationReasons = $this->affiliateProfileService->validateSubmission($user, $validated, $existingProfile);
            if (count($validationReasons) > 0) {
                return response()->json([
                    'message' => $validationReasons[0],
                    'errors' => $validationReasons,
                ], 422);
            }
        }

        $affiliateCode = $submissionAction === 'submit'
            ? (($existingFormattedProfile['affiliate_code'] ?? null) ?: $this->affiliateProfileService->generateAffiliateCode())
            : ($existingFormattedProfile['affiliate_code'] ?? null);

        $payload = [
            'id_number' => trim((string) ($validated['id_number'] ?? '')),
            'id_front_image' => trim((string) ($validated['id_front_image'] ?? '')),
            'id_back_image' => trim((string) ($validated['id_back_image'] ?? '')),
            'id_selfie_image' => trim((string) ($validated['id_selfie_image'] ?? '')),
            'personal_tax_code' => trim((string) ($validated['personal_tax_code'] ?? '')),
            'social_media_links' => $validated['social_media_links'] ?? ($existingFormattedProfile['social_media_links'] ?? null),
            'status' => $submissionAction === 'draft'
                ? AffiliateProfile::STATUS_DRAFT
                : AffiliateProfile::STATUS_SUBMITTED,
            'rejection_reason' => null,
            'need_more_info_reason' => null,
            'accepted_policy' => (bool) ($validated['accepted_policy'] ?? false),
            'accepted_no_fake_order_commitment' => (bool) ($validated['accepted_no_fake_order_commitment'] ?? false),
            'accepted_tax_bank_accuracy_commitment' => (bool) ($validated['accepted_tax_bank_accuracy_commitment'] ?? false),
            'accepted_communication_commitment' => (bool) ($validated['accepted_communication_commitment'] ?? false),
            'accepted_at' => $submissionAction === 'submit' ? now() : null,
            'policy_version' => $validated['policy_version'] ?? null,
            'affiliate_code' => $affiliateCode,
            'affiliate_link' => $submissionAction === 'submit'
                ? (($existingFormattedProfile['affiliate_link'] ?? null) ?: $this->affiliateProfileService->generateAffiliateLink(new AffiliateProfile(['user_id' => $user->id]), $affiliateCode))
                : ($existingFormattedProfile['affiliate_link'] ?? null),
            'submitted_at' => $submissionAction === 'submit'
                ? now()
                : ($existingFormattedProfile['submitted_at'] ?? $existingProfile?->submitted_at),
        ];

        [$profile, $bankAccount] = DB::transaction(function () use ($user, $payload, $validated, $existingProfile) {
            $this->affiliateProfileService->syncUserProfileFields($user, $validated + $payload);
            $persistedPayload = $this->affiliateProfileService->filterAffiliateProfilePayload($payload);

            $profile = AffiliateProfile::updateOrCreate(
                ['user_id' => $user->id],
                $persistedPayload
            );

            $bankAccount = null;
            if (!empty($validated['bank_id']) || !empty($validated['account_number']) || !empty($validated['account_name'])) {
                $bankAccount = BankAccount::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'account_name' => trim((string) ($validated['account_name'] ?? '')),
                        'account_number' => trim((string) ($validated['account_number'] ?? '')),
                        'bank_id' => ! empty($validated['bank_id']) ? (int) $validated['bank_id'] : null,
                        'is_default' => true,
                    ]
                );
                $bankAccount->load('bank');
            }

            return [$profile, $bankAccount];
        });

        $this->affiliateProfileService->logAction(
            $user,
            $submissionAction === 'draft' ? 'affiliate_profile.save_draft' : 'affiliate_profile.submit',
            'AffiliateProfile',
            (string) $profile->id,
            null,
            $this->affiliateProfileService->formatProfile($profile),
            null,
            $request,
        );

        $user->refresh();
        $responseProfile = $this->buildRegistrationResponseProfile($profile, $user, $validated, $submissionAction);

        $statusCode = $profile->wasRecentlyCreated ? 201 : 200;

        return response()->json([
            'success' => true,
            'message' => $submissionAction === 'draft'
                ? 'Đã lưu nháp hồ sơ Affiliate.'
                : 'Hồ sơ Affiliate đã được gửi để xét duyệt.',
            'data' => [
                'status' => $profile->status,
                'profile' => $responseProfile,
                'bank_account' => $this->affiliateProfileService->formatBankAccount($bankAccount),
            ],
        ], $statusCode);
    }

    public function myStatus(Request $request)
    {
        $user = $request->user();

        $profile = AffiliateProfile::query()->where('user_id', $user->id)->first();
        $bankAccount = BankAccount::query()->with('bank')->where('user_id', $user->id)->first();

        if (!$profile) {
            return response()->json([
                'success' => true,
                'data' => [
                    'status' => 'not_registered',
                    'profile' => null,
                    'bank_account' => $bankAccount ? $this->affiliateProfileService->formatBankAccount($bankAccount) : null,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $profile->status,
                'profile' => $this->affiliateProfileService->formatProfile($profile),
                'bank_account' => $bankAccount ? $this->affiliateProfileService->formatBankAccount($bankAccount) : null,
            ],
        ]);
    }

    public function links(Request $request)
    {
        $user = $request->user();
        $affiliateProfile = AffiliateProfile::query()
            ->where('user_id', $user->id)
            ->where('status', AffiliateProfile::STATUS_APPROVED)
            ->first();

        $perPageProducts = max(1, min(50, (int) $request->query('per_page', 20)));
        $perPageStores = max(1, min(20, (int) $request->query('stores_per_page', 10)));

        $formattedAffiliateProfile = $affiliateProfile
            ? $this->affiliateProfileService->formatProfile($affiliateProfile)
            : null;
        $refCode = $formattedAffiliateProfile['affiliate_code'] ?? ('GT' . (string) $user->id);

        // NOTE: "link" ở đây là endpoint API để miniapp có thể gọi trực tiếp.
        $productLinks = Product::query()
            ->leftJoin('stores', 'stores.id', '=', 'products.store_id')
            ->select('products.*')
            ->selectRaw('stores.name as store_name')
            ->whereIn(DB::raw("LOWER(COALESCE(products.status, ''))"), ['published', 'approved', 'active'])
            ->orderByDesc('products.created_at')
            ->limit($perPageProducts)
            ->get()
            ->map(function (Product $product) use ($refCode) {
                return [
                    'id' => (int) $product->id,
                    'slug' => (string) $product->slug,
                    'name' => (string) ($product->name ?? ''),
                    'thumbnail' => $product->thumbnail ?? null,
                    'store_name' => $product->store_name ?? null,
                    'url' => '/api/product/' . $product->slug . '?' . http_build_query([
                        'ref' => $refCode,
                        'source' => 'affiliate_link',
                    ]),
                ];
            })
            ->values();
 
        $storeLinks = Store::query()
            ->select(['id', 'name', 'logo_url', 'status', 'is_open', 'address', 'public_url'])
            ->whereIn(DB::raw('LOWER(status)'), ['approved', 'active'])
            ->orderByDesc('is_open')
            ->orderByDesc('updated_at')
            ->limit($perPageStores)
            ->get()
            ->map(function (Store $store) use ($refCode) {
                return [
                    'id' => (string) $store->id,
                    'name' => (string) ($store->name ?? ''),
                    'logo_url' => $store->logo_url ?? null,
                    'url' => '/api/stores/' . (string) $store->id . '?' . http_build_query([
                        'ref' => $refCode,
                        'source' => 'affiliate_link',
                    ]),
                ];
            })
            ->values();
 
        $avatar = $user->avatar ?? ($user->avatar_url ?? null);
 
        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => (int) $user->id,
                'ref_code' => $refCode,
                'register_link' => $this->withAffiliateLinkSource(
                    ($formattedAffiliateProfile['affiliate_link'] ?? null) ?: (config('app.url') . '/register?ref=' . $refCode),
                ),
                'name_card' => [
                    'user_id' => (int) $user->id,
                    'display_name' => (string) ($user->full_name ?? $user->name ?? ''),
                    'referrer_id' => $user->referrer_id ?? null,
                    'phone' => $user->phone ?? null,
                    'avatar' => $avatar,
                ],
                'product_links' => $productLinks,
                'store_links' => $storeLinks,
            ],
        ]);
    }

    public function downline(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'downlines' => $this->affiliateCustomerService->directDownlineForAffiliate((int) $user->id),
                'depth' => 1,
            ],
        ]);
    }

    private function withAffiliateLinkSource(string $url): string
    {
        if (preg_match('/(?:^|[?&])source=/', $url)) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . 'source=affiliate_link';
    }

    public function deleteDownlineMember(Request $request, $userId)
    {
        $manager = $request->user();
        $targetId = is_numeric($userId) ? (int) $userId : null;
        if (!$targetId) {
            return response()->json([
                'message' => 'userId không hợp lệ.',
            ], 422);
        }

        $payload = DB::transaction(function () use ($manager, $targetId, $request) {
            $lockedManager = User::query()->lockForUpdate()->find($manager->id);
            $lockedTarget = User::query()->lockForUpdate()->find($targetId);

            if (!$lockedTarget) {
                return ['status' => 404];
            }

            if ((int) ($lockedTarget->referrer_id ?? 0) !== (int) $lockedManager->id) {
                return ['status' => 403];
            }

            $this->affiliateCustomerService->removeCustomer(
                (int) $lockedManager->id,
                (int) $lockedTarget->id,
                'Affiliate tự loại khách hàng khỏi danh sách quản lý.',
                $lockedManager,
                $request ?? null,
            );

            return [
                'status' => 200,
                'detached_user_id' => (int) $lockedTarget->id,
            ];
        });

        if (($payload['status'] ?? null) === 404) {
            return response()->json(['message' => 'Không tìm thấy người dùng.'], 404);
        }

        if (($payload['status'] ?? null) === 403) {
            return response()->json(['message' => 'Không có quyền xóa tuyến dưới này.'], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đã xóa tuyến dưới (detach) thành công.',
            'data' => [
                'detached_user_id' => $payload['detached_user_id'] ?? null,
            ],
        ]);
    }

    public function reportUpline(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'upline_user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'max:3000'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $uplineId = (int) $validated['upline_user_id'];

        // F2 report về F1 (upline trực tiếp của user hiện tại).
        $currentUplineId = (int) ($user->referrer_id ?? 0);
        if ($currentUplineId <= 0 || $currentUplineId !== $uplineId) {
            return response()->json([
                'message' => 'Upline không phải người giới thiệu trực tiếp của bạn.',
            ], 403);
        }

        // Chống spam report (giới hạn theo user trong 1h).
        $windowSeconds = 3600;
        $maxReports = 3;
        $cacheKey = 'affiliate_report_upline:' . (string) $user->id;

        $timestamps = Cache::get($cacheKey, []);
        if (!is_array($timestamps)) {
            $timestamps = [];
        }

        $cutoff = time() - $windowSeconds;
        $timestamps = array_values(array_filter($timestamps, fn($ts) => is_numeric($ts) && (int) $ts > $cutoff));

        if (count($timestamps) >= $maxReports) {
            return response()->json([
                'message' => 'Bạn đã gửi báo cáo quá nhiều lần. Vui lòng thử lại sau.',
            ], 429);
        }

        $timestamps[] = time();
        Cache::put($cacheKey, $timestamps, $windowSeconds);

        $complaint = new Complaint();
        $complaint->complaint_code = $this->generateComplaintCode();
        $complaint->user_id = $user->id; // F2
        $complaint->store_id = null;
        $complaint->order_id = null;
        $complaint->complaint_type = 'affiliate_dispute';
        $complaint->severity = 'normal';
        $complaint->title = trim((string) ($validated['title'] ?? 'Báo cáo người giới thiệu')) ?: 'Báo cáo người giới thiệu';
        $complaint->description = trim((string) $validated['reason']) !== ''
            ? ('F2 user_id: ' . $user->id . "\nUpline user_id: " . $uplineId . "\n\nReason:\n" . trim((string) $validated['reason']))
            : ('F2 user_id: ' . $user->id . "\nUpline user_id: " . $uplineId);
        $complaint->status = 'pending';
        $complaint->save();

        if (Schema::hasTable('complaint_messages')) {
            $message = new ComplaintMessage();
            $message->complaint_id = $complaint->id;
            $message->sender_id = $user->id;
            $message->sender_type = 'customer';
            $message->message = trim((string) $validated['reason']);
            $message->attachments = null;
            $message->is_from_admin = false;
            $message->is_read = false;
            $message->created_at = now();
            $message->save();

            // Some projects listen to complaint message broadcast events.
            // We keep it minimal here; admin pages will read complaint_messages directly.
        }

        return response()->json([
            'success' => true,
            'message' => 'Đã gửi báo cáo tuyến (affiliate dispute) thành công.',
            'data' => [
                'complaint_id' => $complaint->id,
                'complaint_code' => $complaint->complaint_code,
            ],
        ], 201);
    }

    public function withdrawalStatus(Request $request)
    {
        $user = $request->user();

        $requestModel = AffiliateWithdrawalRequest::where('user_id', $user->id)->orderByDesc('created_at')->first();

        $referralsCount = User::where('referrer_id', $user->id)->count();

        return response()->json([
            'success' => true,
            'data' => [
                'request' => $requestModel,
                'referrals_count' => $referralsCount,
            ],
        ]);
    }

    public function submitWithdrawalRequest(Request $request)
    {
        $user = $request->user();
        $referralsCount = User::where('referrer_id', $user->id)->count();

        if ($referralsCount < 100) {
            return response()->json([
                'message' => 'Bạn cần đạt đủ 100 thành viên kích hoạt trước khi gửi hồ sơ.',
            ], 422);
        }

        $latestRequest = AffiliateWithdrawalRequest::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->first();

        $hasSubmittedProfile = $latestRequest
            && !empty($latestRequest->cccd_document)
            && !empty($latestRequest->tax_code)
            && !empty($latestRequest->social_channel)
            && !empty($latestRequest->identity_phone)
            && !empty($latestRequest->bank_account_name)
            && !empty($latestRequest->bank_account_number);

        if ($latestRequest && $hasSubmittedProfile && $latestRequest->status === 'pending' && empty($latestRequest->approved_at)) {
            return response()->json([
                'message' => 'Hồ sơ của bạn đang chờ phê duyệt.',
                'data' => [
                    'request' => $latestRequest,
                    'referrals_count' => $referralsCount,
                ],
            ], 409);
        }

        if ($latestRequest && $hasSubmittedProfile && $latestRequest->status === 'approved' && !empty($latestRequest->approved_at)) {
            return response()->json([
                'message' => 'Hồ sơ của bạn đã được duyệt.',
                'data' => [
                    'request' => $latestRequest,
                    'referrals_count' => $referralsCount,
                ],
            ], 409);
        }

        $validated = $request->validate([
            'cccd_document' => ['required', 'string', 'max:255'],
            'tax_code' => ['required', 'string', 'max:50'],
            'social_channel' => ['required', 'string', 'max:255'],
            'identity_phone' => ['required', 'string', 'max:30'],
            'bank_account_name' => ['required', 'string', 'max:255'],
            'bank_account_number' => ['required', 'string', 'max:50'],
        ]);

        $requestAttributes = [
            'referrals_count' => $referralsCount,
            'status' => 'pending',
            'approved_by' => null,
            'approved_at' => null,
            'cccd_document' => trim((string) $validated['cccd_document']),
            'tax_code' => trim((string) $validated['tax_code']),
            'social_channel' => trim((string) $validated['social_channel']),
            'identity_phone' => trim((string) $validated['identity_phone']),
            'bank_account_name' => trim((string) $validated['bank_account_name']),
            'bank_account_number' => trim((string) $validated['bank_account_number']),
        ];

        $requestModel = AffiliateWithdrawalRequest::updateOrCreate(
            [
                'user_id' => $user->id,
                'status' => 'pending',
                'approved_at' => null,
            ],
            $requestAttributes
        );

        return response()->json([
            'success' => true,
            'message' => 'Hồ sơ rút tiền đã được gửi để admin duyệt.',
            'data' => [
                'request' => $requestModel,
                'referrals_count' => $referralsCount,
            ],
        ], 201);
    }

    private function hasAffiliateManagementRole(User $user): bool
    {
        $role = strtolower((string) ($user->role ?? ''));

        // Các role backend hiện có chủ yếu dùng cho StoreAdmin; affiliate quản lý tuyến dưới yêu cầu tối thiểu role "sale"/"admin".
        return in_array($role, ['sale', 'admin', 'super_admin', 'store_admin', 'shop_admin', 'store_owner'], true);
    }

    private function generateComplaintCode(): string
    {
        $datePart = now()->format('ymd');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = 'CMP-' . $datePart . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

            if (!Complaint::query()->where('complaint_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'CMP-' . now()->format('ymdHis') . '-' . Str::upper(Str::random(4));
    }

    private function buildRegistrationResponseProfile(
        AffiliateProfile $profile,
        User $user,
        array $validated,
        string $submissionAction,
    ): array {
        $formatted = $this->affiliateProfileService->formatProfile($profile);
        $nowIso = now()->toIso8601String();

        return array_merge($formatted, [
            'full_name' => trim((string) ($validated['full_name'] ?? '')) !== ''
                ? trim((string) $validated['full_name'])
                : ($user->personal_full_name ?? $user->full_name ?? $user->name),
            'birthday' => trim((string) ($validated['birthday'] ?? '')) !== ''
                ? (string) $validated['birthday']
                : ($formatted['birthday'] ?? null),
            'contact_address' => trim((string) ($validated['contact_address'] ?? '')) !== ''
                ? trim((string) $validated['contact_address'])
                : ($formatted['contact_address'] ?? null),
            'permanent_address' => trim((string) ($validated['permanent_address'] ?? '')) !== ''
                ? trim((string) $validated['permanent_address'])
                : ($formatted['permanent_address'] ?? null),
            'accepted_policy' => (bool) ($validated['accepted_policy'] ?? $formatted['accepted_policy'] ?? false),
            'accepted_no_fake_order_commitment' => (bool) ($validated['accepted_no_fake_order_commitment'] ?? $formatted['accepted_no_fake_order_commitment'] ?? false),
            'accepted_tax_bank_accuracy_commitment' => (bool) ($validated['accepted_tax_bank_accuracy_commitment'] ?? $formatted['accepted_tax_bank_accuracy_commitment'] ?? false),
            'accepted_communication_commitment' => (bool) ($validated['accepted_communication_commitment'] ?? $formatted['accepted_communication_commitment'] ?? false),
            'policy_version' => trim((string) ($validated['policy_version'] ?? '')) !== ''
                ? trim((string) $validated['policy_version'])
                : ($formatted['policy_version'] ?? null),
            'accepted_at' => $submissionAction === 'submit'
                ? ($formatted['accepted_at'] ?? $nowIso)
                : ($formatted['accepted_at'] ?? null),
            'submitted_at' => $submissionAction === 'submit'
                ? ($formatted['submitted_at'] ?? $nowIso)
                : ($formatted['submitted_at'] ?? null),
        ]);
    }
}
