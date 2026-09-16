<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RoleController extends Controller
{
    private const ROLE_META = [
        'super_admin' => [
            'title' => 'Sửa quyền: Quản trị viên',
            'description' => 'Quản lý các nhóm chức năng hệ thống',
            'scope' => '1-2 tài khoản',
            'badge' => 'Vai trò hệ thống',
        ],
        'ops_lead' => [
            'title' => 'Sửa quyền: Trưởng vận hành',
            'description' => 'Quản lý hoạt động hằng ngày, duyệt nghiệp vụ vận hành, điều phối xử lý sự cố.',
            'scope' => 'Nội bộ Ecoluck',
            'badge' => 'Vai trò hệ thống',
        ],
        'store_sales_bd' => [
            'title' => 'Sửa quyền: Sale/BD cửa hàng F&B',
            'description' => 'Tạo hồ sơ cửa hàng, theo dõi điểm bán được giao, không có quyền duyệt.',
            'scope' => 'Theo scope cá nhân/nhóm',
            'badge' => 'Vai trò hệ thống',
        ],
        'merchant_operation' => [
            'title' => 'Sửa quyền: Merchant Operation',
            'description' => 'Duyệt hồ sơ cửa hàng, menu, trạng thái hoạt động, tạm khóa khi vi phạm.',
            'scope' => 'Theo khu vực',
            'badge' => 'Vai trò hệ thống',
        ],
        'factory_product_bd' => [
            'title' => 'Sửa quyền: Factory/Product BD',
            'description' => 'Tạo hồ sơ nhà máy, sản phẩm nháp, đề xuất giá, ticket, affiliate.',
            'scope' => 'Theo nhà máy phụ trách',
            'badge' => 'Vai trò hệ thống',
        ],
        'product_operation' => [
            'title' => 'Sửa quyền: Product Operation',
            'description' => 'Kiểm tra, duyệt nội dung sản phẩm, tạm ẩn sản phẩm, quản lý trạng thái bán.',
            'scope' => 'Theo ngành hàng',
            'badge' => 'Vai trò hệ thống',
        ],
        'ticket_operation' => [
            'title' => 'Sửa quyền: Ticket Operation',
            'description' => 'Tra cứu ticket, đề xuất đóng băng/hủy ticket, theo dõi ticket bất thường.',
            'scope' => 'Không tự tạo ticket',
            'badge' => 'Vai trò hệ thống',
        ],
        'reward_room_operation' => [
            'title' => 'Sửa quyền: Reward Room Operation',
            'description' => 'Tạo phòng thưởng nháp, theo dõi phòng, chuẩn bị công bố, không tự sửa kết quả.',
            'scope' => 'Quyền kiểm soát cao',
            'badge' => 'Vai trò hệ thống',
        ],
        'affiliate_operation' => [
            'title' => 'Sửa quyền: Affiliate Operation',
            'description' => 'Quản lý KOL/KOC, link giới thiệu, hoa hồng tạm tính, yêu cầu chuyển người giới thiệu.',
            'scope' => 'Theo chiến dịch',
            'badge' => 'Vai trò hệ thống',
        ],
        'finance_accounting' => [
            'title' => 'Sửa quyền: Finance/Accounting',
            'description' => 'Lập đối soát, quản lý chứng từ, trạng thái thanh toán, báo cáo tài chính.',
            'scope' => 'Không sửa dữ liệu đơn/ticket',
            'badge' => 'Vai trò hệ thống',
        ],
        'customer_support' => [
            'title' => 'Sửa quyền: CSKH',
            'description' => 'Tra cứu thông tin, tạo phiếu hỗ trợ, chuyển xử lý, không can thiệp dữ liệu gốc.',
            'scope' => 'Theo ca/nhóm',
            'badge' => 'Vai trò hệ thống',
        ],
        'risk_control_fraud' => [
            'title' => 'Sửa quyền: Risk Control/Fraud',
            'description' => 'Theo dõi cảnh báo, đóng băng tạm thời, tạo hồ sơ vụ việc, đề xuất xử lý.',
            'scope' => 'Độc lập với sale',
            'badge' => 'Vai trò hệ thống',
        ],
        'marketing_content' => [
            'title' => 'Sửa quyền: Marketing/Content',
            'description' => 'Tạo nội dung chiến dịch, đề xuất phòng thưởng, xem báo cáo truyền thông.',
            'scope' => 'Không duyệt tài chính/ticket',
            'badge' => 'Vai trò hệ thống',
        ],
        'tech_support' => [
            'title' => 'Sửa quyền: Tech Support',
            'description' => 'Xem lỗi hệ thống, log kỹ thuật, hỗ trợ khôi phục; thao tác dữ liệu phải có ticket nội bộ.',
            'scope' => 'Không tự sửa dữ liệu kinh doanh',
            'badge' => 'Vai trò hệ thống',
        ],
    ];

    private const PERMISSION_SEED = [
        ['code' => 'INTERNAL_ACCOUNT_MANAGE', 'name' => 'Quản lý tài khoản nội bộ', 'group' => 'internal', 'group_label' => 'Quản lý nội bộ', 'group_icon' => 'address-card'],
        ['code' => 'STORE_CREATE', 'name' => 'Tạo hồ sơ cửa hàng', 'group' => 'store', 'group_label' => 'Cửa hàng', 'group_icon' => 'store'],
        ['code' => 'STORE_APPROVE', 'name' => 'Duyệt cửa hàng', 'group' => 'store', 'group_label' => 'Cửa hàng', 'group_icon' => 'store'],
        ['code' => 'PRODUCT_CREATE', 'name' => 'Tạo sản phẩm', 'group' => 'product', 'group_label' => 'Sản phẩm', 'group_icon' => 'box'],
        ['code' => 'PRODUCT_APPROVE', 'name' => 'Duyệt sản phẩm', 'group' => 'product', 'group_label' => 'Sản phẩm', 'group_icon' => 'box'],
        ['code' => 'AFFILIATE_COMMISSION_CONFIG', 'name' => 'Cài hoa hồng Affiliate', 'group' => 'affiliate', 'group_label' => 'Affiliate', 'group_icon' => 'handshake'],
        ['code' => 'VIEW_ORDER', 'name' => 'Xem đơn hàng', 'group' => 'orders', 'group_label' => 'Đơn hàng', 'group_icon' => 'bag-shopping'],
        ['code' => 'UPDATE_ORDER_STATUS', 'name' => 'Sửa trạng thái đơn', 'group' => 'orders', 'group_label' => 'Đơn hàng', 'group_icon' => 'bag-shopping'],
        ['code' => 'CANCEL_ORDER', 'name' => 'Hủy đơn hàng', 'group' => 'orders', 'group_label' => 'Đơn hàng', 'group_icon' => 'bag-shopping'],
        ['code' => 'REFUND_ORDER', 'name' => 'Hoàn đơn hàng', 'group' => 'orders', 'group_label' => 'Đơn hàng', 'group_icon' => 'bag-shopping'],
        ['code' => 'VIEW_TICKET', 'name' => 'Tra cứu Lucky Ticket', 'group' => 'ticket', 'group_label' => 'Lucky Ticket', 'group_icon' => 'ticket'],
        ['code' => 'FREEZE_TICKET', 'name' => 'Đóng băng ticket', 'group' => 'ticket', 'group_label' => 'Lucky Ticket', 'group_icon' => 'ticket'],
        ['code' => 'CANCEL_TICKET', 'name' => 'Hủy ticket', 'group' => 'ticket', 'group_label' => 'Lucky Ticket', 'group_icon' => 'ticket'],
        ['code' => 'VIEW_TICKET_HISTORY', 'name' => 'Xem lịch sử chuyển ticket', 'group' => 'ticket', 'group_label' => 'Lucky Ticket', 'group_icon' => 'ticket'],
        ['code' => 'REWARD_DRAFT_CREATE', 'name' => 'Tạo phòng thưởng nháp', 'group' => 'reward', 'group_label' => 'Phòng thưởng', 'group_icon' => 'gamepad'],
        ['code' => 'REWARD_ACTIVATE', 'name' => 'Kích hoạt phòng thưởng', 'group' => 'reward', 'group_label' => 'Phòng thưởng', 'group_icon' => 'gamepad'],
        ['code' => 'REWARD_LOCK', 'name' => 'Khóa phòng thưởng', 'group' => 'reward', 'group_label' => 'Phòng thưởng', 'group_icon' => 'gamepad'],
        ['code' => 'REWARD_PUBLISH_RESULT', 'name' => 'Công bố kết quả', 'group' => 'reward', 'group_label' => 'Phòng thưởng', 'group_icon' => 'gamepad'],
        ['code' => 'RECONCILIATION_CREATE', 'name' => 'Lập đối soát', 'group' => 'finance', 'group_label' => 'Tài chính', 'group_icon' => 'calculator'],
        ['code' => 'PAYMENT_APPROVE', 'name' => 'Duyệt thanh toán', 'group' => 'finance', 'group_label' => 'Tài chính', 'group_icon' => 'calculator'],
        ['code' => 'EXPORT_DATA', 'name' => 'Xuất dữ liệu', 'group' => 'finance', 'group_label' => 'Tài chính', 'group_icon' => 'calculator'],
        ['code' => 'EDIT_MENU', 'name' => 'Chỉnh sửa menu', 'group' => 'menu', 'group_label' => 'Quản lý menu', 'group_icon' => 'utensils'],
        ['code' => 'EDIT_PRICE', 'name' => 'Chỉnh sửa giá', 'group' => 'menu', 'group_label' => 'Quản lý menu', 'group_icon' => 'utensils'],
        ['code' => 'MANAGE_STAFF', 'name' => 'Quản lý nhân viên và phân quyền', 'group' => 'staff', 'group_label' => 'Nhân sự', 'group_icon' => 'users'],
        ['code' => 'VIEW_REPORT', 'name' => 'Xem báo cáo', 'group' => 'reports', 'group_label' => 'Báo cáo', 'group_icon' => 'chart-line'],
        ['code' => 'EDIT_PROFILE', 'name' => 'Chỉnh sửa hồ sơ cửa hàng', 'group' => 'profile', 'group_label' => 'Hồ sơ cửa hàng', 'group_icon' => 'store'],
    ];

    private const PERMISSION_GROUPS = [
        [
            'key' => 'internal',
            'label' => 'Quản lý nội bộ',
            'icon' => 'address-card',
            'codes' => ['INTERNAL_ACCOUNT_MANAGE'],
        ],
        [
            'key' => 'store',
            'label' => 'Cửa hàng',
            'icon' => 'store',
            'codes' => ['STORE_CREATE', 'STORE_APPROVE'],
        ],
        [
            'key' => 'product',
            'label' => 'Sản phẩm',
            'icon' => 'box',
            'codes' => ['PRODUCT_CREATE', 'PRODUCT_APPROVE'],
        ],
        [
            'key' => 'lucky_ticket',
            'label' => 'Lucky Ticket',
            'icon' => 'ticket',
            'codes' => ['LUCKY_TICKET_CONFIG', 'VIEW_TICKET', 'FREEZE_TICKET', 'CANCEL_TICKET', 'VIEW_TICKET_HISTORY'],
        ],
        [
            'key' => 'affiliate',
            'label' => 'Affiliate',
            'icon' => 'handshake',
            'codes' => ['AFFILIATE_COMMISSION_CONFIG'],
        ],
        [
            'key' => 'orders',
            'label' => 'Đơn hàng',
            'icon' => 'bag-shopping',
            'codes' => ['VIEW_ORDER', 'UPDATE_ORDER_STATUS', 'CANCEL_ORDER', 'REFUND_ORDER'],
        ],
        [
            'key' => 'reward',
            'label' => 'Phòng thưởng',
            'icon' => 'gamepad',
            'codes' => ['REWARD_DRAFT_CREATE', 'REWARD_ACTIVATE', 'REWARD_LOCK', 'REWARD_PUBLISH_RESULT'],
        ],
        [
            'key' => 'finance',
            'label' => 'Tài chính',
            'icon' => 'calculator',
            'codes' => ['RECONCILIATION_CREATE', 'PAYMENT_APPROVE', 'EXPORT_DATA'],
        ],
        [
            'key' => 'menu',
            'label' => 'Quản lý menu',
            'icon' => 'utensils',
            'codes' => ['EDIT_MENU', 'EDIT_PRICE'],
        ],
        [
            'key' => 'staff',
            'label' => 'Nhân sự',
            'icon' => 'users',
            'codes' => ['MANAGE_STAFF'],
        ],
        [
            'key' => 'reports',
            'label' => 'Báo cáo',
            'icon' => 'chart-line',
            'codes' => ['VIEW_REPORT'],
        ],
        [
            'key' => 'profile',
            'label' => 'Hồ sơ cửa hàng',
            'icon' => 'store',
            'codes' => ['EDIT_PROFILE'],
        ],
    ];

    public function edit(string $role): View
    {
        $roleMeta = $this->resolveRoleMeta($role);
        $this->seedPermissions();
        $roleRecord = Role::query()->firstOrCreate([
            'name' => $role,
        ], [
            'description' => $roleMeta['description'],
        ]);

        $permissionItems = Permission::query()
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn (Permission $permission) => [
                'code' => strtoupper((string) $permission->code),
                'name' => (string) $permission->name,
            ])
            ->values();

        $selectedCodes = $this->resolveSelectedCodes($role, $permissionItems->pluck('code')->all());
        $selectedCodes = session('role_permissions_preview.' . $role, $selectedCodes);

        $permissionGroups = collect(self::PERMISSION_GROUPS)
            ->map(function (array $group) use ($permissionItems) {
                $groupPermissions = $permissionItems
                    ->filter(fn (array $permission) => in_array($permission['code'], $group['codes'], true))
                    ->values()
                    ->map(fn (array $permission) => [
                        'code' => $permission['code'],
                        'name' => $permission['name'],
                    ])
                    ->all();

                return [
                    'key' => $group['key'],
                    'label' => $group['label'],
                    'icon' => $group['icon'],
                    'permissions' => $groupPermissions,
                ];
            })
            ->filter(fn (array $group) => !empty($group['permissions']))
            ->values()
            ->all();

        return view('admin.roles.edit', [
            'roleRecord' => $roleRecord,
            'roleMeta' => $roleMeta,
            'permissionGroups' => $permissionGroups,
            'selectedCodes' => $selectedCodes,
        ]);
    }

    public function update(Request $request, string $role): RedirectResponse
    {
        $roleMeta = $this->resolveRoleMeta($role);
        $this->seedPermissions();
        $roleRecord = Role::query()->firstOrCreate([
            'name' => $role,
        ], [
            'description' => $roleMeta['description'],
        ]);

        $validated = $request->validate([
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:50'],
        ]);

        $validCodes = Permission::query()->pluck('code')->map(fn ($code) => strtoupper((string) $code))->all();
        $selectedCodes = array_values(array_intersect(
            array_map(static fn ($code) => strtoupper(trim((string) $code)), (array) ($validated['permissions'] ?? [])),
            $validCodes,
        ));

        session()->put('role_permissions_preview.' . $roleRecord->name, $selectedCodes);

        return redirect()
            ->route('admin.roles.edit', ['role' => $roleRecord->name])
            ->with('success', 'Đã cập nhật phần chọn quyền cho vai trò ' . $roleRecord->name . '.');
    }

    private function resolveRoleMeta(string $role): array
    {
        return self::ROLE_META[$role] ?? [
            'title' => 'Sửa quyền: ' . str_replace('_', ' ', ucfirst($role)),
            'description' => 'Quản lý danh sách quyền cho vai trò này.',
            'scope' => 'Chưa xác định',
            'badge' => 'Vai trò tùy chỉnh',
        ];
    }

    private function resolveSelectedCodes(string $role, array $availableCodes): array
    {
        $availableLookup = array_fill_keys($availableCodes, true);

        $defaultCodes = match ($role) {
            'super_admin' => $availableCodes,
            'ops_lead' => ['INTERNAL_ACCOUNT_MANAGE', 'STORE_APPROVE', 'PRODUCT_APPROVE', 'VIEW_ORDER', 'UPDATE_ORDER_STATUS', 'VIEW_TICKET_HISTORY', 'REWARD_PUBLISH_RESULT', 'RECONCILIATION_CREATE', 'PAYMENT_APPROVE', 'EXPORT_DATA'],
            'store_sales_bd' => ['STORE_CREATE', 'PRODUCT_CREATE', 'VIEW_ORDER', 'VIEW_TICKET'],
            'merchant_operation' => ['STORE_APPROVE', 'PRODUCT_APPROVE', 'VIEW_ORDER', 'UPDATE_ORDER_STATUS', 'VIEW_TICKET', 'FREEZE_TICKET'],
            'factory_product_bd' => ['STORE_CREATE', 'PRODUCT_CREATE', 'LUCKY_TICKET_CONFIG', 'AFFILIATE_COMMISSION_CONFIG'],
            'product_operation' => ['PRODUCT_CREATE', 'PRODUCT_APPROVE', 'VIEW_REPORT'],
            'ticket_operation' => ['VIEW_TICKET', 'FREEZE_TICKET', 'CANCEL_TICKET', 'VIEW_TICKET_HISTORY'],
            'reward_room_operation' => ['REWARD_DRAFT_CREATE', 'REWARD_ACTIVATE', 'REWARD_LOCK', 'REWARD_PUBLISH_RESULT'],
            'affiliate_operation' => ['AFFILIATE_COMMISSION_CONFIG', 'VIEW_REPORT'],
            'finance_accounting' => ['RECONCILIATION_CREATE', 'PAYMENT_APPROVE', 'EXPORT_DATA', 'VIEW_REPORT'],
            'customer_support' => ['VIEW_ORDER', 'VIEW_TICKET', 'VIEW_TICKET_HISTORY'],
            'risk_control_fraud' => ['VIEW_ORDER', 'FREEZE_TICKET', 'CANCEL_TICKET', 'VIEW_TICKET_HISTORY'],
            'marketing_content' => ['VIEW_REPORT', 'AFFILIATE_COMMISSION_CONFIG'],
            'tech_support' => ['VIEW_REPORT', 'EXPORT_DATA'],
            default => [],
        };

        return array_values(array_filter($defaultCodes, static fn (string $code) => isset($availableLookup[$code])));
    }

    private function seedPermissions(): void
    {
        foreach (self::PERMISSION_SEED as $permission) {
            Permission::query()->updateOrCreate(
                ['code' => $permission['code']],
                ['name' => $permission['name']]
            );
        }
    }
}