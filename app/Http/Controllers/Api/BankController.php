<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BankService;
use Illuminate\Http\Request;

class BankController extends Controller
{
    private BankService $bankService;

    public function __construct(BankService $bankService)
    {
        $this->bankService = $bankService;
    }

    public function index(Request $request)
    {
        $banks = $this->bankService->getActiveBanks();
        return response()->json($banks);
    }

    public function lookup(Request $request)
    {
        $validated = $request->validate([
            'bank_id' => 'required',
            'account_number' => 'required|string',
        ]);

        $bank = \App\Models\Bank::find($validated['bank_id']);
        if (!$bank) {
            return response()->json([
                'success' => false,
                'message' => 'Ngân hàng không tồn tại trong hệ thống.'
            ], 404);
        }

        // Get credentials from AppSetting or ENV
        $settings = \App\Models\appSetting::first();
        $clientId = trim((string) ($settings?->vietqr_client_id ?: env('VIETQR_CLIENTID')));
        $apiKey = trim((string) ($settings?->vietqr_api_key ?: env('VIETQR_API_KEY')));

        if (empty($clientId) || empty($apiKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Cấu hình VietQR chưa được thiết lập trên server.'
            ], 400);
        }

        // Fetch VietQR banks list to map bank code -> bin
        $vietQrBanks = \Illuminate\Support\Facades\Cache::remember('vietqr.banks.list', now()->addDays(7), function () {
            try {
                $response = \Illuminate\Support\Facades\Http::get('https://api.vietqr.io/v2/banks');
                if ($response->successful()) {
                    return $response->json()['data'] ?? [];
                }
            } catch (\Exception $e) {
                // Fail silently and return empty
            }
            return [];
        });

        $dbCode = strtoupper($bank->code);
        $codeMap = [
            'CTG' => 'ICB',
            'MBB' => 'MB',
            'NVB' => 'BAB',
            'PVCOM' => 'PVCB',
            'SEB' => 'SEAB',
            'SHN' => 'SHBVN',
            'HSB' => 'HSBC'
        ];
        $vietQrCode = $codeMap[$dbCode] ?? $dbCode;

        // Find bank in the VietQR list
        $vietQrBank = collect($vietQrBanks)->first(function ($b) use ($vietQrCode) {
            return strtoupper($b['code'] ?? '') === $vietQrCode;
        });

        if (!$vietQrBank || empty($vietQrBank['bin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy mã BIN cho ngân hàng này.'
            ], 400);
        }

        $bin = $vietQrBank['bin'];

        try {
            $lookupResponse = \Illuminate\Support\Facades\Http::withHeaders([
                'x-client-id' => $clientId,
                'x-api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.vietqr.io/v2/lookup', [
                'bin' => $bin,
                'accountNumber' => $validated['account_number'],
            ]);

            if (!$lookupResponse->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Lỗi kết nối dịch vụ xác thực VietQR.'
                ], 502);
            }

            $result = $lookupResponse->json();
            if (($result['code'] ?? '') === '00' && !empty($result['data']['accountName'])) {
                return response()->json([
                    'success' => true,
                    'account_name' => $result['data']['accountName']
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['desc'] ?? 'Không tìm thấy thông tin tài khoản hoặc số tài khoản không hợp lệ.'
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Không thể kết nối đến hệ thống kiểm tra số tài khoản.'
            ], 500);
        }
    }
}