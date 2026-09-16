<?php

namespace App\Http\Controllers;

use App\Models\AffiliateWalletLog;
use App\Models\User;
use App\Models\WithdrawRequest;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class BankAccountController extends Controller
{
    private NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function index(Request $request)
    {
        $withdrawRequests = WithdrawRequest::whereDoesntHave('user.affiliateProfile')
            ->with(['user', 'bankAccount.bank'])
            ->orderByDesc('created_at')
            ->paginate(15);
        return view('admin.account_payment.index', compact('withdrawRequests'));
    }

    public function datatable(Request $request)
    {
        $query = WithdrawRequest::query()
            ->leftJoin('users', 'withdraw_requests.user_id', '=', 'users.id')
            ->leftJoin('bank_accounts', 'withdraw_requests.user_id', '=', 'bank_accounts.user_id')
            ->leftJoin('banks', 'bank_accounts.bank_id', '=', 'banks.id')
            ->whereDoesntHave('user.affiliateProfile')
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
                'action' => '<a href="' . route('admin.bank-accounts.edit', $requestItem->id) . '" class="btn btn-sm btn-primary">Cập nhật</a>',
            ];
        });

        return response()->json([
            'draw' => (int) $request->input('draw', 1),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    public function edit($id)
    {
        $withdrawRequest = WithdrawRequest::findOrFail($id);
        return view('admin.account_payment.edit', compact('withdrawRequest'));
    }

    public function update(Request $request, $id)
    {
        $withdrawRequest = WithdrawRequest::findOrFail($id);
        $request->validate([
            'status' => 'required|in:pending,approved,paid,rejected',
            'rejection_reason' => 'nullable|required_if:status,rejected|string|max:1000',
            'image' => 'nullable|image|max:2048'
        ]);

        $newStatus = $request->status;
        $oldStatus = $withdrawRequest->status;

        if (! $this->canTransitionWithdrawStatus($oldStatus, $newStatus)) {
            return redirect()->back()->with('error', 'Khong the thay doi trang thai yeu cau rut tien da ket thuc.');
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('images', 'public');
        }

        try {
            $effectiveOldStatus = $oldStatus;
            $withdrawRequest = DB::transaction(function () use ($id, $newStatus, $request, $imagePath, &$effectiveOldStatus) {
                $lockedWithdrawRequest = WithdrawRequest::query()
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $effectiveOldStatus = (string) $lockedWithdrawRequest->status;

                if (! $this->canTransitionWithdrawStatus($effectiveOldStatus, $newStatus)) {
                    throw new RuntimeException('Invalid withdraw status transition.');
                }

                if ($imagePath !== null) {
                    $lockedWithdrawRequest->image = $imagePath;
                }

                if ($effectiveOldStatus !== $newStatus && in_array($effectiveOldStatus, WithdrawRequest::ACTIVE_STATUSES, true) && $newStatus === WithdrawRequest::STATUS_REJECTED) {
                    $lockedUser = User::query()->lockForUpdate()->find($lockedWithdrawRequest->user_id);
                    if (! $lockedUser) {
                        throw new RuntimeException('Withdraw request user not found.');
                    }

                    $balanceBefore = (int) $lockedUser->balance;
                    $balanceAfter = $balanceBefore + (int) $lockedWithdrawRequest->amount;
                    $lockedUser->balance = $balanceAfter;
                    $lockedUser->save();

                    AffiliateWalletLog::create([
                        'user_id' => $lockedWithdrawRequest->user_id,
                        'action_type' => 'affiliate_withdraw_refund',
                        'amount_change' => (int) $lockedWithdrawRequest->amount,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceAfter,
                        'reference_id' => $lockedWithdrawRequest->id,
                        'reference_type' => WithdrawRequest::class,
                        'metadata' => [
                            'note' => 'Hoàn tiền yêu cầu rút tiền bị từ chối',
                            'source_type' => 'manual_withdraw_refund',
                            'source_label' => 'Hoàn tiền yêu cầu rút thủ công',
                            'rejection_reason' => $request->input('rejection_reason'),
                        ],
                        'created_at' => now(),
                    ]);
                }

                $lockedWithdrawRequest->status = $newStatus;
                $lockedWithdrawRequest->rejection_reason = $newStatus === WithdrawRequest::STATUS_REJECTED ? $request->input('rejection_reason') : null;

                if ($effectiveOldStatus !== $newStatus && $newStatus === WithdrawRequest::STATUS_APPROVED) {
                    $lockedWithdrawRequest->approved_by = auth()->id();
                    $lockedWithdrawRequest->approved_at = now();
                }

                if ($effectiveOldStatus !== $newStatus && $newStatus === WithdrawRequest::STATUS_PAID) {
                    if (empty($lockedWithdrawRequest->approved_at)) {
                        $lockedWithdrawRequest->approved_by = auth()->id();
                        $lockedWithdrawRequest->approved_at = now();
                    }
                    $lockedWithdrawRequest->paid_by = auth()->id();
                    $lockedWithdrawRequest->paid_at = now();
                }

                $lockedWithdrawRequest->save();

                return $lockedWithdrawRequest;
            });
        } catch (Throwable $e) {
            Log::error('BankAccountController@update failed', [
                'withdraw_request_id' => $withdrawRequest->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->back()->with('error', 'Cập nhật yêu cầu rút tiền thất bại. Vui lòng thử lại.');
        }

        if ($effectiveOldStatus !== $newStatus) {
            $this->notifyWithdrawRequestUser($withdrawRequest, $newStatus, $request->input('rejection_reason'));
        }

        if ($request->input('redirect_to') === 'affiliate') {
            return redirect()->route('admin.affiliate.withdrawals.index')->with('success', 'Cập nhật thành công');
        }

        return redirect()->route('admin.bank-accounts.index')->with('success', 'Cập nhật thành công');
    }

    private function canTransitionWithdrawStatus(?string $oldStatus, string $newStatus): bool
    {
        $oldStatus = strtolower(trim((string) $oldStatus));
        $newStatus = strtolower(trim($newStatus));

        if ($oldStatus === $newStatus) {
            return true;
        }

        if ($oldStatus === WithdrawRequest::STATUS_PENDING) {
            return in_array($newStatus, [WithdrawRequest::STATUS_APPROVED, WithdrawRequest::STATUS_PAID, WithdrawRequest::STATUS_REJECTED], true);
        }

        if ($oldStatus === WithdrawRequest::STATUS_APPROVED) {
            return in_array($newStatus, [WithdrawRequest::STATUS_PAID, WithdrawRequest::STATUS_REJECTED], true);
        }

        return false;
    }

    private function notifyWithdrawRequestUser(WithdrawRequest $withdrawRequest, string $status, ?string $reason): void
    {
        $userId = (string) $withdrawRequest->user_id;
        if ($userId === '') {
            return;
        }

        $title = match ($status) {
            WithdrawRequest::STATUS_APPROVED => 'Yêu cầu rút tiền đã được duyệt',
            WithdrawRequest::STATUS_PAID => 'Yêu cầu rút tiền đã được thanh toán',
            default => 'Yêu cầu rút tiền bị từ chối',
        };

        $body = match ($status) {
            WithdrawRequest::STATUS_APPROVED => 'Yêu cầu rút tiền của bạn đã được duyệt và đang chờ chuyển khoản.',
            WithdrawRequest::STATUS_PAID => 'Yêu cầu rút tiền của bạn đã được thanh toán.',
            default => 'Yêu cầu rút tiền của bạn đã bị từ chối.',
        };

        if ($status === WithdrawRequest::STATUS_REJECTED && $reason) {
            $body .= ' Lý do: ' . $reason;
        }

        if ($status === WithdrawRequest::STATUS_PAID && $withdrawRequest->image) {
            $body .= ' Bill/Chứng từ đã được cập nhật.';
        }

        $this->notificationService->create([
            'audience_scope' => 'user_private',
            'user_id' => $userId,
            'type' => 'system',
            'title' => $title,
            'body' => $body,
            'message' => $body,
            'action_url' => null,
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
}
