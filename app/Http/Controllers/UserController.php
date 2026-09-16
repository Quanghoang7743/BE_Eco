<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Specialist;
use App\Models\TicketLedger;
use App\Models\RewardLog;
use App\Models\User;
use App\Services\SpecialistDocumentService;
use App\Support\AdminCmsAccess;
use App\Support\SafeEmail;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

class UserController extends Controller
{
    private const CMS_ROLE_NAMES = [
        'super_admin',
        'admin',
        'operation_admin',
        'finance_admin',
        'sale',
    ];

    // Chỉ dùng để ẩn các role cũ đã từng auto seed trong DB.
    private const OLD_DEFAULT_ROLE_NAMES = [
        'admin_store',
        'user',
    ];

    private const CUSTOMER_USER_ROLE_NAMES = [
        'user',
        'account_user',
        'affiliate',
    ];

    private const CUSTOMER_STORE_ROLE_NAMES = [
        'admin_store',
        'admin_store_pending',
    ];

    private const CUSTOMER_ROLE_NAMES = [
        'user',
        'account_user',
        'affiliate',
        'admin_store',
        'admin_store_pending',
    ];

    public function __construct(
        private readonly SpecialistDocumentService $specialistDocumentService
    ) {
    }

    public function index(Request $request)
    {
        return view('admin.user.index');
    }

    public function salesIndex(Request $request)
    {
        $pageTitle = 'Danh sách chuyên viên cửa hàng';
        $createUrl = route('admin.users.create', ['role' => 'sale']);
        $createLabel = 'Thêm chuyên viên cửa hàng';

        return view('admin.user.sales', compact(
            'pageTitle',
            'createUrl',
            'createLabel'
        ));
    }

