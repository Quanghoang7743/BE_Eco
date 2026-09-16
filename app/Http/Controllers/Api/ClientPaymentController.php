<?php

namespace App\Http\Controllers\Api;

use App\Events\OrderPaymentSuccess;
use App\Events\NewOrderPlaced;
use App\Models\appSetting;
use App\Models\Order;
use App\Models\PreOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ClientPaymentController extends ClientController
{
    private function getVietQrConfig(): array
    {
        $settings = appSetting::first();

        return [
            'bank_id' => trim((string) ($settings?->vietqr_bank_id ?: env('VIETQR_BANK_ID', 'MB'))),
            'account_no' => trim((string) ($settings?->vietqr_account_no ?: env('VIETQR_ACCOUNT_NO'))),
            'account_name' => trim((string) ($settings?->vietqr_account_name ?: env('VIETQR_ACCOUNT_NAME'))),
        ];
    }

    private function getOrderPaymentState(Order $order): string
    {
        $hasStatusPayment = Schema::hasColumn($order->getTable(), 'status_payment');
        if ($hasStatusPayment && ! empty($order->status_payment)) {
            return (string) $order->status_payment;
        }

        $status = strtolower((string) ($order->status ?? 'pending'));
        if (in_array($status, ['paid', 'completed'], true)) {
            return 'paid';
        }

        return 'pending';
    }

    public function createVietQr(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required',
        ]);

        $order = Order::find($validated['order_id']);

        if (! $order) {
            $preOrder = PreOrder::where('id', $validated['order_id'])
                ->orWhere('pre_order_code', $validated['order_id'])
                ->first();

            if ($preOrder) {
                return $this->generateQrForPreOrder($preOrder);
            }

            return response()->json([
                'success' => false,
                'message' => 'Don hang khong ton tai.',
            ], 404);
        }

        if (empty($order->order_code)) {
            $order->order_code = $this->generateOrderCode();
            $order->save();
        }

        $amount = (int) round((float) ($order->final_amount ?? $order->total_amount ?? $order->total ?? 0));

        if ($amount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'So tien don hang khong hop le de tao QR.',
            ], 422);
        }

        $vietQrConfig = $this->getVietQrConfig();
        $bankId = $vietQrConfig['bank_id'];
        $accountNo = $vietQrConfig['account_no'];
        $accountName = $vietQrConfig['account_name'];

        if (empty($bankId) || empty($accountNo) || empty($accountName)) {
            return response()->json([
                'success' => false,
                'message' => 'Thieu cau hinh VietQR trong he thong hoac trong .env',
            ], 500);
        }

        $query = http_build_query([
            'amount' => $amount,
            'addInfo' => $order->order_code,
            'accountName' => $accountName,
            't' => time(), // Cache-busting parameter
        ]);

        $qrUrl = "https://img.vietqr.io/image/{$bankId}-{$accountNo}-compact2.png?{$query}";

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $order->id,
                'order_code' => $order->order_code,
                'amount' => $amount,
                'status_payment' => $this->getOrderPaymentState($order),
                'qr_url' => $qrUrl,
            ],
        ]);
    }

    private function generateQrForPreOrder(PreOrder $preOrder)
    {
        $amount = (int) round((float) $preOrder->amount);
        $vietQrConfig = $this->getVietQrConfig();
        $bankId = $vietQrConfig['bank_id'];
        $accountNo = $vietQrConfig['account_no'];
        $accountName = $vietQrConfig['account_name'];

        if (empty($bankId) || empty($accountNo) || empty($accountName)) {
            return response()->json([
                'success' => false,
                'message' => 'Thieu cau hinh VietQR trong he thong hoac trong .env',
            ], 500);
        }

        $query = http_build_query([
            'amount' => $amount,
            'addInfo' => $preOrder->pre_order_code,
            'accountName' => $accountName,
            't' => time(),
        ]);

        $qrUrl = "https://img.vietqr.io/image/{$bankId}-{$accountNo}-compact2.png?{$query}";

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $preOrder->id,
                'order_code' => $preOrder->pre_order_code,
                'amount' => $amount,
                'status_payment' => 'pending',
                'qr_url' => $qrUrl,
                'is_pre_order' => true,
            ],
        ]);
    }

    public function preOrderStatus($code)
    {
        $preOrder = PreOrder::where('pre_order_code', $code)->first();

        if (! $preOrder) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $isWalletDeposit = ($preOrder->payload['pre_order_type'] ?? '') === 'wallet_deposit';

        if ($preOrder->status === 'converted') {
            if ($isWalletDeposit) {
                return response()->json([
                    'success' => true,
                    'status' => 'paid',
                    'order_id' => $preOrder->id,
                    'data' => [
                        'status_payment' => 'paid',
                        'amount' => $preOrder->amount,
                        'order_code' => $preOrder->pre_order_code,
                    ],
                ]);
            }

            // Find the real order created from this pre-order
            $order = Order::where('order_code', $code)->first();
            if ($order) {
                return response()->json([
                    'success' => true,
                    'status' => 'paid',
                    'order_id' => $order->id,
                    'data' => [
                        'status_payment' => 'paid',
                        'amount' => $preOrder->amount,
                        'order_code' => $order->order_code,
                    ],
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'status' => 'pending',
            'data' => [
                'status_payment' => 'pending',
            ],
        ]);
    }

    public function paymentStatus($orderId)
    {
        $order = Order::query()
            ->where('id', $orderId)
            ->orWhere('order_code', $orderId)
            ->first();

        if (! $order) {
            $preOrder = PreOrder::where('id', $orderId)
                ->orWhere('pre_order_code', $orderId)
                ->first();

            if ($preOrder) {
                $isWalletDeposit = ($preOrder->payload['pre_order_type'] ?? '') === 'wallet_deposit';
                
                if ($isWalletDeposit) {
                    $statusPayment = $preOrder->status === 'converted' ? 'paid' : 'pending';
                    return response()->json([
                        'success' => true,
                        'data' => [
                            'order_id' => $preOrder->id,
                            'payment_request_id' => $preOrder->id,
                            'order_code' => $preOrder->pre_order_code,
                            'amount' => (int) round((float) $preOrder->amount),
                            'status_payment' => $statusPayment,
                            'is_pre_order' => true,
                        ],
                    ]);
                }

                $convertedOrder = $preOrder->status === 'converted'
                    ? Order::query()->where('order_code', $preOrder->pre_order_code)->first()
                    : null;

                return response()->json([
                    'success' => true,
                    'data' => [
                        'order_id' => $convertedOrder?->id ?? $preOrder->id,
                        'payment_request_id' => $preOrder->id,
                        'order_code' => $convertedOrder?->order_code ?? $preOrder->pre_order_code,
                        'amount' => (int) round((float) ($convertedOrder?->final_amount ?? $preOrder->amount)),
                        'status_payment' => $convertedOrder ? 'paid' : 'pending',
                        'is_pre_order' => true,
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Don hang khong ton tai.',
            ], 404);
        }

        $statusPayment = $this->getOrderPaymentState($order);

        if ($statusPayment === 'paid' && strtolower((string) ($order->status ?? '')) === 'pending_payment') {
            $transactionId = Schema::hasColumn($order->getTable(), 'transaction_id')
                ? (string) ($order->transaction_id ?? '')
                : null;

            $this->settleOrderAsPaid(
                $order,
                (string) ($order->payment_method ?? 'BANK_TRANSFER'),
                $transactionId
            );

            $order = Order::query()->find($order->id) ?? $order;
            $statusPayment = $this->getOrderPaymentState($order);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $order->id,
                'order_code' => $order->order_code,
                'status_payment' => $statusPayment,
                'amount' => (int) round((float) ($order->final_amount ?? $order->total_amount ?? $order->total ?? 0)),
                'transaction_id' => Schema::hasColumn($order->getTable(), 'transaction_id') ? $order->transaction_id : null,
                'updated_at' => optional($order->updated_at)->toIso8601String(),
            ],
        ]);
    }

    public function sepayWebhook(Request $request)
    {
        $expectedWebhookApiKey = trim((string) config('services.sepay.webhook_api_key', ''));
        if ($expectedWebhookApiKey !== '') {
            $authorization = trim((string) $request->header('Authorization', ''));
            $providedWebhookApiKey = strncasecmp($authorization, 'Apikey ', 7) === 0
                ? trim(substr($authorization, 7))
                : '';

            if ($providedWebhookApiKey === '' || ! hash_equals($expectedWebhookApiKey, $providedWebhookApiKey)) {
                Log::warning('Sepay webhook rejected: invalid API key', [
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 401);
            }
        }

        $payload = $request->all();
        Log::info('Sepay webhook incoming', [
            'ip' => $request->ip(),
            'payload' => $payload,
        ]);

        if (($payload['transferType'] ?? $payload['transfer_type'] ?? '') !== 'in') {
            return response()->json(['success' => true, 'message' => 'Skipped - not incoming transfer']);
        }

        $content = (string) ($payload['content'] ?? $payload['description'] ?? '');
        $transferAmount = (int) ($payload['transferAmount'] ?? $payload['amount'] ?? 0);
        $transactionId = trim((string) ($payload['referenceCode'] ?? $payload['id'] ?? $payload['transId'] ?? ''));

        if ($transactionId === '') {
            Log::warning('Sepay webhook rejected: missing transaction id', [
                'ip' => $request->ip(),
                'payload' => $payload,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Missing transaction id',
            ], 422);
        }

        preg_match('/\b(OD\d{14}[A-Z0-9]{6}|P[A-Z0-9]{10})\b/i', $content, $matches);

        if (empty($matches[1])) {
            Log::info('Sepay webhook: khong tim thay order_code trong noi dung CK', [
                'content' => $content,
            ]);

            return response()->json(['success' => true, 'message' => 'No order code found']);
        }

        $orderCode = strtoupper($matches[1]);

        $preOrder = PreOrder::query()
            ->where('pre_order_code', $orderCode)
            ->first();

        $order = Order::query()
            ->where('order_code', $orderCode)
            ->first();

        if (! $order) {
            if ($preOrder) {
                $preOrderType = (string) ($preOrder->payload['pre_order_type'] ?? 'normal');

                if ($preOrder->status === 'pending' || ($preOrderType === 'wallet_deposit' && $preOrder->status === 'converted')) {
                    return $this->handlePreOrderPayment($preOrder, $transferAmount, $transactionId);
                }
            }

            return response()->json(['success' => true, 'message' => 'Order not found']);
        }

        $expectedAmount = (int) round((float) ($order->final_amount ?? $order->total_amount ?? $order->total ?? 0));

        if ($transferAmount < $expectedAmount) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient amount',
            ], 422);
        }

        if ($this->getOrderPaymentState($order) === 'paid') {
            $this->broadcastOrderPaymentSuccess($order, $preOrder?->id);

            return response()->json([
                'success' => true,
                'message' => 'Order already paid',
                'data' => [
                    'order_id' => $order->id,
                    'order_code' => $order->order_code,
                    'status_payment' => 'paid',
                ],
            ]);
        }

        $this->settleOrderAsPaid($order, 'BANK_TRANSFER', $transactionId !== '' ? $transactionId : null);
        $order = Order::query()->find($order->id) ?? $order;
        $this->broadcastOrderPaymentSuccess($order, $preOrder?->id);

        Log::info('Sepay webhook: thanh toan thanh cong', [
            'order_id' => $order->id,
            'order_code' => $order->order_code,
            'amount' => $expectedAmount,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment confirmed',
            'data' => [
                'order_id' => $order->id,
                'order_code' => $order->order_code,
                'status_payment' => 'paid',
            ],
        ]);
    }

    private function handlePreOrderPayment(PreOrder $preOrder, int $amount, string $transactionId)
    {
        if ($amount < (int) $preOrder->amount) {
            return response()->json(['success' => false, 'message' => 'Insufficient amount'], 422);
        }

        $payload = $preOrder->payload;
        $type = $payload['pre_order_type'] ?? 'normal';

        if ($type === 'wallet_deposit') {
            return $this->handleWalletDepositPayment($preOrder, $amount, $transactionId);
        }

        try {
            DB::beginTransaction();

            $payload['userId'] = $preOrder->user_id;
            unset($payload['pre_order_type']);

            Log::info('Converting pre-order to real order', [
                'pre_order_code' => $preOrder->pre_order_code,
                'type' => $type,
                'user_id' => $preOrder->user_id,
            ]);

            $request = new Request($payload);
            $user = User::find($preOrder->user_id);
            if ($user) {
                $request->setUserResolver(fn () => $user);
            }

            $orderController = new ClientOrderController;

            // Override order_code generation in the place method by passing it in payload or using a flag
            // But wait, the place method generates its own code.
            // We need the NEW order to have the PRE-order code.

            $response = null;
            if ($type === 'menu') {
                $response = $orderController->placeFromMenu($request);
            } elseif ($type === 'ticket') {
                $response = $orderController->placeFromTicket($request);
            } else {
                $response = $orderController->place($request);
            }

            $responseData = $response->getData(true);
            if (! ($responseData['success'] ?? false)) {
                throw new \Exception($responseData['message'] ?? 'Failed to create order');
            }

            $orderId = $responseData['order_id'];
            $order = Order::find($orderId);
            if ($order) {
                // Set the correct order code and mark as paid
                $order->update([
                    'order_code' => $preOrder->pre_order_code,
                    'status_payment' => 'paid',
                    'transaction_id' => $transactionId,
                ]);
                $this->settleOrderAsPaid($order, 'BANK_TRANSFER', $transactionId);
            }

            $preOrder->update(['status' => 'converted']);

            DB::commit();
            $order = Order::query()->find($orderId);

            if ($order) {
                $this->broadcastOrderPaymentSuccess($order, (string) $preOrder->id);
            }

            return response()->json([
                'success' => true,
                'message' => 'Pre-order converted and paid',
                'data' => [
                    'order_id' => $orderId,
                    'order_code' => $preOrder->pre_order_code,
                    'status_payment' => 'paid',
                ],
            ]);

        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::error('Failed to convert pre-order: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Conversion failed: '.$e->getMessage()], 500);
        }
    }

    private function broadcastOrderPaymentSuccess(Order $order, ?string $paymentRequestId = null): void
    {
        event(new OrderPaymentSuccess(
            paymentRequestId: trim((string) ($paymentRequestId ?: $order->id)),
            orderId: (string) $order->id,
            orderCode: (string) $order->order_code,
            statusPayment: $this->getOrderPaymentState($order),
            orderStatus: (string) ($order->status ?? ''),
            finalAmount: (float) ($order->final_amount ?? $order->total_amount ?? $order->total ?? 0),
        ));
    }

    public function handleIPN(Request $request)
    {
        Log::info('VNPay IPN Received', $request->all());
        $vnp_HashSecret = '2J5J7JTFGX8K25CXTREYFWFQGG90XI';

        $inputData = [];
        foreach ($request->all() as $key => $value) {
            if (substr($key, 0, 4) == 'vnp_') {
                $inputData[$key] = $value;
            }
        }

        $vnp_SecureHash = $inputData['vnp_SecureHash'] ?? '';
        unset($inputData['vnp_SecureHash']);
        ksort($inputData);
        $hashData = urldecode(http_build_query($inputData));
        $secureHash = hash_hmac('sha512', $hashData, $vnp_HashSecret);

        if ($secureHash === $vnp_SecureHash) {
            $orderId = $inputData['vnp_TxnRef'];
            $status = $inputData['vnp_ResponseCode'] == '00' ? 'success' : 'failed';

            return response('OK', 200);
        }

        return response('Invalid signature', 400);
    }

    public function handleZaloCallback(Request $request)
    {
        Log::info('Zalo Bank Transfer Notify:', $request->all());
        $privateKey = env('ZALO_PRIVATE_KEY');

        $data = $request->input('data');
        $mac = $request->input('mac');
        $overallMac = $request->input('overallMac');

        $dataForMac = "appId={$data['appId']}&amount={$data['amount']}&description={$data['description']}&orderId={$data['orderId']}&message={$data['message']}&resultCode={$data['resultCode']}&transId={$data['transId']}";
        $reqMac = hash_hmac('sha256', $dataForMac, $privateKey);

        if ($reqMac !== $mac) {
            Log::warning('Sai MAC trong callback');

            return response()->json([
                'returnCode' => -1,
                'returnMessage' => 'Sai MAC, dữ liệu không hợp lệ',
            ]);
        }

        ksort($data);
        $dataOverallMac = collect($data)->map(function ($v, $k) {
            return "$k=$v";
        })->implode('&');

        $reqOverallMac = hash_hmac('sha256', $dataOverallMac, $privateKey);
        if ($reqOverallMac !== $overallMac) {
            Log::warning('Sai overallMac trong callback');

            return response()->json([
                'returnCode' => -2,
                'returnMessage' => 'Sai overallMac, dữ liệu không hợp lệ',
            ]);
        }

        $order = Order::where('order_id', $data['orderId'])->first();
        if (! $order) {
            return response()->json([
                'returnCode' => -3,
                'returnMessage' => 'Không tìm thấy đơn hàng',
            ]);
        }

        if ($this->getOrderPaymentState($order) === 'paid') {
            return response()->json([
                'returnCode' => 2,
                'returnMessage' => 'Đơn hàng đã được thanh toán',
            ]);
        }

        if ($data['resultCode'] == 1) {
            $this->settleOrderAsPaid($order, 'BANK_TRANSFER');

            return response()->json([
                'returnCode' => 1,
                'returnMessage' => 'Thanh toán thành công',
            ]);
        }

        return response()->json([
            'returnCode' => -4,
            'returnMessage' => 'Thanh toán thất bại',
        ]);
    }

    public function bankTransferNotify(Request $request)
    {
        $orderId = $request->input('order_id');
        $amount = $request->input('amount');
        $status = $request->input('status');
        $transactionId = $request->input('trans_id');

        $order = Order::find($orderId);
        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Đơn hàng không tồn tại.',
            ], 404);
        }

        if ($status === 'success' && $order->total == $amount) {
            $this->settleOrderAsPaid($order, 'BANK_TRANSFER', $transactionId !== null ? (string) $transactionId : null);

            return response()->json([
                'success' => true,
                'message' => 'Thanh toán chuyển khoản thành công.',
                'order_id' => $order->id,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Thanh toán không hợp lệ hoặc số tiền không khớp.',
        ]);
    }

    public function updatePaymentMethod(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required',
            'payment_method' => 'required|string',
        ]);

        $order = Order::query()
            ->where('id', $validated['order_id'])
            ->orWhere('order_code', $validated['order_id'])
            ->first();

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        $newMethod = strtoupper(trim($validated['payment_method']));

        $dispatchNewOrderPlaced = false;

        $order = DB::transaction(function () use ($order, $newMethod, &$dispatchNewOrderPlaced) {
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedOrder) {
                return $order;
            }

            // Update payment method
            $lockedOrder->update(['payment_method' => $newMethod]);

            // If switching to CASH, move status from pending_payment to pending
            if (in_array($newMethod, ['CASH', 'COD'], true) && $lockedOrder->status === 'pending_payment') {
                $lockedOrder->update(['status' => 'pending']);
                $this->precheckStoreCreditForPendingOrder($lockedOrder->fresh() ?? $lockedOrder);
                $dispatchNewOrderPlaced = true;
            }

            // If switching from CASH to BANK, move status to pending_payment
            if (! in_array($newMethod, ['CASH', 'COD'], true) && $this->isPendingPaymentMethod($newMethod) && $lockedOrder->status === 'pending') {
                // Only if it was originally a pending order and not paid yet
                if ($lockedOrder->status_payment !== 'paid') {
                    $lockedOrder->update(['status' => 'pending_payment']);
                }
            }

            return $lockedOrder->fresh() ?? $lockedOrder;
        });

        if ($dispatchNewOrderPlaced) {
            event(new NewOrderPlaced($order));
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment method updated successfully',
            'data' => [
                'order_id' => $order->id,
                'payment_method' => $order->payment_method,
                'status' => $order->status,
            ],
        ]);
    }

    private function handleWalletDepositPayment(PreOrder $preOrder, int $amount, string $transactionId)
    {
        $transactionId = trim($transactionId);
        if ($transactionId === '') {
            return response()->json(['success' => false, 'message' => 'Missing transaction id'], 422);
        }

        $depositReference = 'sepay:' . hash('sha256', $transactionId);
        $existingDeposit = $this->findWalletDepositLedger($depositReference);
        if ($existingDeposit !== null) {
            $metadata = json_decode((string) ($existingDeposit->metadata ?? ''), true);
            $existingPreOrderId = is_array($metadata) ? (string) ($metadata['pre_order_id'] ?? '') : '';

            if ($existingPreOrderId !== '' && $existingPreOrderId === (string) $preOrder->id && $preOrder->status !== 'converted') {
                $preOrder->update(['status' => 'converted']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Wallet deposit transaction already processed',
                'data' => [
                    'order_id' => $preOrder->id,
                    'order_code' => $preOrder->pre_order_code,
                    'status_payment' => $existingPreOrderId === (string) $preOrder->id ? 'paid' : 'pending',
                    'idempotent' => true,
                ],
            ]);
        }

        try {
            DB::beginTransaction();

            $storeId = $preOrder->payload['store_id'];
            $creditService = app(\App\Services\StoreCreditService::class);

            $depositResult = $creditService->recordWalletDeposit(
                $storeId,
                $amount,
                $depositReference,
                [
                    'source' => 'sepay_webhook',
                    'transaction_id' => $transactionId,
                    'pre_order_id' => $preOrder->id,
                    'pre_order_code' => $preOrder->pre_order_code,
                ]
            );

            $preOrder->update(['status' => 'converted']);

            DB::commit();

            event(new \App\Events\OrderPaymentSuccess(
                paymentRequestId: (string) $preOrder->id,
                orderId: (string) $preOrder->id,
                orderCode: (string) $preOrder->pre_order_code,
                statusPayment: 'paid',
                orderStatus: 'completed',
                finalAmount: (float) $amount,
            ));

            return response()->json([
                'success' => true,
                'message' => ($depositResult['idempotent'] ?? false)
                    ? 'Wallet deposit transaction already processed'
                    : 'Wallet deposit processed successfully',
                'data' => [
                    'order_id' => $preOrder->id,
                    'order_code' => $preOrder->pre_order_code,
                    'status_payment' => 'paid',
                    'idempotent' => (bool) ($depositResult['idempotent'] ?? false),
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to process wallet deposit pre-order: '.$e->getMessage());
            return response()->json(['success' => false, 'message' => 'Wallet deposit failed: '.$e->getMessage()], 500);
        }
    }

    private function findWalletDepositLedger(string $depositReference): ?object
    {
        if (
            ! Schema::hasTable('store_ticket_transactions')
            || ! Schema::hasColumn('store_ticket_transactions', 'source_type')
            || ! Schema::hasColumn('store_ticket_transactions', 'source_id')
        ) {
            return null;
        }

        return DB::table('store_ticket_transactions')
            ->where('source_type', 'STORE_DEPOSIT')
            ->where('source_id', $depositReference)
            ->orderBy('created_at')
            ->first();
    }
}
