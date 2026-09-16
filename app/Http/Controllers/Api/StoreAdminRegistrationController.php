<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\NotificationService;
use App\Services\StoreApprovalHistoryService;
use App\Services\StoreBusinessTypeService;
use App\Support\SafeEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class StoreAdminRegistrationController extends Controller
{
	private const ACTION_KEY_ADMIN_STORE_APPROVALS_PENDING = 'key:admin.store_approvals.pending';
	private const ACTION_KEY_CLIENT_STORE_PARTNER_REGISTRATION = 'key:client.profile.store_registration';

	public function __construct(
		private readonly NotificationService $notificationService,
		private readonly StoreApprovalHistoryService $approvalHistoryService,
		private readonly StoreBusinessTypeService $businessTypeService
	) {
	}

	public function register(Request $request): JsonResponse
	{
		$user = $request->user();
		if (!$user) {
			return response()->json([
				'data' => null,
				'meta' => [
					'request_at' => now()->toIso8601String(),
				],
				'errors' => [
					[
						'code' => 'UNAUTHENTICATED',
						'message' => 'Bạn chưa đăng nhập hoặc phiên đăng nhập đã hết hạn.',
					],
				],
			], 401);
		}

		$store = Store::query()
			->where('owner_id', $user->id)
			->orderByDesc('created_at')
			->first();

		$currentRole = strtolower((string) ($user->role ?? ''));
		$allowedRoles = ['user', 'account_user', 'admin_store_pending'];
		$allowedByExistingStore = $store && in_array(strtolower((string) ($store->status ?? '')), ['draft', 'pending', 'rejected'], true);
		if (!in_array($currentRole, $allowedRoles, true) && !$allowedByExistingStore) {
			return response()->json([
				'data' => null,
				'meta' => [
					'request_at' => now()->toIso8601String(),
				],
				'errors' => [
					[
						'code' => 'FORBIDDEN_ROLE',
						'message' => 'Vai trò hiện tại không được phép gửi hồ sơ đăng ký cửa hàng.',
					],
				],
			], 403);
		}

		$input = $request->all();
		if (array_key_exists('email', $input)) {
			$input['email'] = SafeEmail::normalizeForValidation($input['email']);
		}

		$validator = Validator::make($input, [
			'status' => ['nullable', 'string', 'in:draft,pending'],
			'name' => ['nullable', 'string', 'min:3', 'max:255'],
			'phone' => ['nullable', 'regex:/^(\\+?84|0)[0-9]{9,10}$/'],
			'address' => ['nullable', 'string', 'max:2000'],
			'location' => ['nullable', 'array'],
			'location.lat' => ['required_with:location', 'numeric', 'between:-90,90'],
			'location.lng' => ['required_with:location', 'numeric', 'between:-180,180'],
			'description' => ['nullable', 'string', 'max:5000'],
			'email' => ['nullable', 'string', 'max:255', SafeEmail::validationRule()],
			'logo_url' => ['nullable', 'url', 'max:2000'],
			'cover_image' => ['nullable', 'url', 'max:2000'],
			'license_number' => ['nullable', 'string', 'max:100'],
			'license_image' => ['nullable', 'url', 'max:2000'],
			'representative' => ['nullable', 'string', 'max:255'],
			'business_type_id' => ['nullable', 'integer', Rule::exists('store_business_types', 'id')],
			'business_type' => ['nullable', 'string', 'max:255'],
			'bank_name' => ['nullable', 'string', 'max:255'],
			'bank_account' => ['nullable', 'string', 'max:255'],
			'cccd_front_url' => ['nullable', 'url', 'max:2000'],
			'cccd_back_url' => ['nullable', 'url', 'max:2000'],
			'sla_timeout_minutes' => ['nullable', 'integer', 'min:1', 'max:120'],
		]);

		if ($validator->fails()) {
			return $this->validationErrorResponse($validator->errors()->toArray());
		}

		$validated = $validator->validated();
		if (array_key_exists('email', $validated)) {
			$validated['email'] = SafeEmail::normalizeNullable($validated['email']);
		}
		$validated = array_merge($validated, $this->businessTypeService->storePayloadFromInput($validated));
		$targetStatus = strtolower((string) ($validated['status'] ?? 'pending'));

		if ($store && in_array(strtolower((string) $store->status), ['approved', 'active'], true)) {
			return response()->json([
				'data' => null,
				'meta' => [
					'request_at' => now()->toIso8601String(),
				],
				'errors' => [
					[
						'code' => 'STORE_ALREADY_APPROVED',
						'message' => 'Cửa hàng đã được duyệt, không thể gửi đăng ký lại.',
					],
				],
			], 409);
		}

		$storeLocation = $this->resolveStoreLocation($store);

		$effective = [
			'name' => $validated['name'] ?? ($store->name ?? null),
			'phone' => $validated['phone'] ?? ($store->phone ?? null),
			'address' => $validated['address'] ?? ($store->address ?? null),
			'license_image' => $validated['license_image'] ?? ($store->license_image ?? null),
			'location' => $validated['location'] ?? $storeLocation,
		];

		if ($targetStatus === 'pending') {
			$fieldErrors = [];

			if (empty($effective['name']) || Str::length((string) $effective['name']) < 3) {
				$fieldErrors['name'][] = 'Tên cửa hàng phải có ít nhất 3 ký tự.';
			}

			if (empty($effective['phone'])) {
				$fieldErrors['phone'][] = 'Số điện thoại là bắt buộc khi gửi duyệt.';
			} elseif (preg_match('/^(\\+?84|0)[0-9]{9,10}$/', (string) $effective['phone']) !== 1) {
				$fieldErrors['phone'][] = 'Số điện thoại không hợp lệ.';
			}

			if (empty($effective['address'])) {
				$fieldErrors['address'][] = 'Địa chỉ là bắt buộc khi gửi duyệt.';
			}

			if (!is_array($effective['location'])) {
				$fieldErrors['location'][] = 'Tọa độ GPS là bắt buộc khi gửi duyệt.';
			} else {
				$lat = data_get($effective['location'], 'lat');
				$lng = data_get($effective['location'], 'lng');
				if (!is_numeric($lat) || (float) $lat < -90 || (float) $lat > 90) {
					$fieldErrors['location.lat'][] = 'Vĩ độ không hợp lệ.';
				}
				if (!is_numeric($lng) || (float) $lng < -180 || (float) $lng > 180) {
					$fieldErrors['location.lng'][] = 'Kinh độ không hợp lệ.';
				}
			}

			if (!empty($fieldErrors)) {
				return $this->validationErrorResponse($fieldErrors);
			}
		}

		$oldStatus = $store ? strtolower((string) $store->status) : null;
		$oldRole = strtolower((string) ($user->role ?? ''));
		$persistedStore = null;
		$persistedRole = $oldRole;

		DB::transaction(function () use ($validated, $targetStatus, $user, $store, &$persistedStore, &$persistedRole) {
			$requestedLocation = array_key_exists('location', $validated) ? $validated['location'] : null;
			$isCreating = !$store;

			if ($isCreating) {
				$persistedStore = new Store();
				$persistedStore->owner_id = $user->id;
				$persistedStore->store_code = $this->generateStoreCode();
				$persistedStore->is_open = false;
			} else {
				$persistedStore = $store;
			}

			$updatableFields = [
				'name',
				'description',
				'address',
				'phone',
				'email',
				'logo_url',
				'cover_image',
				'license_number',
				'license_image',
				'cccd_front_url',
				'cccd_back_url',
				'representative',
				'business_type_id',
				'business_type',
				'bank_name',
				'bank_account',
			];

			foreach ($updatableFields as $field) {
				if (array_key_exists($field, $validated)) {
					$persistedStore->{$field} = $validated[$field];
				}
			}

			$persistedStore->status = $targetStatus;
			if ($targetStatus === 'pending') {
				$persistedStore->rejection_reason = null;

				if (Schema::hasColumn('stores', 'submitted_at')) {
					$persistedStore->submitted_at = now();
				}

				if (Schema::hasColumn('stores', 'submitted_by')) {
					$persistedStore->submitted_by = $user->id;
				}
			}

			// Persist custom SLA if provided as `sla_timeout_minutes`
			if (array_key_exists('sla_timeout_minutes', $validated) && Schema::hasColumn('stores', 'custom_sla_minutes')) {
				$persistedStore->custom_sla_minutes = (int) $validated['sla_timeout_minutes'];
			}
			$persistedStore->save();

			if (is_array($requestedLocation) && Schema::hasColumn('stores', 'location')) {
				$lat = (float) data_get($requestedLocation, 'lat');
				$lng = (float) data_get($requestedLocation, 'lng');
				$this->updateStoreLocation($persistedStore->id, $lng, $lat);
				$persistedStore->refresh();
			}

			if ($targetStatus === 'pending' && Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
				DB::table('users')->where('id', $user->id)->update([
					'role' => 'admin_store_pending',
					'updated_at' => now(),
				]);
				$persistedRole = 'admin_store_pending';
			}
		});

		$store = $persistedStore;
		if (!$store) {
			return response()->json([
				'data' => null,
				'meta' => [
					'request_at' => now()->toIso8601String(),
				],
				'errors' => [
					[
						'code' => 'STORE_PERSIST_FAILED',
						'message' => 'Không thể lưu hồ sơ cửa hàng. Vui lòng thử lại.',
					],
				],
			], 500);
		}

		if ($targetStatus === 'pending') {
			$this->approvalHistoryService->recordSubmission($store, $user->id ?? null);
			$pendingCount = Store::where('status', 'pending')->count();
			event(new \App\Events\StoreApprovalUpdated($store->id, 'pending', $pendingCount));
		}

		if ($targetStatus !== $oldStatus) {
			Event::dispatch('store.approval_status_changed', [
				'store_id' => $store->id,
				'owner_id' => $user->id,
				'old_status' => $oldStatus,
				'new_status' => $targetStatus,
				'old_role' => $oldRole,
				'new_role' => $persistedRole,
				'changed_at' => now()->toIso8601String(),
			]);
		}

		if ($targetStatus === 'pending' && $targetStatus !== $oldStatus) {
			$this->createPartnerRegistrationNotifications($store, $user);
		}

		$completion = $this->calculateProfileCompletion($store);
		$canAccessAdminStore = in_array($persistedRole, ['admin_store_pending', 'admin_store', 'store_admin'], true);
		$canManageAdminStore = in_array(strtolower((string) ($store->status ?? '')), ['approved', 'active'], true)
			&& in_array($persistedRole, ['admin_store', 'store_admin'], true);

		return response()->json([
			'data' => [
				'id' => $store->id,
				'name' => $store->name,
				'store_status' => $store->status,
				'status' => $store->status,
				'user_role' => $persistedRole,
				'account_role' => $persistedRole,
				'can_access_admin_store' => $canAccessAdminStore,
				'can_manage_admin_store' => $canManageAdminStore,
				'profile_completion' => $completion,
				'rejection_reason' => $store->rejection_reason,
				'approved_at' => optional($store->approved_at)?->toIso8601String(),
				'details' => [
					'address' => $store->address,
					'phone' => $store->phone,
					'license_image' => $store->license_image,
					'cccd_front_url' => $store->cccd_front_url,
					'cccd_back_url' => $store->cccd_back_url,
				],
			],
			'meta' => [
				'request_at' => now()->toIso8601String(),
			],
			'errors' => [],
		], 200);
	}

	public function approve(Request $request, string $storeId): JsonResponse
	{
		$actor = $request->user();
		if (!$actor) {
			return response()->json([
				'data' => null,
				'meta' => ['request_at' => now()->toIso8601String()],
				'errors' => [[
					'code' => 'UNAUTHENTICATED',
					'message' => 'Bạn chưa đăng nhập hoặc phiên đăng nhập đã hết hạn.',
				]],
			], 401);
		}

		if (!in_array((string) $actor->role, ['super_admin', 'admin_super', 'admin'], true)) {
			return response()->json([
				'data' => null,
				'meta' => ['request_at' => now()->toIso8601String()],
				'errors' => [[
					'code' => 'FORBIDDEN_ROLE',
					'message' => 'Bạn không có quyền duyệt hồ sơ cửa hàng.',
				]],
			], 403);
		}

		$store = Store::query()->where('id', $storeId)->first();
		if (!$store) {
			return response()->json([
				'data' => null,
				'meta' => ['request_at' => now()->toIso8601String()],
				'errors' => [[
					'code' => 'STORE_NOT_FOUND',
					'message' => 'Không tìm thấy hồ sơ cửa hàng cần duyệt.',
				]],
			], 404);
		}

		$oldStatus = strtolower((string) ($store->status ?? 'pending'));
		$ownerId = $store->owner_id;

		DB::transaction(function () use ($store, $actor, $ownerId) {
			$store->status = 'approved';
			$store->approved_by = $actor->id;
			$store->approved_at = now();
			$store->rejection_reason = null;
			$store->save();

			$this->approvalHistoryService->markReviewed($store, 'approved', $actor->id);

			if ($ownerId && Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
				DB::table('users')->where('id', $ownerId)->update([
					'role' => 'admin_store',
					'updated_at' => now(),
				]);
			}
		});

		Event::dispatch('store.approval_status_changed', [
			'store_id' => $store->id,
			'owner_id' => $ownerId,
			'old_status' => $oldStatus,
			'new_status' => 'approved',
			'changed_at' => now()->toIso8601String(),
		]);

		$pendingCount = Store::where('status', 'pending')->count();
		event(new \App\Events\StoreApprovalUpdated($store->id, 'approved', $pendingCount));

		return response()->json([
			'data' => [
				'id' => $store->id,
				'store_status' => 'approved',
				'user_role' => 'admin_store',
				'approved_at' => optional($store->approved_at)?->toIso8601String(),
			],
			'meta' => ['request_at' => now()->toIso8601String()],
			'errors' => [],
		]);
	}

	public function reject(Request $request, string $storeId): JsonResponse
	{
		$actor = $request->user();
		if (!$actor) {
			return response()->json([
				'data' => null,
				'meta' => ['request_at' => now()->toIso8601String()],
				'errors' => [[
					'code' => 'UNAUTHENTICATED',
					'message' => 'Bạn chưa đăng nhập hoặc phiên đăng nhập đã hết hạn.',
				]],
			], 401);
		}

		if (!in_array((string) $actor->role, ['super_admin', 'admin_super', 'admin'], true)) {
			return response()->json([
				'data' => null,
				'meta' => ['request_at' => now()->toIso8601String()],
				'errors' => [[
					'code' => 'FORBIDDEN_ROLE',
					'message' => 'Bạn không có quyền từ chối hồ sơ cửa hàng.',
				]],
			], 403);
		}

		$validator = Validator::make($request->all(), [
			'rejection_reason' => ['required', 'string', 'min:5', 'max:2000'],
		]);

		if ($validator->fails()) {
			return $this->validationErrorResponse($validator->errors()->toArray());
		}

		$store = Store::query()->where('id', $storeId)->first();
		if (!$store) {
			return response()->json([
				'data' => null,
				'meta' => ['request_at' => now()->toIso8601String()],
				'errors' => [[
					'code' => 'STORE_NOT_FOUND',
					'message' => 'Không tìm thấy hồ sơ cửa hàng cần từ chối.',
				]],
			], 404);
		}

		$reason = trim((string) $validator->validated()['rejection_reason']);
		$oldStatus = strtolower((string) ($store->status ?? 'pending'));
		$ownerId = $store->owner_id;

		DB::transaction(function () use ($store, $reason, $ownerId, $actor) {
			$store->status = 'rejected';
			$store->rejection_reason = $reason;
			$store->save();

			$this->approvalHistoryService->markReviewed($store, 'rejected', $actor->id, $reason);

			if ($ownerId && Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
				DB::table('users')->where('id', $ownerId)->update([
					'role' => 'account_user',
					'updated_at' => now(),
				]);
			}
		});

		Event::dispatch('store.approval_status_changed', [
			'store_id' => $store->id,
			'owner_id' => $ownerId,
			'old_status' => $oldStatus,
			'new_status' => 'rejected',
			'changed_at' => now()->toIso8601String(),
		]);

		$pendingCount = Store::where('status', 'pending')->count();
		event(new \App\Events\StoreApprovalUpdated($store->id, 'rejected', $pendingCount));

		return response()->json([
			'data' => [
				'id' => $store->id,
				'store_status' => 'rejected',
				'user_role' => 'account_user',
				'rejection_reason' => $reason,
			],
			'meta' => ['request_at' => now()->toIso8601String()],
			'errors' => [],
		]);
	}

	private function validationErrorResponse(array $messages): JsonResponse
	{
		$errors = [];
		foreach ($messages as $field => $fieldMessages) {
			foreach ((array) $fieldMessages as $message) {
				$errors[] = [
					'code' => 'VALIDATION_ERROR',
					'path' => $field,
					'message' => (string) $message,
				];
			}
		}

		return response()->json([
			'data' => null,
			'meta' => [
				'request_at' => now()->toIso8601String(),
			],
			'errors' => $errors,
		], 422);
	}

	private function generateStoreCode(): string
	{
		do {
			$code = 'ST' . now()->format('ymd') . strtoupper(Str::random(6));
		} while (Store::query()->where('store_code', $code)->exists());

		return $code;
	}

	private function calculateProfileCompletion(Store $store): array
	{
		$checks = [
			'name' => (string) ($store->name ?? ''),
			'phone' => (string) ($store->phone ?? ''),
			'address' => (string) ($store->address ?? ''),
			'license_image' => (string) ($store->license_image ?? ''),
		];

		$location = $store->location;
		$hasLocation = is_array($location)
			&& is_numeric(data_get($location, 'lat'))
			&& is_numeric(data_get($location, 'lng'));

		$total = count($checks) + 1;
		$filled = 0;
		$missing = [];

		foreach ($checks as $field => $value) {
			if (trim($value) !== '') {
				$filled++;
			} else {
				$missing[] = $field;
			}
		}

		if ($hasLocation) {
			$filled++;
		} else {
			$missing[] = 'location';
		}

		return [
			'percent' => (int) floor(($filled * 100) / $total),
			'missing_fields' => $missing,
		];
	}

	private function resolveStoreLocation(?Store $store): ?array
	{
		if (!$store || !Schema::hasColumn('stores', 'location')) {
			return null;
		}

		$location = $store->location;
		if (is_array($location)) {
			$lat = data_get($location, 'lat');
			$lng = data_get($location, 'lng');
			if (is_numeric($lat) && is_numeric($lng)) {
				return [
					'lat' => (float) $lat,
					'lng' => (float) $lng,
				];
			}
		}

		if (is_string($location)) {
			$decoded = json_decode($location, true);
			if (json_last_error() === JSON_ERROR_NONE) {
				$lat = data_get($decoded, 'lat');
				$lng = data_get($decoded, 'lng');
				if (is_numeric($lat) && is_numeric($lng)) {
					return [
						'lat' => (float) $lat,
						'lng' => (float) $lng,
					];
				}
			}
		}

		$driver = DB::connection()->getDriverName();
		if ($driver === 'pgsql') {
			$row = DB::table('stores')
				->where('id', $store->id)
				->selectRaw('ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng')
				->first();
		} else {
			$row = DB::table('stores')
				->where('id', $store->id)
				->selectRaw('ST_Y(location) as lat, ST_X(location) as lng')
				->first();
		}

		if ($row && is_numeric($row->lat ?? null) && is_numeric($row->lng ?? null)) {
			return [
				'lat' => (float) $row->lat,
				'lng' => (float) $row->lng,
			];
		}

		return null;
	}

	private function updateStoreLocation(string $storeId, float $lng, float $lat): void
	{
		$driver = DB::connection()->getDriverName();

		if ($driver === 'pgsql') {
			DB::table('stores')
				->where('id', $storeId)
				->update([
					'location' => DB::raw("ST_GeomFromText('POINT({$lng} {$lat})', 4326)"),
				]);

			return;
		}

		DB::table('stores')
			->where('id', $storeId)
			->update([
				'location' => DB::raw("ST_GeomFromText('POINT({$lng} {$lat})')"),
			]);
	}

	private function createPartnerRegistrationNotifications(Store $store, object $owner): void
	{
		$storeCode = trim((string) ($store->store_code ?? ''));
		$storeLabel = trim((string) ($store->name ?? ''));
		$ownerLabel = trim((string) ($owner->full_name ?? $owner->name ?? $owner->email ?? ''));

		if ($storeCode === '') {
			$storeCode = (string) $store->id;
		}

		if ($storeLabel === '') {
			$storeLabel = 'Cua hang chua dat ten';
		}

		if ($ownerLabel === '') {
			$ownerLabel = 'User #' . (string) ($owner->id ?? 'unknown');
		}

		try {
			$this->notificationService->create([
				'audience_scope' => 'admin_shared',
				'type' => 'system',
				'title' => 'Đăng ký đối tác mới',
				'body' => 'Có hồ sơ đăng ký đối tác mới cần duyệt: ' . $storeCode . ' - ' . $storeLabel . '.',
				'message' => 'Có hồ sơ đăng ký đối tác mới cần duyệt. Cửa hàng: ' . $storeCode . ' - ' . $storeLabel . '. Chủ hồ sơ: ' . $ownerLabel . '.',
				'action_url' => self::ACTION_KEY_ADMIN_STORE_APPROVALS_PENDING,
			]);

			$this->notificationService->create([
				'audience_scope' => 'user_private',
				'user_id' => (string) ($owner->id ?? ''),
				'type' => 'system',
				'title' => 'Gửi đăng ký đối tác thành công',
				'body' => 'Hồ sơ đăng ký đối tác của bạn đã được ghi nhận và đang chờ duyệt.',
				'message' => 'Bạn đã gửi đăng ký đối tác thành công cho cửa hàng ' . $storeLabel . '. Hệ thống sẽ thông báo ngay khi có kết quả duyệt.',
				'action_url' => self::ACTION_KEY_CLIENT_STORE_PARTNER_REGISTRATION,
			]);
		} catch (\Throwable $exception) {
			report($exception);
		}
	}

	/**
	 * GET /api/store-registration/status
	 * Dedicated public endpoint (auth:sanctum only) for the client-side store
	 * partner registration page. Returns the current status and rejection reason
	 * of the authenticated user's latest store application WITHOUT requiring any
	 * store-admin role. Works correctly for draft / pending / rejected / approved.
	 */
	public function getRegistrationStatus(Request $request): JsonResponse
	{
		$user = $request->user();

		if (!$user) {
			return response()->json([
				'data' => null,
				'meta' => ['request_at' => now()->toIso8601String()],
				'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Bạn chưa đăng nhập.']],
			], 401);
		}

		$requestedStoreId = trim((string) $request->query('store_id', ''));

		$storeQuery = Store::query()->where('owner_id', $user->id);
		if ($requestedStoreId !== '') {
			$storeQuery->where('id', $requestedStoreId);
		} else {
			$storeQuery->orderByDesc('updated_at');
		}

		$store = $storeQuery->first();

		if (!$store) {
			if ($requestedStoreId !== '') {
				return response()->json([
					'data' => null,
					'meta' => ['request_at' => now()->toIso8601String()],
					'errors' => [[
						'code' => 'STORE_NOT_FOUND',
						'message' => 'Không tìm thấy cửa hàng cần tải hồ sơ.',
					]],
				], 404);
			}

			return response()->json([
				'data' => [
					'has_store' => false,
					'status'    => 'none',
					'approval_status' => 'none',
					'rejection_reason' => null,
					'submitted_at' => null,
					'reviewed_at' => null,
					'store' => null,
				],
				'meta' => [
					'request_at' => now()->toIso8601String(),
					'debug_user_id' => $user->id
				],
				'errors' => [],
			]);
		}

		$status = strtolower((string) ($store->status ?? 'draft'));

		// Map store status to a canonical approval_status the frontend understands
		$approvalStatus = match ($status) {
			'approved', 'active' => 'approved',
			'pending'            => 'pending',
			'rejected'           => 'rejected',
			default              => 'draft',
		};

		$submittedAt = null;
		if (Schema::hasColumn('stores', 'submitted_at') && $store->submitted_at) {
			$submittedAt = optional($store->submitted_at)->toIso8601String();
		}

		$reviewedAt = null;
		if (Schema::hasColumn('stores', 'approved_at') && $store->approved_at) {
			$reviewedAt = optional($store->approved_at)->toIso8601String();
		}
		
		// For rejected stores, use updated_at as the time of rejection
		if (!$reviewedAt && $approvalStatus === 'rejected') {
			$reviewedAt = optional($store->updated_at)->toIso8601String();
		}

		$licenseImages = [];
		if (Schema::hasTable('store_licenses')) {
			$licenseImages = $store->licenses()
				->orderBy('created_at')
				->pluck('image_url')
				->filter(fn ($value) => is_string($value) && trim($value) !== '')
				->map(fn ($value) => trim((string) $value))
				->values()
				->all();
		}

		if (!empty($store->license_image) && !in_array((string) $store->license_image, $licenseImages, true)) {
			$licenseImages[] = (string) $store->license_image;
		}

		return response()->json([
			'data' => [
				'has_store'       => true,
				'id'              => $store->id, // Added for tracking
				'status'          => $status,
				'approval_status' => $approvalStatus,
				'rejection_reason' => $store->rejection_reason ?? null,
				'submitted_at'    => $submittedAt,
				'reviewed_at'     => $reviewedAt,
				'sla_timeout_minutes' => (Schema::hasColumn('stores', 'custom_sla_minutes') && $store->custom_sla_minutes !== null) ? (int) $store->custom_sla_minutes : null,
				'license_images' => $licenseImages,
				'store' => [
					'id'               => $store->id,
					'name'             => $store->name,
					'address'          => $store->address,
					'phone'            => $store->phone,
					'email'            => $store->email,
					'representative'   => $store->representative,
					'business_type_id' => Schema::hasColumn('stores', 'business_type_id') ? $store->business_type_id : null,
					'business_type'    => $store->business_type,
					'bank_name'        => $store->bank_name,
					'bank_account'     => $store->bank_account,
					'license_number'   => $store->license_number,
					'license_image'    => $store->license_image,
					'license_images'   => $licenseImages,
					'cccd_front_url'   => $store->cccd_front_url,
					'cccd_back_url'    => $store->cccd_back_url,
					'description'      => $store->description,
					'store_code'       => $store->store_code,
					'status'           => $store->status,
					'sla_timeout_minutes' => (Schema::hasColumn('stores', 'custom_sla_minutes') && $store->custom_sla_minutes !== null) ? (int) $store->custom_sla_minutes : null,
					'custom_sla_minutes' => (Schema::hasColumn('stores', 'custom_sla_minutes') && $store->custom_sla_minutes !== null) ? (int) $store->custom_sla_minutes : null,
					'updated_at'       => optional($store->updated_at)->toIso8601String(),
					'rejection_reason' => $store->rejection_reason,
				],
			],
			'meta' => [
				'request_at' => now()->toIso8601String(),
				'debug_info' => [
					'store_id' => $store->id,
					'raw_status' => $store->status,
					'has_reason' => !empty($store->rejection_reason)
				]
			],
			'errors' => [],
		]);
	}
}