    public function salesDatatable(Request $request)
    {
        $columns = [
            1 => 'id',
            3 => 'name',
            4 => 'phone',
            5 => 'status',
            7 => 'role',
            8 => 'gender',
            9 => 'created_at',
        ];

        $search = (string) $request->input('search.value', '');
        $statusFilter = (string) $request->input('status_filter', '');
        $areaFilter = trim((string) $request->input('area_filter', ''));
        $orderField = $columns[$request->input('order.0.column', 0)] ?? 'id';
        if ($orderField === 'status' && !Schema::hasColumn('users', 'status')) {
            $orderField = 'id';
        }
        $orderDir = $request->input('order.0.dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 20);

        $baseQuery = User::query()
            ->with(['assignedStores:id,store_code,name,address,sale_id'])
            ->withCount('assignedStores')
            ->where('role', 'sale');

        if ($statusFilter !== '' && Schema::hasColumn('users', 'status')) {
            if ($statusFilter === 'active') {
                $baseQuery->where(function ($query) {
                    $query->whereNull('status')->orWhere('status', 'active');
                });
            } else {
                $baseQuery->where('status', $statusFilter);
            }
        }

        if ($areaFilter !== '') {
            $baseQuery->whereHas('assignedStores', function ($storeQuery) use ($areaFilter) {
                $storeQuery->where(function ($query) use ($areaFilter) {
                    $query->where('store_code', 'like', "%{$areaFilter}%")
                        ->orWhere('name', 'like', "%{$areaFilter}%")
                        ->orWhere('address', 'like', "%{$areaFilter}%");
                });
            });
        }
        $recordsTotal = (clone $baseQuery)->count();

        if ($search !== '') {
            $baseQuery->where(function ($q) use ($search) {
                $q->where('id', $search)
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $recordsFiltered = $baseQuery->count();

        $users = $baseQuery
            ->orderBy($orderField, $orderDir)
            ->skip($start)
            ->take($length)
            ->get();

        $data = $users->map(function (User $user) {
            $avatar = $user->avatar;
            if ($avatar) {
                $avatarUrl = Str::startsWith($avatar, ['http://', 'https://'])
                    ? $avatar
                    : asset('storage/' . $avatar);
            } else {
                $avatarUrl = 'https://static.vecteezy.com/system/resources/previews/009/292/244/non_2x/default-avatar-icon-of-social-media-user-vector.jpg';
            }

            $displayName = $user->full_name ?: $user->name;
            $genderLabel = $user->gender === 'male'
                ? 'Nam'
                : ($user->gender === 'female' ? 'Nữ' : 'Khác');

            $status = $this->normalizedStatus($user);
            $toggleRoute = $status === 'locked'
                ? route('admin.users.unlock', $user->id)
                : route('admin.users.lock', $user->id);
            $toggleLabel = $status === 'locked' ? 'Mở khóa' : 'Khóa';
            $toggleClass = $status === 'locked' ? 'btn-success' : 'btn-secondary';
            $toggleConfirm = $status === 'locked'
                ? 'Mở khóa tài khoản chuyên viên này?'
                : 'Khóa tài khoản chuyên viên này?';
            $statusIcon = $status === 'locked' ? '<i class="fas fa-lock-open"></i>' : '<i class="fas fa-lock"></i>';
            $statusForm = '<form action="' . $toggleRoute . '" method="POST" style="display:inline-block">'
                . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
                . '<input type="hidden" name="_method" value="PATCH">'
                . '<button type="submit" class="btn ' . $toggleClass . ' btn-sm btn-action" title="' . $toggleLabel . '" onclick="return confirm(\'' . $toggleConfirm . '\')">' . $statusIcon . '</button>'
                . '</form>';

            $stores = $user->assignedStores ?? collect();
            $storeCount = (int) ($user->assigned_stores_count ?? $stores->count());
            $storePreview = $stores
                ->take(3)
                ->map(fn($store) => e($store->store_code ?: $store->name ?: $store->id))
                ->implode('<br>');
            $storeScopeHtml = '<span class="badge bg-info">' . $storeCount . ' cua hang</span>';
            if ($storePreview !== '') {
                $storeScopeHtml .= '<div class="small text-muted mt-1">' . $storePreview . '</div>';
            }

            return [
                'checkbox' => '<input type="checkbox" class="user-row-checkbox" value="' . $user->id . '">',
                'id' => $user->id,
                'avatar' => '<img src="' . e($avatarUrl) . '" width="50" alt="">',
                'name' => '<a href="#" class="user-detail-link" data-id="' . $user->id . '">' . e($displayName) . '</a>',
                'phone' => $user->phone ?? '---',
                'status' => $this->statusBadge($status),
                'assigned_stores' => $storeScopeHtml,
                'role' => ucfirst($user->role),
                'gender' => $genderLabel,
                'created_at' => $user->created_at?->format('Y-m-d H:i') ?? '---',
                'action' => '<a href="#" class="btn btn-info btn-sm user-ledger-link btn-action" data-id="' . $user->id . '" title="Nhật ký vé"><i class="fas fa-ticket-alt"></i></a> '
                    . '<a href="' . route('admin.users.edit', $user->id) . '" class="btn btn-warning btn-sm btn-action" title="Sửa"><i class="fas fa-edit"></i></a> '
                    . $statusForm,
            ];
        });

        return response()->json([
            'draw' => (int) $request->input('draw', 1),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    public function usersDatatable(Request $request)
    {
        $listType = (string) $request->input('list_type', 'account');
        $search = (string) $request->input('search.value', '');
        $roleFilter = (string) $request->input('role_filter', '');
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 20);

        $baseQuery = User::query();

        if ($this->spatieTablesReady()) {
            $baseQuery->with('roles');
        }

        if ($listType === 'customer') {
            $columns = [
                1 => 'users.name',
                2 => 'users.phone',
                3 => 'users.role',
                5 => 'users.gender',
                6 => 'users.created_at',
            ];

            $baseQuery->select('users.*')
                ->leftJoin('client_user_verifications', 'users.id', '=', 'client_user_verifications.user_id')
                ->whereIn('users.role', self::CUSTOMER_ROLE_NAMES)
                ->with('clientVerification');

            if ($roleFilter !== '') {
                $roleFilterNames = $this->customerRoleFilterNames($roleFilter);
                if ($roleFilterNames === []) {
                    $baseQuery->whereRaw('1 = 0');
                } else {
                    $baseQuery->whereIn('users.role', $roleFilterNames);
                }
            }

            $verificationFilter = (string) $request->input('verification_filter', '');
            if ($verificationFilter !== '') {
                if ($verificationFilter === 'unverified') {
                    $baseQuery->whereDoesntHave('clientVerification');
                } else {
                    $baseQuery->where('client_user_verifications.status', $verificationFilter);
                }
            }

            $orderField = $columns[$request->input('order.0.column', 0)] ?? 'users.created_at';
            $orderDir = $request->input('order.0.dir', 'desc') === 'asc' ? 'asc' : 'desc';

        } elseif ($listType === 'verification') {
            $columns = [
                1 => 'users.name',
                2 => 'users.phone',
                6 => 'client_user_verifications.submitted_at',
            ];

            $baseQuery->select('users.*')
                ->join('client_user_verifications', 'users.id', '=', 'client_user_verifications.user_id')
                ->with('clientVerification');

            if ($roleFilter !== '') {
                $baseQuery->where('client_user_verifications.status', $roleFilter);
            }

            $orderField = $columns[$request->input('order.0.column', 0)] ?? 'client_user_verifications.submitted_at';
            $orderDir = $request->input('order.0.dir', 'desc') === 'asc' ? 'asc' : 'desc';

        } else {
            $columns = [
                1 => 'users.name',
                2 => 'users.phone',
                3 => 'users.role',
                4 => 'users.gender',
                5 => 'users.created_at',
            ];

            $baseQuery->whereIn('users.role', ['super_admin', 'admin', 'sale']);
            if ($roleFilter !== '') {
                $baseQuery->where('users.role', $roleFilter);
            }

            $orderField = $columns[$request->input('order.0.column', 0)] ?? 'users.created_at';
            $orderDir = $request->input('order.0.dir', 'desc') === 'asc' ? 'asc' : 'desc';
        }

        $recordsTotal = (clone $baseQuery)->count();

        if ($search !== '') {
            $baseQuery->where(function ($q) use ($search, $listType) {
                $q->where('users.id', $search)
                    ->orWhere('users.name', 'like', "%{$search}%")
                    ->orWhere('users.full_name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%")
                    ->orWhere('users.phone', 'like', "%{$search}%");

                if (in_array($listType, ['customer', 'verification'], true)) {
                    $q->orWhere('client_user_verifications.full_name', 'like', "%{$search}%")
                        ->orWhere('client_user_verifications.id_number', 'like', "%{$search}%");
                }
            });
        }

        $recordsFiltered = $baseQuery->count();

        if ($listType === 'customer' && $orderField === 'users.created_at' && $orderDir === 'desc') {
            $users = $baseQuery
                ->orderByRaw("(CASE WHEN client_user_verifications.status = 'pending' THEN 0 ELSE 1 END) ASC")
                ->orderByRaw("COALESCE(client_user_verifications.submitted_at, client_user_verifications.created_at) DESC")
                ->orderBy('users.created_at', 'desc')
                ->skip($start)
                ->take($length)
                ->get();
        } else {
            $users = $baseQuery
                ->orderBy($orderField, $orderDir)
                ->skip($start)
                ->take($length)
                ->get();
        }

        $data = $users->map(function (User $user) use ($listType) {
            $avatar = $user->avatar;
            if ($avatar) {
                $avatarUrl = Str::startsWith($avatar, ['http://', 'https://'])
                    ? $avatar
                    : asset('storage/' . $avatar);
            } else {
                $avatarUrl = 'https://static.vecteezy.com/system/resources/previews/009/292/244/non_2x/default-avatar-icon-of-social-media-user-vector.jpg';
            }

            $displayName = $user->full_name ?: $user->name;
            $genderLabel = $user->gender === 'male'
                ? 'Nam'
                : ($user->gender === 'female' ? 'Nữ' : 'Khác');

            $deleteForm = '<form action="' . route('admin.users.destroy', $user->id) . '" method="POST" style="display:inline-block">'
                . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
                . '<input type="hidden" name="_method" value="DELETE">'
                . '<button type="submit" class="btn btn-danger btn-sm btn-action" title="Xóa" onclick="return confirm(\'Xóa tài khoản này?\')"><i class="fas fa-trash-alt"></i></button>'
                . '</form>';

            if ($listType === 'verification') {
                $verification = $user->clientVerification;
                $statusLabels = [
                    'pending' => '<span class="badge bg-warning text-dark">Chờ duyệt</span>',
                    'approved' => '<span class="badge bg-success">Đã duyệt</span>',
                    'rejected' => '<span class="badge bg-danger">Từ chối</span>',
                ];
                $statusHtml = $verification ? ($statusLabels[$verification->status] ?? $verification->status) : '—';
                $verificationDeleteForm = $verification
                    ? '<form action="' . route('admin.user-verifications.destroy', $verification->id) . '" method="POST" style="display:inline-block">'
                    . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
                    . '<input type="hidden" name="_method" value="DELETE">'
                    . '<input type="hidden" name="redirect_to" value="users">'
                    . '<button type="submit" class="btn btn-danger btn-sm btn-action" title="Xóa hồ sơ xác thực" onclick="return confirm(\'Xóa hồ sơ xác thực này? Tài khoản người dùng vẫn được giữ nguyên.\')"><i class="fas fa-trash-alt"></i></button>'
                    . '</form>'
                    : '';

                return [
                    'checkbox' => $verification
                        ? '<input type="checkbox" class="user-row-checkbox" value="' . $verification->id . '">'
                        : '',
                    'avatar' => '<img src="' . e($avatarUrl) . '" width="50" alt="">',
                    'name' => '<a href="#" class="user-detail-link" data-id="' . $user->id . '">' . e($displayName) . '</a>',
                    'phone' => $user->phone ?? '---',
                    'verified_name' => $verification ? e($verification->full_name) : '—',
                    'id_number' => $verification ? e($verification->id_number) : '—',
                    'status' => $statusHtml,
                    'submitted_at' => $verification && $verification->submitted_at ? $verification->submitted_at->format('Y-m-d H:i') : '—',
                    'action' => ($verification ? '<a href="' . route('admin.user-verifications.show', $verification->id) . '" class="btn btn-primary btn-sm btn-action" title="Chi tiết duyệt"><i class="fas fa-id-card"></i></a> ' : '')
                        . '<a href="' . route('admin.users.edit', $user->id) . '" class="btn btn-warning btn-sm btn-action" title="Sửa"><i class="fas fa-edit"></i></a> '
                        . $verificationDeleteForm,
                ];
            }

            $verificationHtml = '—';
            $verificationAction = '';

            if ($listType === 'customer') {
                $verification = $user->clientVerification;
                if ($verification) {
                    $statusLabels = [
                        'pending' => '<span class="badge bg-warning text-dark">Chờ duyệt</span>',
                        'approved' => '<span class="badge bg-success">Đã duyệt</span>',
                        'rejected' => '<span class="badge bg-danger">Từ chối</span>',
                    ];
                    $verificationHtml = $statusLabels[$verification->status] ?? $verification->status;
                    $verificationAction = '<a href="' . route('admin.user-verifications.show', $verification->id) . '" class="btn btn-primary btn-sm btn-action" title="Chi tiết duyệt"><i class="fas fa-id-card"></i></a> ';
                } else {
                    $verificationHtml = '<span class="badge bg-secondary text-white">Chưa xác thực</span>';
                }
            }

            return [
                'checkbox' => '<input type="checkbox" class="user-row-checkbox" value="' . $user->id . '">',
                'avatar' => '<img src="' . e($avatarUrl) . '" width="50" alt="">',
                'name' => '<a href="#" class="user-detail-link" data-id="' . $user->id . '">' . e($displayName) . '</a>',
                'phone' => $user->phone ?? '---',
                'role' => $listType === 'customer'
                    ? $this->customerRoleBadge((string) $user->role)
                    : ($user->relationLoaded('roles') && $user->roles->isNotEmpty()
                    ? $user->roles->pluck('name')->map(fn(string $role) => '<span class="badge bg-primary me-1">' . e($role) . '</span>')->implode('')
                    : '<span class="badge bg-light text-dark border">' . e(ucwords(str_replace('_', ' ', (string) $user->role))) . '</span>'),
                'verification_status' => $verificationHtml,
                'gender' => $genderLabel,
                'balance' => number_format((float) $user->balance, 0, ',', '.') . ' VND',
                'created_at' => $user->created_at?->format('Y-m-d H:i') ?? '---',
                'action' => $verificationAction
                    . '<a href="#" class="btn btn-info btn-sm user-ledger-link btn-action" data-id="' . $user->id . '" title="Nhật ký vé"><i class="fas fa-ticket-alt"></i></a> '
                    . '<a href="' . route('admin.users.edit', $user->id) . '" class="btn btn-warning btn-sm btn-action" title="Sửa"><i class="fas fa-edit"></i></a> '
                    . $deleteForm,
            ];
        });

        return response()->json([
            'draw' => (int) $request->input('draw', 1),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    public function destroyMultiple(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|distinct|exists:users,id',
        ]);

        $ids = $request->input('ids');

        $deleted = DB::transaction(function () use ($ids) {
            DB::table('commission_users')->whereIn('user_id', $ids)->delete();
            return User::whereIn('id', $ids)->delete();
        });

        return response()->json([
            'success' => true,
            'message' => "Đã xóa {$deleted} tài khoản.",
        ]);
    }

    public function destroy(User $user)
    {
        DB::transaction(function () use ($user) {
            DB::table('commission_users')->where('user_id', $user->id)->delete();
            $user->delete();
        });
        return redirect()->route('admin.users.index')->with('success', 'Xóa tài khoản thành công');
    }

    public function create()
    {
        $spatieRoles = $this->spatieTablesReady()
            ? $this->cmsRoleQuery()->orderBy('name')->get()
            : collect();
        $canManageSpecialistDocuments = $this->canManageSpecialistDocuments(request()->user());

        return view('admin.user.create', compact('spatieRoles', 'canManageSpecialistDocuments'));
    }

    public function store(Request $request)
    {
        // return $request;
        if ($request->has('email')) {
            $request->merge(['email' => SafeEmail::normalizeForValidation($request->input('email'))]);
        }

        $request->validate([
            'name' => 'required|unique:users,name|max:255',
            'full_name' => 'nullable|string|max:255',
            'email' => ['nullable', 'string', 'max:255', SafeEmail::validationRule(), Rule::unique('users', 'email')],
            'password' => 'required|min:6',
            'zalo_id' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:20',
            'gender' => 'nullable|in:male,female,other',
            'birthday' => 'nullable|date',
            'address' => 'nullable|string|max:255',
            'balance' => 'nullable|numeric|min:0',
            'role' => 'required|in:user,admin,sale',
            'status' => 'nullable|in:active,locked,inactive',
            'avatar' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);
        $this->validateSpecialistDocuments($request);
        $this->authorizeSpecialistDocumentChanges($request);

        $cmsRole = null;

        if ($request->input('role') === 'admin') {
            if (!$this->spatieTablesReady()) {
                return back()
                    ->withErrors(['cms_role' => 'Các bảng phân quyền Spatie chưa sẵn sàng.'])
                    ->withInput();
            }

            $request->validate([
                'cms_role' => 'required|string|max:80',
            ], [
                'cms_role.required' => 'Vui lòng chọn vai trò CMS cho quản trị viên.',
            ]);

            $cmsRole = Str::of($request->input('cms_role'))->lower()->replace('-', '_')->toString();

            if (!$this->cmsRoleQuery()->where('name', $cmsRole)->exists()) {
                return back()
                    ->withErrors(['cms_role' => 'Vai trò này không thuộc nhóm Admin CMS.'])
                    ->withInput();
            }
        }

        $user = new User();
        $user->name = $request->name;
        $user->full_name = $request->full_name;
        $user->email = SafeEmail::normalizeNullable($request->email);
        $user->password = Hash::make($request->password);
        $user->zalo_id = $request->zalo_id;
        $user->phone = $request->phone;
        $user->gender = $request->gender;
        $user->birthday = $request->birthday;
        $user->address = $request->address;
        $user->balance = $request->balance ?? 0;
        $user->role = $cmsRole ? $this->legacyRoleForCmsRole($cmsRole) : $request->role;
        if (Schema::hasColumn('users', 'status')) {
            $user->status = $request->input('status', 'active');
        }

        // Xử lý ảnh đại diện
        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $path = $file->store('avatars', 'public');
            $user->avatar = $path;
        }

        $user->save();

        if ($cmsRole) {
            $user->syncRoles([$cmsRole]);
        } elseif ($request->input('role') === 'sale' && $this->spatieTablesReady()) {
            $this->ensureSaleCmsRole();
            $user->syncRoles(['sale']);
        }

        if ($user->role === 'sale') {
            $this->specialistDocumentService->syncFromRequest($user, $request, $request->user());
        }

        if (!$cmsRole && $user->role === 'sale') {
            return redirect()->route('admin.users.sales')->with('success', 'Thêm người dùng thành công!');
        }

        return redirect()->route('admin.users.index', ['tab' => 'accounts'])->with('success', 'Thêm người dùng thành công!');
    }

    public function edit(User $user)
    {
        $spatieRoles = $this->spatieTablesReady()
            ? $this->cmsRoleQuery()->orderBy('name')->get()
            : collect();
        $selectedCmsRole = null;

        if ($this->spatieTablesReady()) {
            $user->loadMissing('roles');
            $cmsRoleNames = $spatieRoles->pluck('name');
            $selectedCmsRole = $user->roles
                ->pluck('name')
                ->first(fn(string $role) => $cmsRoleNames->contains($role));
        }

        $user->loadMissing('specialistProfile');
        $canManageSpecialistDocuments = $this->canManageSpecialistDocuments(request()->user());

        return view('admin.user.edit', compact(
            'user',
            'spatieRoles',
            'selectedCmsRole',
            'canManageSpecialistDocuments'
        ));
    }

    public function lock(User $user)
    {
        $this->assertCanChangeSpecialistStatus($user);

        $user->forceFill(['status' => 'locked'])->save();

        return back()->with('success', 'Da khoa tai khoan chuyen vien.');
    }

    public function unlock(User $user)
    {
        $this->assertCanChangeSpecialistStatus($user);

        $user->forceFill(['status' => 'active'])->save();

        return back()->with('success', 'Da mo khoa tai khoan chuyen vien.');
    }

    public function editUser()
    {
        $user = Auth::user();
        return view('change_info', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        if ($request->has('email')) {
            $request->merge(['email' => SafeEmail::normalizeForValidation($request->input('email'))]);
        }

        $validated = $request->validate([
            'name' => 'required|unique:users,name,' . $user->id,
            'full_name' => 'required|string|max:255',
            'email' => ['nullable', 'string', 'max:255', SafeEmail::validationRule(), Rule::unique('users', 'email')->ignore($user->id)],
            'password' => 'nullable|min:6',
            'phone' => 'required|string|max:20',
            'gender' => 'nullable|in:male,female,other',
            'birthday' => 'nullable|date',
            'address' => 'nullable|string|max:255',
            'balance' => 'nullable|numeric|min:0',
            'role' => 'required|in:user,admin,sale',
            'status' => 'nullable|in:active,locked,inactive',
            'avatar' => 'nullable|image|max:2048',
        ]);
        $this->validateSpecialistDocuments($request);
        $this->authorizeSpecialistDocumentChanges($request);

        if (array_key_exists('email', $validated)) {
            $validated['email'] = SafeEmail::normalizeNullable($validated['email']);
        }

        if (!Schema::hasColumn('users', 'status')) {
            unset($validated['status']);
        } else {
            $validated['status'] = $validated['status'] ?? 'active';
        }

        $cmsRole = null;

        if ($validated['role'] === 'admin') {
            if (!$this->spatieTablesReady()) {
                return back()
                    ->withErrors(['cms_role' => 'Các bảng phân quyền Spatie chưa sẵn sàng.'])
                    ->withInput();
            }

            $request->validate([
                'cms_role' => 'required|string|max:80',
            ], [
                'cms_role.required' => 'Vui lòng chọn vai trò CMS cho quản trị viên.',
            ]);

            $cmsRole = Str::of($request->input('cms_role'))->lower()->replace('-', '_')->toString();

            if (!$this->cmsRoleQuery()->where('name', $cmsRole)->exists()) {
                return back()
                    ->withErrors(['cms_role' => 'Vai trò này không thuộc nhóm Admin CMS.'])
                    ->withInput();
            }

            $validated['role'] = $this->legacyRoleForCmsRole($cmsRole);
        }

        if ($request->filled('password')) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }

            $validated['avatar'] = $request->file('avatar')->store('avatars', 'public');
        }

        $user->update($validated);

        if ($this->spatieTablesReady()) {
            if ($cmsRole) {
                $user->syncRoles([$cmsRole]);
            } elseif ($validated['role'] === 'sale') {
                $this->ensureSaleCmsRole();
                $user->syncRoles(['sale']);
            } else {
                $user->syncRoles([]);
            }
        }

        if ($user->role === 'sale') {
            $this->specialistDocumentService->syncFromRequest($user, $request, $request->user());
        }

        $redirectRoute = $user->role === 'sale' ? 'admin.users.sales' : 'admin.users.index';
        $redirectParameters = $user->role === 'sale' ? [] : ['tab' => 'accounts'];

        return redirect()->route($redirectRoute, $redirectParameters)->with('success', 'Cập nhật người dùng thành công');
    }

    public function downloadSpecialistDocument(Request $request, User $user, string $type, ?int $index = null)
    {
        abort_unless((string) $user->role === 'sale', 404);

        $actor = $request->user();
        $isOwner = $actor && (int) $actor->id === (int) $user->id;
        abort_unless($isOwner || $this->canManageSpecialistDocuments($actor), 403);

        /** @var Specialist|null $profile */
        $profile = $user->specialistProfile;
        abort_unless($profile, 404);

        $path = $this->specialistDocumentService->documentPath($profile, $type, $index);
        abort_unless(
            $path
            && $this->specialistDocumentService->isOwnedPath($user, $path)
            && Storage::disk('local')->exists($path),
            404
        );

        return Storage::disk('local')->download($path, basename($path));
    }

    public function updateUser(Request $request)
    {
        // $user = Auth::user();
        $user = User::where('id', Auth::user()->id)->first();

        if ($request->has('email')) {
            $request->merge(['email' => SafeEmail::normalizeForValidation($request->input('email'))]);
        }

        $validated = $request->validate([
            'name' => 'required|unique:users,name,' . $user->id,
            'full_name' => 'required|string|max:255',
            'email' => ['nullable', 'string', 'max:255', SafeEmail::validationRule(), Rule::unique('users', 'email')->ignore($user->id)],
            'password' => 'nullable|min:6',
            'phone' => 'required|string|max:20',
            'gender' => 'nullable|in:male,female,other',
            'birthday' => 'nullable|date',
            'address' => 'nullable|string|max:255',
            'avatar' => 'nullable|image|max:2048',
        ]);

        if ($request->filled('password')) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        if (array_key_exists('email', $validated)) {
            $validated['email'] = SafeEmail::normalizeNullable($validated['email']);
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }

            $validated['avatar'] = $request->file('avatar')->store('avatars', 'public');
        }

        $user->update($validated);

        return redirect()->route('account.index')->with('success', 'Cập nhật người dùng thành công');
    }
    public function member(Request $request)
    {
        $query = $request->input('q'); // Lấy giá trị tìm kiếm từ URL ?q=xxx

        $users = User::query();

        if ($query) {
            $users->where(function ($q) use ($query) {
                $q->where('full_name', 'like', '%' . $query . '%')
                    ->orWhere('phone', 'like', '%' . $query . '%')
                    ->orWhere('id', $query); // Nếu nhập đúng ID thì tìm theo ID
            });
        }

        $users = $users->get(); // <<< thêm get() vào đây

        $members = $users->map(function ($user) {
            // Tính doanh số cá nhân
            $userSaleCompleted = Order::where('referrer_id', $user->id)
                ->where('status', 'completed') // Chỉ tính đơn đã hoàn thành
                ->sum('total');
            $userSale = Order::where('referrer_id', $user->id)
                ->sum('total');

            // Tính hoa hồng đã hoàn thành
            $commission_completed = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where('order_items.referrer_id', $user->id)
                ->where('orders.status', 'completed')
                ->sum('order_items.commission_amount');

            return (object) [
                'id' => $user->id,
                'name' => $user->full_name ?? $user->name,
                'phone' => $user->phone,
                'avatar' => $user->avatar ?? null,
                'joined_at' => $user->created_at,
                'personal_sales_completed' => $userSaleCompleted,
                'personal_sales' => $userSale,
                'commission' => $commission_completed,
                'total_members' => 0,
                'branch_count' => 0,
            ];
        });

        return view('member', compact('members', 'query')); // Truyền query ra view để hiện trong ô tìm kiếm
    }

    public function ambassador()
    {
        return view('ambassador'); // Truyền query ra view để hiện trong ô tìm kiếm

    }

    public function referrer(Request $request)
    {
        $referrerId = $request->query('ref');
        if (!Auth::check()) {
            return redirect()->route('signup', ['ref' => $referrerId]);
        }
        $user = Auth::user();
        $referrers = User::find($referrerId);
        session(['referrer_id' => $referrerId]);
        return view('referrer', compact('referrers', 'user'));
    }

    // app/Http/Controllers/Admin/UserController.php
    public function detail(Request $request, $id)
    {
        $user = User::with('orders.items')->findOrFail($id);

        $orders = collect($user->orders)->sortByDesc('created_at');

        $totalOrders = $orders->count();
        $totalDays = $orders->pluck('created_at')->map(fn($d) => Carbon::parse($d)->toDateString())->unique()->count();
        $totalProducts = $orders->flatMap->items->sum('quantity');
        $totalPoints = $user->point ?? 0;
        $totalAmount = $orders->sum('total'); // giả định trường `total_amount`

        $firstOrder = $orders->sortBy('created_at')->first();
        $lastOrder = $orders->sortByDesc('created_at')->first();

        $ledgerBaseQuery = TicketLedger::query()
            ->where('user_id', (string) $user->id);

        $ledgerPerPage = max(10, min((int) $request->query('ledger_per_page', 20), 100));

        $ledgerEntries = TicketLedger::query()
            ->with(['ticket:id,ticket_code'])
            ->where('user_id', (string) $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('balance_after')
            ->paginate($ledgerPerPage, ['*'], 'ledger_page');

        $ledgerEntries->appends($request->query());

        $ledgerSummary = [
            'total_entries' => (int) (clone $ledgerBaseQuery)->count(),
            'total_in' => (int) (clone $ledgerBaseQuery)->where('amount_change', '>', 0)->sum('amount_change'),
            'total_out' => (int) abs((int) (clone $ledgerBaseQuery)->where('amount_change', '<', 0)->sum('amount_change')),
            'net_change' => (int) (clone $ledgerBaseQuery)->sum('amount_change'),
        ];

        $rewardLogs = RewardLog::with('reward')
            ->where('user_id', (string) $user->id)
            ->orderByDesc('created_at')
            ->get();

        // --- Affiliate Tab Data ---
        // Network F1
        $f1Query = User::where('referrer_id', $user->id);
        $f1Ids = (clone $f1Query)->pluck('id');
        $f1Users = (clone $f1Query)->orderByDesc('created_at')->paginate(10, ['*'], 'f1_page');
        $f1Users->appends($request->query());

        // Network F2
        $f2Users = User::whereIn('referrer_id', $f1Ids)->orderByDesc('created_at')->paginate(10, ['*'], 'f2_page');
        $f2Users->appends($request->query());

        // Affiliate Profile
        $affiliateProfile = \App\Models\AffiliateProfile::where('user_id', $user->id)->first();

        // --- Commission Tab Data ---
        // Quick Stats
        $commissionStats = [
            'withdrawn' => (float) \App\Models\WithdrawRequest::where('user_id', $user->id)
                ->where('status', 'approved')
                ->sum('amount'),
            'pending' => (float) \App\Models\AffiliateCommission::where('affiliate_id', $user->id)
                ->where('status', \App\Models\AffiliateCommission::STATUS_PENDING)
                ->sum('pending_amount'),
            'cancelled' => (float) \App\Models\AffiliateCommission::where('affiliate_id', $user->id)
                ->where('status', \App\Models\AffiliateCommission::STATUS_CANCELLED)
                ->sum('pending_amount'),
        ];

        // Detailed commissions list
        $commissionQuery = \App\Models\AffiliateCommission::with('order')
            ->where('affiliate_id', $user->id);

        if ($request->filled('commission_status')) {
            $commissionQuery->where('status', $request->input('commission_status'));
        }

        if ($request->filled('commission_start_date')) {
            $commissionQuery->whereDate('created_at', '>=', $request->input('commission_start_date'));
        }

        if ($request->filled('commission_end_date')) {
            $commissionQuery->whereDate('created_at', '<=', $request->input('commission_end_date'));
        }

        $commissions = $commissionQuery->orderByDesc('created_at')->paginate(10, ['*'], 'commission_page');
        $commissions->appends($request->query());

        return view('admin.user.detail', compact(
            'user',
            'totalOrders',
            'totalDays',
            'totalProducts',
            'totalPoints',
            'totalAmount',
            'firstOrder',
            'lastOrder',
            'orders',
            'ledgerEntries',
            'ledgerSummary',
            'rewardLogs',
            'f1Users',
            'f2Users',
            'affiliateProfile',
            'commissionStats',
            'commissions'
        ));
    }

    private function spatieTablesReady(): bool
    {
        $tables = config('permission.table_names', []);

        foreach (['roles', 'model_has_roles', 'permissions', 'role_has_permissions'] as $key) {
            if (empty($tables[$key]) || !Schema::hasTable($tables[$key])) {
                return false;
            }
        }

        return true;
    }

    private function customerRoleFilterNames(string $roleFilter): array
    {
        return match ($roleFilter) {
            'user' => self::CUSTOMER_USER_ROLE_NAMES,
            'admin_store' => self::CUSTOMER_STORE_ROLE_NAMES,
            default => [],
        };
    }

    private function customerRoleBadge(string $role): string
    {
        if (in_array($role, self::CUSTOMER_STORE_ROLE_NAMES, true)) {
            return '<span class="badge badge-role-admin-store">Admin_Store</span>';
        }

        return '<span class="badge badge-role-user">User</span>';
    }

    private function assertCanChangeSpecialistStatus(User $user): void
    {
        abort_unless(Schema::hasColumn('users', 'status'), 409, 'Cot trang thai tai khoan chua san sang.');
        abort_unless((string) $user->role === 'sale', 404, 'Khong tim thay chuyen vien.');
        abort_if((int) $user->id === (int) auth()->id(), 403, 'Khong the khoa tai khoan cua chinh ban.');
    }

    private function normalizedStatus(User $user): string
    {
        if (!Schema::hasColumn('users', 'status')) {
            return 'active';
        }

        $status = strtolower(trim((string) ($user->status ?? 'active')));

        return $status !== '' ? $status : 'active';
    }

    private function statusBadge(string $status): string
    {
        return match ($status) {
            'locked' => '<span class="badge bg-danger">Da khoa</span>',
            'inactive' => '<span class="badge bg-secondary">Tam dung</span>',
            default => '<span class="badge bg-success">Dang hoat dong</span>',
        };
    }

    private function ensureSaleCmsRole(): void
    {
        if (!$this->spatieTablesReady()) {
            return;
        }

        foreach (['DASHBOARD_VIEW', 'STORE_VIEW', 'ORDER_VIEW', 'LUCKY_TICKET_VIEW'] as $permission) {
            SpatiePermission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $role = SpatieRole::query()->firstOrCreate([
            'name' => 'sale',
            'guard_name' => 'web',
        ]);

        $role->syncPermissions(['DASHBOARD_VIEW', 'STORE_VIEW', 'ORDER_VIEW', 'LUCKY_TICKET_VIEW']);
    }

    private function cmsRoleQuery()
    {
        return SpatieRole::query()
            ->where('guard_name', 'web')
            ->where(function ($query) {
                $query->whereIn('name', self::CMS_ROLE_NAMES);

                if (Schema::hasColumn(config('permission.table_names.roles'), 'scope')) {
                    $query->orWhere(function ($scopeQuery) {
                        $scopeQuery
                            ->where('scope', 'admin_cms')
                            ->whereNotIn('name', self::OLD_DEFAULT_ROLE_NAMES);
                    });
                }
            });
    }

    private function legacyRoleForCmsRole(string $role): string
    {
        return match ($role) {
            'super_admin' => 'super_admin',
            'sale' => 'sale',
            default => 'admin',
        };
    }

    private function validateSpecialistDocuments(Request $request): void
    {
        $request->validate([
            'contract_file' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240', 'prohibited_unless:role,sale'],
            'id_card_front' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'prohibited_unless:role,sale'],
            'id_card_back' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'prohibited_unless:role,sale'],
            'other_documents' => ['nullable', 'array', 'max:10', 'prohibited_unless:role,sale'],
            'other_documents.*' => ['file', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp', 'max:10240'],
            'remove_contract_file' => ['nullable', 'boolean', 'prohibited_unless:role,sale'],
            'remove_id_card_front' => ['nullable', 'boolean', 'prohibited_unless:role,sale'],
            'remove_id_card_back' => ['nullable', 'boolean', 'prohibited_unless:role,sale'],
            'remove_other_documents' => ['nullable', 'array', 'prohibited_unless:role,sale'],
            'remove_other_documents.*' => ['boolean'],
        ], [
            'contract_file.mimes' => 'Hợp đồng chỉ chấp nhận file PDF, DOC hoặc DOCX.',
            'contract_file.max' => 'File hợp đồng không được vượt quá 10 MB.',
            'id_card_front.image' => 'CCCD mặt trước phải là file ảnh.',
            'id_card_back.image' => 'CCCD mặt sau phải là file ảnh.',
            'id_card_front.max' => 'Ảnh CCCD mặt trước không được vượt quá 5 MB.',
            'id_card_back.max' => 'Ảnh CCCD mặt sau không được vượt quá 5 MB.',
            'other_documents.max' => 'Chỉ được tải tối đa 10 giấy tờ khác mỗi lần.',
            'other_documents.*.mimes' => 'Giấy tờ khác chỉ chấp nhận PDF, DOC, DOCX hoặc ảnh.',
            '*.prohibited_unless' => 'Chỉ được đính kèm hồ sơ pháp lý cho chuyên viên Sale.',
        ]);
    }

    private function authorizeSpecialistDocumentChanges(Request $request): void
    {
        if (!$this->hasSpecialistDocumentChanges($request)) {
            return;
        }

        abort_unless($this->canManageSpecialistDocuments($request->user()), 403);
    }

    private function hasSpecialistDocumentChanges(Request $request): bool
    {
        return $request->hasFile('contract_file')
            || $request->hasFile('id_card_front')
            || $request->hasFile('id_card_back')
            || $request->hasFile('other_documents')
            || $request->boolean('remove_contract_file')
            || $request->boolean('remove_id_card_front')
            || $request->boolean('remove_id_card_back')
            || $request->filled('remove_other_documents');
    }

    private function canManageSpecialistDocuments(?User $actor): bool
    {
        if (!$actor) {
            return false;
        }

        if (AdminCmsAccess::isSuperAdmin($actor)) {
            return true;
        }

        if (!$this->spatieTablesReady()) {
            return false;
        }

        return $actor->hasAnyRole(['super_admin', 'operation_admin', 'operator'])
            || $actor->can('SALE_MANAGE');
    }
}
