<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Imports\StoreAdminOrderImport;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class StoreAdminOrderImportController extends Controller
{
    public function import(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('orders')) {
            return $this->errorResponse('ORDER_NOT_SUPPORTED', 'He thong chua ho tro nhap don hang tren moi truong hien tai.', 422, true, true);
        }

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,xlsx,xls', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $file = $request->file('file');
        if (!$file) {
            return $this->errorResponse('FILE_NOT_FOUND', 'Khong tim thay file import.', 422, true, true);
        }

        $sheets = Excel::toArray(new StoreAdminOrderImport(), $file);
        $rows = $sheets[0] ?? [];

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $processed++;
            $rowNumber = $index + 2;

            $orderId = trim((string) ($row['order_id'] ?? ''));
            $orderCode = trim((string) ($row['order_code'] ?? ''));
            $status = mb_strtolower(trim((string) ($row['status'] ?? '')));
            $reason = trim((string) ($row['reason'] ?? $row['note'] ?? ''));

            if ($status === '') {
                $skipped++;
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => 'Thieu trang thai don hang.',
                ];
                continue;
            }

            if ($orderId === '' && $orderCode === '') {
                $skipped++;
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => 'Thieu order_id hoac order_code.',
                ];
                continue;
            }

            $orderQuery = DB::table('orders')->where('store_id', $store->id);
            if ($orderId !== '') {
                $orderQuery->where('id', $orderId);
            } elseif (Schema::hasColumn('orders', 'order_code')) {
                $orderQuery->where('order_code', $orderCode);
            } else {
                $skipped++;
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => 'Khong co cot order_code de tim don hang.',
                ];
                continue;
            }

            $order = $orderQuery->first();
            if (!$order) {
                $failed++;
                $errors[] = [
                    'row' => $rowNumber,
                    'order_id' => $orderId !== '' ? $orderId : null,
                    'order_code' => $orderCode !== '' ? $orderCode : null,
                    'message' => 'Khong tim thay don hang thuoc cua hang hien tai.',
                ];
                continue;
            }

            $currentStatus = mb_strtolower(trim((string) ($order->status ?? '')));
            if ($currentStatus === $status) {
                $skipped++;
                continue;
            }

            try {
                DB::beginTransaction();

                $updatePayload = ['status' => $status];
                if (Schema::hasColumn('orders', 'updated_at')) {
                    $updatePayload['updated_at'] = now();
                }

                DB::table('orders')
                    ->where('id', $order->id)
                    ->where('store_id', $store->id)
                    ->update($updatePayload);

                if (Schema::hasTable('order_status_history')) {
                    $historyPayload = [];
                    if (Schema::hasColumn('order_status_history', 'id')) {
                        $historyPayload['id'] = (string) Str::uuid();
                    }
                    if (Schema::hasColumn('order_status_history', 'order_id')) {
                        $historyPayload['order_id'] = $order->id;
                    }
                    if (Schema::hasColumn('order_status_history', 'old_status')) {
                        $historyPayload['old_status'] = $order->status;
                    }
                    if (Schema::hasColumn('order_status_history', 'new_status')) {
                        $historyPayload['new_status'] = $status;
                    }
                    if (Schema::hasColumn('order_status_history', 'changed_by')) {
                        $historyPayload['changed_by'] = $user->id;
                    }
                    if (Schema::hasColumn('order_status_history', 'changed_by_role')) {
                        $historyPayload['changed_by_role'] = $user->role ?? 'store_admin';
                    }
                    if (Schema::hasColumn('order_status_history', 'reason')) {
                        $historyPayload['reason'] = $reason !== '' ? $reason : 'store_admin_import';
                    }
                    if (Schema::hasColumn('order_status_history', 'created_at')) {
                        $historyPayload['created_at'] = now();
                    }
                    if (Schema::hasColumn('order_status_history', 'updated_at')) {
                        $historyPayload['updated_at'] = now();
                    }

                    if (!empty($historyPayload)) {
                        DB::table('order_status_history')->insert($historyPayload);
                    }
                }

                DB::commit();
                $updated++;
            } catch (\Throwable $e) {
                DB::rollBack();
                $failed++;
                $errors[] = [
                    'row' => $rowNumber,
                    'order_id' => $order->id ?? null,
                    'order_code' => $order->order_code ?? null,
                    'message' => 'Khong the cap nhat don hang o dong nay.',
                ];
            }
        }

        return response()->json([
            'data' => [
                'total_rows' => $processed,
                'updated' => $updated,
                'skipped' => $skipped,
                'failed' => $failed,
                'errors' => $errors,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    private function resolveOwnedStoreContext(Request $request): array
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return [null, null, $context['error']];
        }

        if (!$context['store']) {
            return [
                null,
                $context['user'],
                $this->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang thuoc quyen quan ly cua tai khoan nay.', 404, false, true),
            ];
        }

        return [$context['store'], $context['user'], null];
    }

    private function resolveContext(Request $request): array
    {
        return $this->resolveStoreAdminAccessContext($request);
    }

    private function errorResponse(string $code, string $message, int $status, bool $hasStore, bool $authenticated, ?array $details = null): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json([
            'data' => null,
            'meta' => [
                'authenticated' => $authenticated,
                'has_store' => $hasStore,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [$error],
        ], $status);
    }
}
