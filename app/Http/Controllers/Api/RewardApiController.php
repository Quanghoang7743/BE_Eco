<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use App\Models\RewardLog;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class RewardApiController extends Controller
{
    public function spin(Request $request)
    {
        $user = null;
        $authHeader = $request->header('Authorization');
        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken) {
                $user = $accessToken->tokenable;
            }
        }

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Không xác thực người dùng'], 401);
        }

        if ($user->extra_spin > 0) {
            $user->decrement('extra_spin');
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Bạn đã hết lượt quay.'
            ], 400);
        }

        // Chỉ lấy các phần thưởng còn quantity hoặc không giới hạn (null)
        $rewards = Reward::where(function ($query) {
            $query->whereNull('quantity')->orWhere('quantity', '>', 0);
        })
            ->where('probability', '>', 0)
            ->get();

        if ($rewards->isEmpty()) {
            return response()->json(['status' => 'error', 'message' => 'Không còn phần thưởng nào khả dụng'], 400);
        }

        // Lấy phần thưởng ngẫu nhiên theo xác suất
        $selectedReward = $this->getRandomReward($rewards);

        if (!$selectedReward) {
            return response()->json(['status' => 'success', 'reward' => [
                'id' => null,
                'name' => 'Không trúng',
                'type' => 'none',
                'value' => null,
                'image' => null,
            ]]);
        }
        if ($selectedReward) {
            switch ($selectedReward->type) {
                case 'voucher':
                    if ($selectedReward->voucher_id && $user) {
                        // Kiểm tra user đã có chưa
                        $already = $user->vouchers()->where('voucher_id', $selectedReward->voucher_id)->exists();

                        if (!$already) {
                            $user->vouchers()->attach($selectedReward->voucher_id);
                        }
                    }
                    break;

                case 'product':

                    $giftOrder = \App\Models\Order::create([
                        'user_id'        => $user->id,
                        'name'           => $user->name ?? 'Khách hàng',
                        'phone'          => $user->phone ?? '',
                        'address'        => $user->address ?? '',
                        'payment_method' => 'COD',
                        'total'          => 0,
                        'status'         => 'pending',
                        'voucher_code'   => null,
                    ]);
                    // return $giftOrder;

                    // Tạo order item với tên sản phẩm có chữ (Tặng)
                    $product = $selectedReward->product;
                    if ($product) {
                        \App\Models\OrderItem::create([
                            'order_id'         => $giftOrder->id,
                            'product_id'       => $product->id,
                            'variant_id'       => null,
                            'product_name'     => $product->name . ' (Tặng)',
                            'thumbnail'        => $product->thumbnail,
                            'price'            => 0,
                            'quantity'         => 1,
                            'referrer_id'      => null,
                            'commission_amount' => 0,
                        ]);

                        // Trừ tồn kho nếu cần
                        $product->decrement('stock');
                    }
                    break;

                case 'point':
                    // Cộng coin/xu
                    $user->increment('point', $selectedReward->value ?? 0);
                    break;
                case 'extra_spin':
                    // Cộng thêm lượt quay
                    $user->increment('extra_spin', $selectedReward->value ?? 1); // mặc định 1 lượt
                    break;

                default:
                    // Không cần xử lý gì thêm
                    break;
            }
        }

        // Giảm quantity nếu có giới hạn
        if (!is_null($selectedReward->quantity)) {
            $selectedReward->decrement('quantity');
        }

        // Ghi log
        RewardLog::create([
            'user_id' => $user->id,
            'reward_id' => $selectedReward->id,
            'reward_name' => $selectedReward->name,
        ]);

        // Trả dữ liệu phần thưởng chi tiết
        return response()->json([
            'status' => 'success',
            'reward' => [
                'id' => $selectedReward->id,
                'name' => $selectedReward->name,
                'type' => $selectedReward->type,
                'value' => $selectedReward->value,
                'image' => $selectedReward->image,
                'product' => $selectedReward->product, // trả về nếu cần
                'voucher' => $selectedReward->voucher, // trả về nếu cần
            ],
        ]);
    }


    private function getRandomReward($rewards)
    {
        $totalWeight = $rewards->sum('probability');
        $random = rand(1, $totalWeight);
        $current = 0;

        foreach ($rewards as $reward) {
            $current += $reward->probability;
            if ($random <= $current) {
                return $reward;
            }
        }

        return null;
    }


    public function getRewards()
    {
        $rewards = Reward::all(['id', 'name']);

        return response()->json([
            'status' => 'success',
            'rewards' => $rewards,
        ]);
    }
}
