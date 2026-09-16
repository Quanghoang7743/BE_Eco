<?php

namespace App\Http\Controllers;

use App\Models\AffiliateWithdrawalRequest;
use App\Models\User;
use App\Models\WithdrawRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

class AdminAffiliateWithdrawalController extends Controller
{
    public function index(Request $request): View
    {
        $this->ensureAdmin();

        return view('admin.affiliate.withdrawals');
    }

    public function datatable(Request $request)
    {
        $this->ensureAdmin();

        $query = WithdrawRequest::query()
            ->leftJoin('users', 'withdraw_requests.user_id', '=', 'users.id')
            ->leftJoin('bank_accounts', 'withdraw_requests.user_id', '=', 'bank_accounts.user_id')
            ->leftJoin('banks', 'bank_accounts.bank_id', '=', 'banks.id')
            ->whereHas('user.affiliateProfile')
            ->select('withdraw_requests.*');

        $recordsTotal = $query->count();

        if ($status = $request->input('status', WithdrawRequest::STATUS_PENDING)) {
            if (in_array($status, $this->withdrawStatuses(), true)) {
                $query->where('withdraw_requests.status', $status);
            }
        }

        if ($search = $request->input('search.value')) {
            $query->where(function ($query) use ($search) {
                $query->where('withdraw_requests.id', 'like', "%{$search}%")
                    ->orWhere('users.name', 'like', "%{$search}%")
                    ->orWhere('withdraw_requests.status', 'like', "%{$search}%")
                    ->orWhere('bank_accounts.account_name', 'like', "%{$search}%")
                    ->orWhere('bank_accounts.account_number', 'like', "%{$search}%")
                    ->orWhere('banks.name', 'like', "%{$search}%");
            });
        }

        $recordsFiltered = $query->count();

        $columns = [
            0 => 'withdraw_requests.id',
            1 => 'users.name',
            2 => 'withdraw_requests.amount',
            3 => 'withdraw_requests.status',
            4 => 'bank_accounts.account_name',
            5 => 'bank_accounts.account_number',
            6 => 'banks.name',
            7 => 'withdraw_requests.created_at',
        ];

        $orderField = $columns[$request->input('order.0.column', 0)] ?? 'withdraw_requests.created_at';
        $orderDir = $request->input('order.0.dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($orderField, $orderDir);

        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 15);

        $withdrawRequests = $query->skip($start)->take($length)->get();
        $withdrawRequests->load(['user', 'bankAccount.bank']);

        $data = $withdrawRequests->map(function (WithdrawRequest $requestItem) {
            $bankAccount = $requestItem->bankAccount;
            $bankName = $bankAccount->bank->name ?? $bankAccount->bank_name ?? 'Chưa có';

            return [
                'id' => $requestItem->id,
                'user' => $requestItem->user->name ?? 'Không có',
                'amount' => number_format($requestItem->amount, 0, ',', '.') . ' VND',
                'status' => $this->renderWithdrawStatusBadge((string) $requestItem->status),
                'account_name' => $bankAccount->account_name ?? 'Chưa có',
                'account_number' => $bankAccount->account_number ?? 'Chưa có',
                'bank_name' => $bankName,
                'created_at' => $requestItem->created_at->format('d/m/Y H:i'),
                'action' => '<a href="' . route('admin.bank-accounts.edit', [$requestItem->id, 'redirect_to' => 'affiliate']) . '" class="btn btn-sm btn-primary">Cập nhật</a>',
            ];
        });

        return response()->json([
            'draw' => (int) $request->input('draw', 1),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    private function withdrawStatuses(): array
    {
        return [
            WithdrawRequest::STATUS_PENDING,
            WithdrawRequest::STATUS_APPROVED,
            WithdrawRequest::STATUS_PAID,
            WithdrawRequest::STATUS_REJECTED,
        ];
    }

    private function renderWithdrawStatusBadge(string $status): string
    {
        return match ($status) {
            WithdrawRequest::STATUS_PENDING => '<span class="badge bg-warning text-dark">Chờ duyệt</span>',
            WithdrawRequest::STATUS_APPROVED => '<span class="badge bg-primary">Đã duyệt</span>',
            WithdrawRequest::STATUS_PAID => '<span class="badge bg-success">Đã thanh toán</span>',
            WithdrawRequest::STATUS_REJECTED => '<span class="badge bg-danger">Từ chối</span>',
            default => '<span class="badge bg-secondary">' . e($status) . '</span>',
        };
    }

    public function approve(int $withdrawalId, Request $request)
    {
        $this->ensureAdmin();

        $withdrawal = AffiliateWithdrawalRequest::query()->findOrFail($withdrawalId);

        DB::transaction(function () use ($withdrawal) {
            $withdrawal->status = 'approved';
            $withdrawal->approved_by = auth()->id();
            $withdrawal->approved_at = now();
            $withdrawal->save();

            // Create a database notification for the user (use custom notifications table schema)
            try {
                DB::table('notifications')->insert([
                    'message' => 'Ch\u00fac m\u1eebng anh/ch\u1ec9 \u0111\u00e3 ch\u00ednh th\u1ee9c \u0111\u01b0\u1ee3c ki\u1ebfm ti\u1ec1n t\u1eeb n\u1ec1n t\u1ea3ng Ecoluck.',
                    'title' => 'Y\u00eau c\u1ea7u r\u00fat ti\u1ec1n \u0111\u00e3 \u0111\u01b0\u1ee3c duy\u1ec7t',
                    'body' => 'Y\u00eau c\u1ea7u r\u00fat ti\u1ec1n affiliate của bạn đã được admin duy\u1ec7t.',
                    'type' => 'affiliate_withdrawal_approved',
                    'action_url' => '/affiliate/wallet',
                    'is_read' => false,
                    'read_at' => null,
                    'audience_scope' => 'user_private',
                    'user_id' => (string) $withdrawal->user_id,
                    'store_id' => null,
                    'order_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Exception $e) {
                // ignore notification failures
            }
        });

        return back()->with('success', 'Đã duyệt rút tiền affiliate.');
    }

    public function toggleStatus(int $withdrawalId, Request $request)
    {
        $this->ensureAdmin();

        $withdrawal = AffiliateWithdrawalRequest::query()->findOrFail($withdrawalId);

        DB::transaction(function () use ($withdrawal) {
            $isApproved = $withdrawal->status === 'approved' || !empty($withdrawal->approved_at);

            if ($isApproved) {
                $withdrawal->status = 'pending';
                $withdrawal->approved_by = null;
                $withdrawal->approved_at = null;
            } else {
                $withdrawal->status = 'approved';
                $withdrawal->approved_by = auth()->id();
                $withdrawal->approved_at = now();
            }

            $withdrawal->save();
        });

        return back()->with('success', 'Đã cập nhật trạng thái rút tiền affiliate.');
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();

        abort_unless($user && in_array((string) $user->role, ['admin', 'super_admin'], true), 403, 'Chỉ super admin mới được phép thực hiện thao tác này.');
    }
}
