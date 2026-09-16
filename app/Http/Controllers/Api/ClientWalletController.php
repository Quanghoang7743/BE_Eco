<?php

namespace App\Http\Controllers\Api;

use App\Events\AffiliateWalletUpdated;
use App\Http\Requests\AffiliateWithdrawRequest;
use App\Http\Requests\BankAccountRequest;
use App\Models\AffiliateWalletLog;
use App\Models\WithdrawRequest;
use App\Models\User;
use App\Services\AffiliateCommissionService;
use App\Services\BankAccountService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class ClientWalletController extends ClientController
{
    private BankAccountService $bankAccountService;

    private AffiliateCommissionService $affiliateCommissionService;

    public function __construct(BankAccountService $bankAccountService, AffiliateCommissionService $affiliateCommissionService)
    {
        $this->bankAccountService = $bankAccountService;
        $this->affiliateCommissionService = $affiliateCommissionService;
    }

    public function bankAccounts(Request $request)
    {
        $user = $request->user();
        $account = $this->bankAccountService->getByUser($user);

        return response()->json($account ? [$account] : []);
    }

    public function createOrUpdate(BankAccountRequest $request)
    {
        $user = $request->user();
        $payload = $request->validated();

        // allow optional is_default flag from client
        if (($request->has('is_default') && $request->input('is_default')) || ! $this->bankAccountService->getByUser($user)) {
            $payload['is_default'] = true;
        }

        $account = $this->bankAccountService->createOrUpdateForUser($user, $payload);

        return response()->json([
            'message' => 'Cập nhật tài khoản thành công',
            'data' => $account,
        ]);
    }

    public function affiliateWithdraw(AffiliateWithdrawRequest $request)
    {
        $user = $request->user();
        $bankAccount = $this->bankAccountService->getByUser($user);

        if (! $bankAccount) {
            return response()->json([
                'message' => 'Vui lòng thiết lập tài khoản ngân hàng trước khi yêu cầu rút tiền.',
            ], 422);
        }

        $validated = $request->validated();

        try {
            [$withdrawRequest, $walletLog] = DB::transaction(function () use ($user, $validated) {
                $lockedUser = User::query()->lockForUpdate()->find($user->id);
                $availableAmount = (int) $lockedUser->balance;

                $activeWithdrawRequest = WithdrawRequest::query()
                    ->where('user_id', $lockedUser->id)
                    ->whereIn('status', WithdrawRequest::ACTIVE_STATUSES)
                    ->lockForUpdate()
                    ->first();

                if ($activeWithdrawRequest) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Bạn đang có yêu cầu rút tiền chờ xử lý. Vui lòng chờ admin xử lý trước khi tạo yêu cầu mới.',
                        'active_request_id' => $activeWithdrawRequest->id,
                        'active_request_status' => $activeWithdrawRequest->status,
                    ], 409));
                }

                if ($validated['amount'] > $availableAmount) {
                    throw new ValidationException(Validator::make([], []), response()->json([
                        'message' => 'Số dư không đủ để thực hiện yêu cầu rút tiền.',
                        'available_amount' => $availableAmount,
                    ], 422));
                }

                $balanceBefore = $availableAmount;
                $balanceAfter = $balanceBefore - $validated['amount'];
                $lockedUser->balance = $balanceAfter;
                $lockedUser->save();

                $request = WithdrawRequest::create([
                    'user_id' => $lockedUser->id,
                    'amount' => $validated['amount'],
                    'note' => $validated['note'] ?? null,
                    'status' => WithdrawRequest::STATUS_PENDING,
                ]);

                $walletLog = AffiliateWalletLog::create([
                    'user_id' => $lockedUser->id,
                    'action_type' => 'affiliate_withdraw_request',
                    'amount_change' => -abs($validated['amount']),
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'reference_id' => $request->id,
                    'reference_type' => WithdrawRequest::class,
                    'metadata' => [
                        'note' => $validated['note'] ?? null,
                        'source_type' => 'manual_withdraw_request',
                        'source_label' => 'Yêu cầu rút thủ công',
                    ],
                    'created_at' => now(),
                ]);

                return [$request, $walletLog];
            });

            event(AffiliateWalletUpdated::fromLog(
                (int) $user->id,
                'affiliate_withdraw_request',
                $this->affiliateCommissionService->getDashboardSummary((int) $user->id),
                $walletLog,
            ));
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('affiliateWithdraw failed', [
                'user_id' => $user->id,
                'amount' => $validated['amount'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Đã có lỗi xảy ra khi tạo yêu cầu rút tiền. Vui lòng thử lại sau.',
            ], 500);
        }

        return response()->json([
            'message' => 'Yêu cầu rút hoa hồng đã được gửi.',
            'data' => $withdrawRequest,
        ], 201);
    }

    public function walletLogs(Request $request)
    {
        $user = $request->user();

        $logs = AffiliateWalletLog::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(fn (AffiliateWalletLog $log): array => $this->formatWalletLog($log));

        return response()->json($logs);
    }

    public function withdrawRequests(Request $request)
    {
        $user = $request->user();

        $withdrawRequests = WithdrawRequest::query()
            ->where('user_id', $user->id)
            ->with(['bankAccount.bank'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($withdrawRequests);
    }

    private function formatWalletLog(AffiliateWalletLog $log): array
    {
        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $sourceType = (string) ($metadata['source_type'] ?? $this->walletSourceTypeFromAction((string) $log->action_type));

        return [
            'id' => (string) $log->id,
            'user_id' => (int) $log->user_id,
            'action_type' => (string) $log->action_type,
            'amount_change' => (float) $log->amount_change,
            'balance_before' => (float) $log->balance_before,
            'balance_after' => (float) $log->balance_after,
            'reference_id' => $log->reference_id,
            'reference_type' => $log->reference_type,
            'source_type' => $sourceType,
            'source_id' => $metadata['order_id']
                ?? $metadata['order_item_id']
                ?? $metadata['source_assignment_id']
                ?? $log->reference_id,
            'source_label' => (string) ($metadata['source_label'] ?? $this->walletSourceLabel($sourceType)),
            'metadata' => $metadata ?: null,
            'created_at' => optional($log->created_at)->toIso8601String(),
        ];
    }

    private function walletSourceTypeFromAction(string $actionType): string
    {
        return match ($actionType) {
            'affiliate_withdraw_request' => 'manual_withdraw_request',
            'affiliate_commission_credit' => 'affiliate_commission',
            default => 'affiliate_wallet',
        };
    }

    private function walletSourceLabel(string $sourceType): string
    {
        return match ($sourceType) {
            'product_link' => 'Link sản phẩm Affiliate',
            'customer_upline' => 'Khách hàng tuyến dưới',
            'order_referrer' => 'Đơn hàng giới thiệu',
            'manual_withdraw_request' => 'Yêu cầu rút thủ công',
            'affiliate_commission' => 'Hoa hồng Affiliate',
            default => 'Ví Affiliate',
        };
    }
}
