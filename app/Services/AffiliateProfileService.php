<?php

namespace App\Services;

use App\Models\AffiliateAuditLog;
use App\Models\AffiliateProfile;
use App\Models\BankAccount;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AffiliateProfileService
{
    private const FALLBACK_META_PREFIX = '__affiliate_meta__:';

    private ?array $affiliateProfileColumns = null;

    public function formatProfile(AffiliateProfile $profile): array
    {
        $profile->loadMissing(['user.bankAccount.bank']);
        $user = $profile->user;
        $fallbackMeta = $this->extractFallbackProfileMeta($profile->social_media_links);
        $socialLinks = $this->extractVisibleSocialLinks($profile->social_media_links);

        $submittedAt = $this->hasProfileColumn('submitted_at')
            ? $this->toIsoDateTime($profile->submitted_at ?? $profile->created_at)
            : ($fallbackMeta['submitted_at'] ?? $this->toIsoDateTime($profile->created_at));
        $affiliateCode = $this->hasProfileColumn('affiliate_code')
            ? $profile->affiliate_code
            : ($fallbackMeta['affiliate_code'] ?? null);
        $affiliateLink = $this->hasProfileColumn('affiliate_link')
            ? $profile->affiliate_link
            : ($fallbackMeta['affiliate_link'] ?? null);
        $acceptedPolicy = $this->hasProfileColumn('accepted_policy')
            ? (bool) $profile->accepted_policy
            : (bool) ($fallbackMeta['accepted_policy'] ?? false);
        $acceptedNoFakeOrderCommitment = $this->hasProfileColumn('accepted_no_fake_order_commitment')
            ? (bool) $profile->accepted_no_fake_order_commitment
            : (bool) ($fallbackMeta['accepted_no_fake_order_commitment'] ?? false);
        $acceptedTaxBankAccuracyCommitment = $this->hasProfileColumn('accepted_tax_bank_accuracy_commitment')
            ? (bool) $profile->accepted_tax_bank_accuracy_commitment
            : (bool) ($fallbackMeta['accepted_tax_bank_accuracy_commitment'] ?? false);
        $acceptedCommunicationCommitment = $this->hasProfileColumn('accepted_communication_commitment')
            ? (bool) $profile->accepted_communication_commitment
            : (bool) ($fallbackMeta['accepted_communication_commitment'] ?? false);
        $acceptedAt = $this->hasProfileColumn('accepted_at')
            ? $this->toIsoDateTime($profile->accepted_at)
            : ($fallbackMeta['accepted_at'] ?? null);
        $policyVersion = $this->hasProfileColumn('policy_version')
            ? $profile->policy_version
            : ($fallbackMeta['policy_version'] ?? null);
        $needMoreInfoReason = $this->hasProfileColumn('need_more_info_reason')
            ? $profile->need_more_info_reason
            : ($fallbackMeta['need_more_info_reason'] ?? null);
        $suspendedReason = $this->hasProfileColumn('suspended_reason')
            ? $profile->suspended_reason
            : ($fallbackMeta['suspended_reason'] ?? null);
        $terminatedReason = $this->hasProfileColumn('terminated_reason')
            ? $profile->terminated_reason
            : ($fallbackMeta['terminated_reason'] ?? null);
        $reviewStartedAt = $this->hasProfileColumn('review_started_at')
            ? $this->toIsoDateTime($profile->review_started_at)
            : ($fallbackMeta['review_started_at'] ?? null);
        $approvedAt = $this->hasProfileColumn('approved_at')
            ? $this->toIsoDateTime($profile->approved_at)
            : ($fallbackMeta['approved_at'] ?? null);
        $rejectedAt = $this->hasProfileColumn('rejected_at')
            ? $this->toIsoDateTime($profile->rejected_at)
            : ($fallbackMeta['rejected_at'] ?? null);

        return [
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'full_name' => $user?->personal_full_name ?? $user?->full_name ?? $user?->name,
            'birthday' => $this->toDateString($user?->birthday),
            'contact_address' => $user?->address,
            'permanent_address' => $user?->personal_address,
            'id_number' => $profile->id_number,
            'id_front_image' => $profile->id_front_image,
            'id_back_image' => $profile->id_back_image,
            'id_selfie_image' => $profile->id_selfie_image,
            'personal_tax_code' => $profile->personal_tax_code,
            'social_media_links' => $socialLinks,
            'status' => $profile->status,
            'rejection_reason' => $profile->rejection_reason,
            'need_more_info_reason' => $needMoreInfoReason,
            'suspended_reason' => $suspendedReason,
            'terminated_reason' => $terminatedReason,
            'affiliate_code' => $affiliateCode,
            'affiliate_link' => $affiliateLink,
            'accepted_policy' => $acceptedPolicy,
            'accepted_no_fake_order_commitment' => $acceptedNoFakeOrderCommitment,
            'accepted_tax_bank_accuracy_commitment' => $acceptedTaxBankAccuracyCommitment,
            'accepted_communication_commitment' => $acceptedCommunicationCommitment,
            'accepted_at' => $acceptedAt,
            'policy_version' => $policyVersion,
            'submitted_at' => $submittedAt,
            'review_started_at' => $reviewStartedAt,
            'approved_at' => $approvedAt,
            'rejected_at' => $rejectedAt,
            'created_at' => $this->toIsoDateTime($profile->created_at),
            'updated_at' => $this->toIsoDateTime($profile->updated_at),
        ];
    }

    public function formatBankAccount(?BankAccount $account): ?array
    {
        if (!$account) {
            return null;
        }

        $account->loadMissing('bank');

        return [
            'id' => $account->id,
            'user_id' => $account->user_id,
            'account_name' => $account->account_name,
            'account_number' => $account->account_number,
            'bank_id' => $account->bank_id,
            'bank' => $account->bank ? [
                'id' => $account->bank->id,
                'code' => $account->bank->code,
                'name' => $account->bank->name,
                'image' => $account->bank->image,
            ] : null,
        ];
    }

    public function syncUserProfileFields(User $user, array $payload): void
    {
        $fullName = trim((string) ($payload['full_name'] ?? ''));
        $birthday = trim((string) ($payload['birthday'] ?? ''));
        $contactAddress = trim((string) ($payload['contact_address'] ?? ''));
        $permanentAddress = trim((string) ($payload['permanent_address'] ?? ''));
        $idNumber = trim((string) ($payload['id_number'] ?? ''));

        if ($fullName !== '') {
            $user->full_name = $fullName;
            $user->personal_full_name = $fullName;
        }

        if ($birthday !== '') {
            $user->birthday = $birthday;
        }

        if ($contactAddress !== '') {
            $user->address = $contactAddress;
        }

        if ($permanentAddress !== '') {
            $user->personal_address = $permanentAddress;
        }

        if ($idNumber !== '') {
            $user->personal_identity_number = $idNumber;
        }

        $user->save();
    }

    public function validateSubmission(User $user, array $payload, ?AffiliateProfile $currentProfile = null): array
    {
        $reasons = [];

        if (Schema::hasColumn('users', 'is_phone_verified') && !$user->is_phone_verified) {
            $reasons[] = 'Bạn cần xác thực số điện thoại trước khi đăng ký Affiliate.';
        }

        if (empty($user->phone)) {
            $reasons[] = 'Bạn cần cập nhật số điện thoại trước khi đăng ký Affiliate.';
        }

        $identityName = $this->resolveIdentityFullName($user, $payload);
        if ($identityName === '') {
            $reasons[] = 'Bạn cần cập nhật họ tên đầy đủ trước khi gửi hồ sơ.';
        }

        $birthday = trim((string) ($payload['birthday'] ?? $this->toDateString($user->birthday) ?? ''));
        if ($birthday === '') {
            $reasons[] = 'Bạn cần cập nhật ngày sinh trước khi gửi hồ sơ.';
        } else {
            try {
                $birthDateObj = \Carbon\Carbon::parse($birthday);
                if ($birthDateObj->greaterThan(now()->subYears(16))) {
                    $reasons[] = 'Bạn phải từ 16 tuổi trở lên mới được đăng ký Affiliate.';
                }
            } catch (\Exception $e) {
                $reasons[] = 'Ngày sinh không đúng định dạng.';
            }
        }

        if (trim((string) ($payload['contact_address'] ?? $user->address ?? '')) === '') {
            $reasons[] = 'Bạn cần cập nhật địa chỉ liên hệ trước khi gửi hồ sơ.';
        }

        if (trim((string) ($payload['permanent_address'] ?? $user->personal_address ?? '')) === '') {
            $reasons[] = 'Bạn cần cập nhật địa chỉ thường trú trước khi gửi hồ sơ.';
        }

        foreach ([
            'id_number' => 'Vui lòng nhập số CCCD hợp lệ.',
            'id_front_image' => 'Vui lòng tải ảnh CCCD mặt trước.',
            'id_back_image' => 'Vui lòng tải ảnh CCCD mặt sau.',
            'id_selfie_image' => 'Vui lòng tải ảnh chân dung/selfie.',
            'personal_tax_code' => 'Vui lòng nhập mã số thuế cá nhân.',
            'bank_id' => 'Vui lòng chọn ngân hàng.',
            'account_number' => 'Vui lòng nhập số tài khoản ngân hàng.',
            'account_name' => 'Vui lòng nhập tên chủ tài khoản.',
            'policy_version' => 'Vui lòng xác nhận phiên bản điều khoản Affiliate.',
        ] as $field => $message) {
            if (trim((string) ($payload[$field] ?? '')) === '') {
                $reasons[] = $message;
            }
        }

        foreach ([
            'accepted_policy' => 'Bạn cần đồng ý điều khoản Affiliate.',
            'accepted_no_fake_order_commitment' => 'Bạn cần cam kết không tạo đơn ảo.',
            'accepted_tax_bank_accuracy_commitment' => 'Bạn cần cam kết thông tin thuế và ngân hàng là chính xác.',
            'accepted_communication_commitment' => 'Bạn cần cam kết truyền thông đúng quy định.',
        ] as $field => $message) {
            if (!filter_var($payload[$field] ?? false, FILTER_VALIDATE_BOOL)) {
                $reasons[] = $message;
            }
        }

        $duplicateChecks = $this->findDuplicateSignals($user, $payload, $currentProfile);
        foreach ($duplicateChecks as $duplicateReason) {
            $reasons[] = $duplicateReason;
        }

        return array_values(array_unique($reasons));
    }

    public function buildApprovalChecklist(AffiliateProfile $profile): array
    {
        $profile->loadMissing(['user.bankAccount.bank']);
        $user = $profile->user;
        $bank = $user?->bankAccount;
        $formattedProfile = $this->formatProfile($profile);
        $identityName = $this->resolveIdentityFullName($user, [
            'full_name' => $user?->full_name,
        ]);

        $checks = [
            'has_full_name' => $identityName !== '',
            'has_verified_phone' => !Schema::hasColumn('users', 'is_phone_verified') || (bool) ($user?->is_phone_verified ?? false),
            'has_phone' => !empty($user?->phone),
            'has_birthday' => !empty($user?->birthday),
            'has_contact_address' => trim((string) ($user?->address ?? '')) !== '',
            'has_permanent_address' => trim((string) ($user?->personal_address ?? '')) !== '',
            'has_id_documents' => trim((string) $profile->id_front_image) !== ''
                && trim((string) $profile->id_back_image) !== ''
                && trim((string) $profile->id_selfie_image) !== '',
            'has_tax_code' => trim((string) $profile->personal_tax_code) !== '',
            'has_bank_account' => $bank instanceof BankAccount
                && trim((string) ($bank->account_number ?? '')) !== ''
                && !empty($bank->bank_id),
            'has_consents' => (bool) ($formattedProfile['accepted_policy'] ?? false)
                && (bool) ($formattedProfile['accepted_no_fake_order_commitment'] ?? false)
                && (bool) ($formattedProfile['accepted_tax_bank_accuracy_commitment'] ?? false)
                && (bool) ($formattedProfile['accepted_communication_commitment'] ?? false),
            'has_no_duplicate_signals' => count($this->findDuplicateSignals($user, [
                'id_number' => $profile->id_number,
                'personal_tax_code' => $profile->personal_tax_code,
                'account_number' => $bank?->account_number,
            ], $profile)) === 0,
        ];

        $reasons = [];
        foreach ($checks as $check => $passed) {
            if ($passed) {
                continue;
            }

            $reasons[] = match ($check) {
                'has_full_name' => 'Thiếu họ tên đầy đủ.',
                'has_verified_phone' => 'Số điện thoại chưa xác thực OTP.',
                'has_phone' => 'Thiếu số điện thoại.',
                'has_birthday' => 'Thiếu ngày sinh.',
                'has_contact_address' => 'Thiếu địa chỉ liên hệ.',
                'has_permanent_address' => 'Thiếu địa chỉ thường trú.',
                'has_id_documents' => 'Thiếu bộ ảnh CCCD hoặc selfie.',
                'has_tax_code' => 'Thiếu mã số thuế cá nhân.',
                'has_bank_account' => 'Thiếu thông tin tài khoản ngân hàng.',
                'has_consents' => 'Thiếu xác nhận điều khoản hoặc cam kết bắt buộc.',
                'has_no_duplicate_signals' => 'Phát hiện trùng CCCD/MST/tài khoản ngân hàng với hồ sơ đã duyệt hoặc bị khóa.',
                default => 'Hồ sơ chưa đủ điều kiện duyệt.',
            };
        }

        return [
            'checks' => $checks,
            'can_approve' => count($reasons) === 0,
            'reasons' => $reasons,
        ];
    }

    public function transition(AffiliateProfile $profile, string $action, array $payload, ?User $actor, ?Request $request = null): AffiliateProfile
    {
        $profile->loadMissing(['user.bankAccount.bank']);
        $before = $this->formatProfile($profile);
        $now = now();
        $changes = [];
        $usesLegacySchema = $this->usesLegacyAffiliateProfileSchema();

        switch ($action) {
            case 'start_review':
                if (
                    !in_array($profile->status, [
                        AffiliateProfile::STATUS_PENDING,
                        AffiliateProfile::STATUS_SUBMITTED,
                        AffiliateProfile::STATUS_NEED_MORE_INFO,
                    ], true)
                ) {
                    abort(422, 'Chỉ có thể nhận xử lý hồ sơ đã gửi hoặc cần bổ sung.');
                }
                $changes['status'] = $usesLegacySchema
                    ? AffiliateProfile::STATUS_PENDING
                    : AffiliateProfile::STATUS_UNDER_REVIEW;
                $changes['review_started_at'] = $now;
                break;

            case 'request_more_info':
                if ($usesLegacySchema) {
                    abort(422, 'Tính năng yêu cầu bổ sung cần migrate bảng affiliate_profiles trước.');
                }
                if (!in_array($profile->status, AffiliateProfile::REVIEWABLE_STATUSES, true)) {
                    abort(422, 'Chỉ có thể yêu cầu bổ sung với hồ sơ đang chờ xử lý.');
                }
                $needMoreInfoReason = trim((string) ($payload['need_more_info_reason'] ?? ''));
                if ($needMoreInfoReason === '') {
                    abort(422, 'Vui lòng nhập lý do cần bổ sung.');
                }
                $changes['status'] = AffiliateProfile::STATUS_NEED_MORE_INFO;
                $changes['need_more_info_reason'] = $needMoreInfoReason;
                break;

            case 'approve':
                if (
                    (!$usesLegacySchema && $profile->status !== AffiliateProfile::STATUS_UNDER_REVIEW)
                    || ($usesLegacySchema && !in_array($profile->status, AffiliateProfile::REVIEWABLE_STATUSES, true))
                ) {
                    abort(422, 'Chỉ có thể duyệt hồ sơ đang được kiểm tra.');
                }
                $checklist = $this->buildApprovalChecklist($profile);
                if (!$checklist['can_approve']) {
                    abort(422, implode(' ', $checklist['reasons']));
                }
                $changes['status'] = AffiliateProfile::STATUS_APPROVED;
                $changes['approved_at'] = $now;
                $changes['approved_by'] = $actor?->id;
                $changes['rejection_reason'] = null;
                $changes['need_more_info_reason'] = null;
                $changes['affiliate_code'] = $before['affiliate_code'] ?: $this->generateAffiliateCode();
                $changes['affiliate_link'] = $before['affiliate_link'] ?: $this->generateAffiliateLink($profile);
                break;

            case 'reject':
                if (!in_array($profile->status, AffiliateProfile::REVIEWABLE_STATUSES, true)) {
                    abort(422, 'Chỉ có thể từ chối hồ sơ đang chờ xử lý.');
                }
                $rejectionReason = trim((string) ($payload['rejection_reason'] ?? ''));
                if ($rejectionReason === '') {
                    abort(422, 'Vui lòng nhập lý do từ chối.');
                }
                $changes['status'] = AffiliateProfile::STATUS_REJECTED;
                $changes['rejection_reason'] = $rejectionReason;
                $changes['rejected_at'] = $now;
                $changes['rejected_by'] = $actor?->id;
                break;

            case 'suspend':
                if ($usesLegacySchema) {
                    abort(422, 'Tính năng tạm khóa cần migrate bảng affiliate_profiles trước.');
                }
                if ($profile->status !== AffiliateProfile::STATUS_APPROVED) {
                    abort(422, 'Chỉ có thể tạm khóa hồ sơ đã duyệt.');
                }
                $suspendedReason = trim((string) ($payload['suspended_reason'] ?? ''));
                if ($suspendedReason === '') {
                    abort(422, 'Vui lòng nhập lý do tạm khóa.');
                }
                $changes['status'] = AffiliateProfile::STATUS_SUSPENDED;
                $changes['suspended_reason'] = $suspendedReason;
                break;

            case 'lock':
                if ($usesLegacySchema) {
                    abort(422, 'Tính năng khóa hồ sơ cần migrate bảng affiliate_profiles trước.');
                }
                if (
                    !in_array($profile->status, [
                        AffiliateProfile::STATUS_APPROVED,
                        AffiliateProfile::STATUS_SUSPENDED,
                        AffiliateProfile::STATUS_REJECTED,
                    ], true)
                ) {
                    abort(422, 'Chỉ có thể khóa hồ sơ đã duyệt, tạm khóa hoặc bị từ chối.');
                }
                $changes['status'] = AffiliateProfile::STATUS_LOCKED;
                $changes['suspended_reason'] = trim((string) ($payload['suspended_reason'] ?? $payload['lock_reason'] ?? ''));
                break;

            case 'unlock':
                if ($usesLegacySchema) {
                    abort(422, 'Tính năng mở khóa cần migrate bảng affiliate_profiles trước.');
                }
                if ($profile->status !== AffiliateProfile::STATUS_LOCKED) {
                    abort(422, 'Chỉ có thể mở khóa hồ sơ đang bị khóa.');
                }
                $changes['status'] = AffiliateProfile::STATUS_APPROVED;
                $changes['suspended_reason'] = null;
                break;

            case 'terminate':
                if ($usesLegacySchema) {
                    abort(422, 'Tính năng chấm dứt Affiliate cần migrate bảng affiliate_profiles trước.');
                }
                if (
                    !in_array($profile->status, [
                        AffiliateProfile::STATUS_APPROVED,
                        AffiliateProfile::STATUS_SUSPENDED,
                        AffiliateProfile::STATUS_LOCKED,
                    ], true)
                ) {
                    abort(422, 'Chỉ có thể chấm dứt Affiliate đã từng hoạt động.');
                }
                $terminatedReason = trim((string) ($payload['terminated_reason'] ?? ''));
                if ($terminatedReason === '') {
                    abort(422, 'Vui lòng nhập lý do chấm dứt.');
                }
                $changes['status'] = AffiliateProfile::STATUS_TERMINATED;
                $changes['terminated_reason'] = $terminatedReason;
                break;

            default:
                abort(422, 'Hành động chuyển trạng thái không hợp lệ.');
        }

        $this->fillAffiliateProfile($profile, $changes);
        $profile->save();
        $profile->refresh();

        if ($action === 'approve') {
            User::query()->whereKey($profile->user_id)->update(['role' => 'affiliate']);
        }

        $after = $this->formatProfile($profile);
        $this->logAction(
            $actor,
            'affiliate_profile.' . $action,
            'AffiliateProfile',
            (string) $profile->id,
            $before,
            $after,
            $payload['reason']
            ?? $payload['rejection_reason']
            ?? $payload['need_more_info_reason']
            ?? $payload['suspended_reason']
            ?? $payload['terminated_reason']
            ?? null,
            $request,
        );

        return $profile;
    }

    public function logAction(
        ?User $actor,
        string $action,
        string $objectType,
        string $objectId,
        ?array $oldValue,
        ?array $newValue,
        ?string $reason,
        ?Request $request = null,
    ): void {
        if (!Schema::hasTable('affiliate_audit_logs')) {
            return;
        }

        try {
            AffiliateAuditLog::query()->create([
                'actor_id' => $actor?->id,
                'actor_role' => $actor?->role ?? 'system',
                'action' => $action,
                'object_type' => $objectType,
                'object_id' => $objectId,
                'old_value_json' => $oldValue,
                'new_value_json' => $newValue,
                'reason' => $reason,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
            ]);
        } catch (\Throwable $exception) {
            // ignore audit logging failures on environments that have not been migrated yet
        }
    }

    public function generateAffiliateLink(AffiliateProfile $profile, ?string $code = null): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');
        $refCode = $code ?? $profile->affiliate_code;
        if (empty($refCode)) {
            $fallbackMeta = $this->extractFallbackProfileMeta($profile->social_media_links);
            $refCode = $fallbackMeta['affiliate_code'] ?? null;
        }
        if (empty($refCode)) {
            $refCode = $profile->user_id;
        }

        return $baseUrl . '/referrer?' . http_build_query([
            'ref' => (string) $refCode,
            'source' => 'affiliate_link',
        ]);
    }

    public function normalizeName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);

        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                $value = $converted;
            }
        }

        $value = preg_replace('/[^a-z0-9\s]/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);

        return trim((string) $value);
    }

    private function resolveIdentityFullName(?User $user, array $payload): string
    {
        return trim((string) (
            $payload['full_name']
            ?? $user?->personal_full_name
            ?? $user?->full_name
            ?? $user?->name
            ?? ''
        ));
    }

    private function findDuplicateSignals(User $user, array $payload, ?AffiliateProfile $currentProfile = null): array
    {
        $statuses = [
            AffiliateProfile::STATUS_APPROVED,
            AffiliateProfile::STATUS_LOCKED,
        ];
        $excludeUserId = $user->id;
        $reasons = [];

        $idNumber = trim((string) ($payload['id_number'] ?? ''));
        if ($idNumber !== '') {
            $duplicateId = AffiliateProfile::query()
                ->whereIn('status', $statuses)
                ->where('id_number', $idNumber)
                ->where('user_id', '!=', $excludeUserId)
                ->exists();
            if ($duplicateId) {
                $reasons[] = 'Số CCCD đã tồn tại trên một Affiliate đã duyệt hoặc bị khóa.';
            }
        }

        $taxCode = trim((string) ($payload['personal_tax_code'] ?? ''));
        if ($taxCode !== '') {
            $duplicateTax = AffiliateProfile::query()
                ->whereIn('status', $statuses)
                ->where('personal_tax_code', $taxCode)
                ->where('user_id', '!=', $excludeUserId)
                ->exists();
            if ($duplicateTax) {
                $reasons[] = 'Mã số thuế đã tồn tại trên một Affiliate đã duyệt hoặc bị khóa.';
            }
        }

        $accountNumber = trim((string) ($payload['account_number'] ?? ''));
        if ($accountNumber !== '') {
            $duplicateBankUserIds = BankAccount::query()
                ->where('account_number', $accountNumber)
                ->where('user_id', '!=', $excludeUserId)
                ->pluck('user_id');
            if ($duplicateBankUserIds->isNotEmpty()) {
                $duplicateBank = AffiliateProfile::query()
                    ->whereIn('status', $statuses)
                    ->whereIn('user_id', $duplicateBankUserIds->all())
                    ->exists();
                if ($duplicateBank) {
                    $reasons[] = 'Số tài khoản ngân hàng đã tồn tại trên một Affiliate đã duyệt hoặc bị khóa.';
                }
            }
        }

        return array_values(array_unique($reasons));
    }

    public function generateAffiliateCode(): string
    {
        if (!$this->hasProfileColumn('affiliate_code')) {
            return Str::upper(Str::random(6));
        }

        do {
            $code = Str::upper(Str::random(6));
        } while (AffiliateProfile::query()->where('affiliate_code', $code)->exists());

        return $code;
    }

    private function toIsoDateTime($value): ?string
    {
        if (!$value) {
            return null;
        }

        return method_exists($value, 'toIso8601String')
            ? $value->toIso8601String()
            : (string) $value;
    }

    private function toDateString($value): ?string
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        $stringValue = trim((string) $value);

        return $stringValue !== '' ? $stringValue : null;
    }

    public function filterAffiliateProfilePayload(array $payload): array
    {
        if (array_key_exists('status', $payload)) {
            $payload['status'] = $this->normalizeAffiliateProfileStatusForPersistence((string) $payload['status']);
        }

        $payload = $this->mergeFallbackProfileMetaIntoPayload($payload);

        return array_filter(
            $payload,
            fn($value, $key) => $this->hasProfileColumn((string) $key),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    public function usesLegacyAffiliateProfileSchema(): bool
    {
        return !$this->hasProfileColumn('submitted_at');
    }

    private function fillAffiliateProfile(AffiliateProfile $profile, array $changes): void
    {
        foreach ($this->filterAffiliateProfilePayload($changes + ['social_media_links' => $profile->social_media_links]) as $key => $value) {
            $profile->{$key} = $value;
        }
    }

    private function normalizeAffiliateProfileStatusForPersistence(string $status): string
    {
        $status = trim(strtolower($status));

        if (!$this->usesLegacyAffiliateProfileSchema()) {
            return $status;
        }

        return match ($status) {
            AffiliateProfile::STATUS_DRAFT,
            AffiliateProfile::STATUS_SUBMITTED,
            AffiliateProfile::STATUS_UNDER_REVIEW,
            AffiliateProfile::STATUS_NEED_MORE_INFO => AffiliateProfile::STATUS_PENDING,
            AffiliateProfile::STATUS_APPROVED => AffiliateProfile::STATUS_APPROVED,
            AffiliateProfile::STATUS_REJECTED => AffiliateProfile::STATUS_REJECTED,
            default => AffiliateProfile::STATUS_PENDING,
        };
    }

    private function hasProfileColumn(string $column): bool
    {
        if ($this->affiliateProfileColumns === null) {
            $this->affiliateProfileColumns = Schema::hasTable('affiliate_profiles')
                ? Schema::getColumnListing('affiliate_profiles')
                : [];
        }

        return in_array($column, $this->affiliateProfileColumns, true);
    }

    private function mergeFallbackProfileMetaIntoPayload(array $payload): array
    {
        if (!$this->hasProfileColumn('social_media_links')) {
            return $payload;
        }

        $socialLinks = $this->extractVisibleSocialLinks($payload['social_media_links'] ?? null);
        $fallbackMeta = $this->extractFallbackProfileMeta($payload['social_media_links'] ?? null);
        $fallbackFields = [
            'affiliate_code',
            'affiliate_link',
            'accepted_policy',
            'accepted_no_fake_order_commitment',
            'accepted_tax_bank_accuracy_commitment',
            'accepted_communication_commitment',
            'accepted_at',
            'policy_version',
            'submitted_at',
            'review_started_at',
            'approved_at',
            'rejected_at',
            'need_more_info_reason',
            'suspended_reason',
            'terminated_reason',
        ];

        foreach ($fallbackFields as $field) {
            if ($this->hasProfileColumn($field) || !array_key_exists($field, $payload)) {
                continue;
            }

            $normalizedValue = $this->normalizeFallbackMetaValue($payload[$field]);

            if ($normalizedValue === null || $normalizedValue === '') {
                unset($fallbackMeta[$field]);
                continue;
            }

            $fallbackMeta[$field] = $normalizedValue;
        }

        $payload['social_media_links'] = $socialLinks;

        if (!empty($fallbackMeta)) {
            $payload['social_media_links'][] = self::FALLBACK_META_PREFIX . json_encode(
                $fallbackMeta,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        return $payload;
    }

    private function extractVisibleSocialLinks($socialMediaLinks): array
    {
        return array_values(array_filter(
            $this->normalizeSocialMediaLinks($socialMediaLinks),
            fn($value) => !str_starts_with((string) $value, self::FALLBACK_META_PREFIX),
        ));
    }

    private function extractFallbackProfileMeta($socialMediaLinks): array
    {
        $meta = [];

        foreach ($this->normalizeSocialMediaLinks($socialMediaLinks) as $entry) {
            if (!str_starts_with((string) $entry, self::FALLBACK_META_PREFIX)) {
                continue;
            }

            $decoded = json_decode(substr((string) $entry, strlen(self::FALLBACK_META_PREFIX)), true);
            if (is_array($decoded)) {
                $meta = array_merge($meta, $decoded);
            }
        }

        return $meta;
    }

    private function normalizeSocialMediaLinks($socialMediaLinks): array
    {
        if (is_array($socialMediaLinks)) {
            return array_values(array_filter($socialMediaLinks, fn($value) => is_scalar($value) && trim((string) $value) !== ''));
        }

        if (is_string($socialMediaLinks)) {
            $decoded = json_decode($socialMediaLinks, true);
            if (is_array($decoded)) {
                return array_values(array_filter($decoded, fn($value) => is_scalar($value) && trim((string) $value) !== ''));
            }

            return trim($socialMediaLinks) !== '' ? [trim($socialMediaLinks)] : [];
        }

        return [];
    }

    private function normalizeFallbackMetaValue($value)
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            $stringValue = trim((string) $value);

            return $stringValue === '' ? null : $stringValue;
        }

        return null;
    }
}
