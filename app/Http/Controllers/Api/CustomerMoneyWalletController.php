<?php

namespace App\Http\Controllers\Api;

use App\Models\MoneyWalletLedgerEntry;
use App\Services\MoneyWalletService;
use App\Services\OrderReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class CustomerMoneyWalletController extends ClientController
{
    public function __construct(
        private readonly MoneyWalletService $walletService,
        private readonly OrderReturnService $returnService
    ) {
    }

    public function wallet(Request $request): JsonResponse
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Unauthorized', 401);
        }

        $account = $this->walletService->accountFor('user', (string) $user->id);

        return response()->json([
            'data' => [
                'available_balance' => (float) $account->available_balance,
                'locked_balance' => (float) $account->locked_balance,
                'currency' => $account->currency,
            ],
            'errors' => [],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function ledger(Request $request): JsonResponse
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Unauthorized', 401);
        }

        $account = $this->walletService->accountFor('user', (string) $user->id);
        $pageSize = min(100, max(1, (int) $request->query('page_size', 20)));
        $rows = MoneyWalletLedgerEntry::query()
            ->where('wallet_account_id', (string) $account->id)
            ->orderByDesc('created_at')
            ->paginate($pageSize);

        return response()->json([
            'data' => collect($rows->items())
                ->map(fn (MoneyWalletLedgerEntry $entry): array => $this->serializeLedgerEntry($entry))
                ->all(),
            'meta' => [
                'page' => $rows->currentPage(),
                'page_size' => $rows->perPage(),
                'total' => $rows->total(),
            ],
            'errors' => [],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function withdraw(Request $request): JsonResponse
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Unauthorized', 401);
        }

        $input = $request->all();
        $headerKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');
        if (! array_key_exists('idempotency_key', $input) && is_string($headerKey) && trim($headerKey) !== '') {
            $input['idempotency_key'] = $headerKey;
        }

        $validator = Validator::make($input, [
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999999.99'],
            'bank_id' => ['nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._-]+\z/'],
            'bank_code' => ['nullable', 'string', 'max:30', 'regex:/\A[A-Za-z0-9._-]+\z/'],
            'bank_name' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'account_number' => ['required', 'string', 'max:50', 'regex:/\A[A-Za-z0-9._-]+\z/'],
            'account_name' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'idempotency_key' => ['nullable', 'string', 'max:160', 'regex:/\A[A-Za-z0-9:._-]+\z/'],
        ]);
        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Invalid request data.', 422, $validator->errors()->toArray());
        }

        try {
            $withdrawal = $this->returnService->createWithdrawal($user, $validator->validated());

            return response()->json(['data' => $this->serializeWithdrawal($withdrawal), 'errors' => []], 201, [], JSON_UNESCAPED_UNICODE);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getMessage(), 422);
        }
    }

    private function serializeLedgerEntry(MoneyWalletLedgerEntry $entry): array
    {
        return [
            'id' => (string) $entry->id,
            'direction' => $entry->direction,
            'amount' => (float) $entry->amount,
            'balance_before' => (float) $entry->balance_before,
            'balance_after' => (float) $entry->balance_after,
            'entry_type' => $entry->entry_type,
            'source_type' => $entry->source_type,
            'metadata' => $entry->metadata,
            'created_at' => optional($entry->created_at)?->toIso8601String(),
        ];
    }

    private function serializeWithdrawal(object $withdrawal): array
    {
        return [
            'id' => (string) $withdrawal->id,
            'amount' => (float) $withdrawal->amount,
            'status' => $withdrawal->status,
            'bank_code' => $withdrawal->bank_code,
            'bank_name' => $withdrawal->bank_name,
            'account_number' => $withdrawal->account_number,
            'account_name' => $withdrawal->account_name,
            'created_at' => optional($withdrawal->created_at)?->toIso8601String(),
            'updated_at' => optional($withdrawal->updated_at)?->toIso8601String(),
        ];
    }

    private function error(string $code, string $message, int $status, ?array $details = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json(['data' => null, 'errors' => [$error]], $status, [], JSON_UNESCAPED_UNICODE);
    }
}
