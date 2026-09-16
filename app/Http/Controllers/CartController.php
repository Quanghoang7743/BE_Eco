<?php

namespace App\Http\Controllers;

use App\Events\NewOrderPlaced;
use App\Models\Commission;
use App\Models\CommissionUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CartController extends Controller
{
    private function resolveAffiliateRecipientId($referrerId, ?int $currentUserId = null): ?int
    {
        if ($currentUserId) {
            $currentUser = User::query()->find($currentUserId);
            $storedReferrerId = (int) ($currentUser?->referrer_id ?? 0);

            if ($storedReferrerId > 0 && $storedReferrerId !== (int) $currentUserId) {
                return $storedReferrerId;
            }
        }

        if (! $referrerId) {
            return null;
        }

        $resolvedId = User::resolveReferrerId($referrerId);
        if (! $resolvedId) {
            return null;
        }

        if ($currentUserId && $resolvedId === (int) $currentUserId) {
            return null;
        }

        return $resolvedId;
    }

    public function addToCart(Request $request, $productId)
    {
        $product = Product::with('variants.attributeValues.attribute')->find($productId);

        if (!$product) {
            return redirect()->back()->with('error', 'Sản phẩm không tồn tại!');
        }

        $cart = session()->get('cart', []);
        $variantIndex = $request->input('variant_index');
        $variant = null;

        if ($product->variants->count() > 0 && is_null($variantIndex)) {
            return redirect()
                ->route('product.show', ['slug' => $product->slug])
                ->with('error', 'Vui lòng chọn biến thể trước khi thêm vào giỏ hàng!');
        }

        if (!is_null($variantIndex) && isset($product->variants[$variantIndex])) {
            $variant = $product->variants[$variantIndex];
        }

        // Xác định số lượng hiện tại đã có trong giỏ (nếu có)
        $currentQty = isset($cart[$product->id]) ? $cart[$product->id]['quantity'] : 0;
        // Nếu có biến thể
        if ($variant) {
            if ($currentQty + 1 > $variant->stock) {
                return redirect()->back()->with('error', 'Không đủ hàng trong kho cho biến thể đã chọn!');
            }
            // Kiểm tra tồn kho
            $alreadyExists = collect($cart)->firstWhere('variant_id', $variant->id);
            if ($alreadyExists && $alreadyExists['quantity'] >= $variant->stock) {
                return redirect()->back()->with('error', 'Không đủ hàng trong kho!');
            }

            $attributeText = collect($variant->attributeValues)
                ->map(fn($val) => $val->attribute->name . ': ' . $val->value)
                ->implode(', ');

            $cart[$product->id] = [
                'variant_id' => $variant->id,
                'name'       => $product->name . " ({$attributeText})",
                'quantity'   => $currentQty + 1,
                'image'      => $variant->image ?? $product->thumbnail,
            ];
        } else {
            if ($currentQty + 1 > $product->stock) {
                return redirect()->back()->with('error', 'Không đủ hàng trong kho!');
            }
            // Sản phẩm không có biến thể
            $alreadyExists = collect($cart)->filter(function ($item) use ($product) {
                return $item['variant_id'] === null && str_starts_with($item['name'], $product->name);
            })->first();

            if ($alreadyExists && $alreadyExists['quantity'] >= $product->stock) {
                return redirect()->back()->with('error', 'Không đủ hàng trong kho!');
            }

            $cart[$product->id] = [
                'variant_id' => null,
                'name'       => $product->name,
                'price'      => $product->sale_price,
                'quantity'   => $currentQty + 1,
                'image'      => $product->thumbnail,
            ];
        }

        session()->put('cart', $cart);
        return redirect()->route('cart.view')->with('success', 'Đã thêm vào giỏ hàng!');
    }


    // CartController.php (thêm phương thức để hiển thị giỏ hàng)
    public function viewCart()
    {
        $carts = session()->get('cart', []);
        $total = 0;

        foreach ($carts as $item) {
            $total += $item['price'] * $item['quantity'];
        }

        return view('cart', compact('carts', 'total'));
    }

    public function decreaseQuantity($productId)
    {
        $cart = session()->get('cart', []);

        // Kiểm tra sản phẩm có trong giỏ không
        if (!isset($cart[$productId])) {
            return redirect()->route('cart.view')->with('error', 'Sản phẩm không có trong giỏ hàng!');
        }

        // Giảm số lượng hoặc xoá hẳn
        if ($cart[$productId]['quantity'] > 1) {
            $cart[$productId]['quantity']--;
            $message = 'Đã giảm 1 sản phẩm trong giỏ.';
        } else {
            unset($cart[$productId]);
            $message = 'Đã xoá sản phẩm khỏi giỏ hàng.';
        }

        session()->put('cart', $cart);

        return redirect()->route('cart.view')->with('success', $message);
    }


    public function clientProduct()
    {
        return view('product');
    }

    public function place(Request $request)
    {
        $request->validate([
            'name'           => 'required|string|max:255',
            'phone'          => 'required|string|max:20',
            'address'        => 'required|string|max:255',
            'payment_method' => 'required|string',
        ]);

        $cart = session()->get('cart', []);
        if (empty($cart)) {
            return back()->with('error', 'Giỏ hàng trống!');
        }

        DB::beginTransaction();

        try {
            $isLoggedIn = Auth::check();
            $userId = $isLoggedIn ? Auth::id() : null;
            $rawReferrerId = $isLoggedIn ? Auth::user()->referrer_id : session()->get('referrer_id');
            $referrerId = $this->resolveAffiliateRecipientId(
                $rawReferrerId,
                $userId ? (int) $userId : null,
            );
            $oldUser = session()->get('oldUser');
            $oldOrder = Order::where('phone', $request->phone)->where('status', 'completed')->exists();
            $oldOrderById = $userId ? Order::where('user_id', $userId)->where('status', 'completed')->exists() : false;

            $total = 0;

            // Batch-load all cart variants (with their product) once instead of
            // calling ProductVariant::find() per item in each loop (fixes N+1).
            $variantIds = collect($cart)
                ->pluck('variant_id')
                ->filter()
                ->unique()
                ->values()
                ->all();
            $variantsById = !empty($variantIds)
                ? ProductVariant::with('product')->whereIn('id', $variantIds)->get()->keyBy('id')
                : collect();

            // Load the referrer once; it is constant for the whole cart.
            $referrer = $referrerId ? User::find($referrerId) : null;

            foreach ($cart as $item) {
                // dd($cart);
                $variant = $item['variant_id'] ? $variantsById->get($item['variant_id']) : null;
                $price = $item['price'];
                $stock = $variant ? $variant->stock : ($item['stock'] ?? 0);

                if ($item['quantity'] > $stock) {
                    throw new \Exception("Sản phẩm \"{$item['name']}\" chỉ còn {$stock} trong kho.");
                }

                // Giảm 5% nếu là đơn đầu tiên
                if (!$oldOrder && !$oldUser) {
                    $price *= 0.95;
                }

                $total += $price * $item['quantity'];
            }

            $order = Order::create([
                'user_id'        => $userId,
                'name'           => $request->name,
                'phone'          => $request->phone,
                'address'        => $request->address,
                'payment_method' => $request->payment_method,
                'total'          => $total,
                'status'         => 'pending',
                'referrer_id'    => $referrerId,
            ]);

            foreach ($cart as $item) {
                $variant = $item['variant_id'] ? $variantsById->get($item['variant_id']) : null;
                $price = $item['price'];
                $stock = $variant ? $variant->stock : ($item['stock'] ?? 0);

                if (!$oldUser) {
                    session()->put('oldUser', true);
                    $price *= 0.95;
                }

                $commissionRate = 0;
                $commissionRateMode = $item['commissionRateMode'] ?? 'percent';
                
                if ($variant && isset($variant->commission_rate) && $variant->commission_rate !== null) {
                    $commissionRate = (float) $variant->commission_rate;
                } elseif (!empty($variant) && $variant->product) {
                    $commissionRate = (float) ($variant->product->commission_rate ?? 0);
                }

                // Calculate commission based on mode
                if ($commissionRateMode === 'fixed') {
                    $commissionAmount = $commissionRate; // Fixed amount
                } else {
                    $commissionAmount = $price * $item['quantity'] * ($commissionRate / 100); // Percentage
                }

                $orderItem = OrderItem::create([
                    'order_id'         => $order->id,
                    'product_id'       => $variant ? $variant->product_id : null,
                    'variant_id'       => $variant ? $variant->id : null,
                    'product_name'     => $item['name'],
                    'thumbnail'        => $item['image'],
                    'price'            => $price,
                    'quantity'         => $item['quantity'],
                    'referrer_id'      => $referrerId,
                    'commission_amount' => $commissionAmount,
                    'commission_rate_mode' => $commissionRateMode,
                ]);

                // Giảm tồn kho
                if ($variant) {
                    $variant->decrement('stock', $item['quantity']);
                }

                // ✅ Nếu đã đăng nhập và từng mua trước đó → xử lý hoa hồng trực tiếp cấp 1
                // For CASH/COD payments, only create commission when payment is confirmed (upon delivery)
                $paymentMethodNorm = strtoupper(trim((string) $request->payment_method));
                if ($isLoggedIn && $oldOrderById && $referrerId && !in_array($paymentMethodNorm, ['CASH', 'COD'], true)) {
                    if ($referrer) {
                        CommissionUser::create([
                            'user_id'       => $referrer->id,
                            'order_item_id' => $orderItem->id,
                            'level'         => 1,
                            'amount'        => $commissionAmount,
                            'status'        => 'pending',
                            'agency_rights' => true,
                        ]);
                    }
                }
            }

            DB::commit();
            app(\App\Services\AffiliateCommissionService::class)->syncOrderCommissions($order->fresh(['items.product', 'items.variant']));
            session()->forget(['cart', 'referrer_id']);
            event(new NewOrderPlaced($order));
            Log::info('Đã phát event đơn hàng mới: Order#' . $order->id);

            // ✅ Xử lý VNPAY nếu được chọn
            if ($request->payment_method === 'VNPAY') {
                $vnp_TmnCode    = '3XN2ER8H';
                $vnp_HashSecret = '2J57J7TFGX8K25CXTREYFWFQGG9OXKR0';
                $vnp_Url        = 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html';
                $vnp_Returnurl  = route('vnpay.return');

                $inputData = [
                    "vnp_Version"    => "2.0.0",
                    "vnp_TmnCode"    => $vnp_TmnCode,
                    "vnp_Amount"     => $order->total * 100,
                    "vnp_Command"    => "pay",
                    "vnp_CreateDate" => now()->format('YmdHis'),
                    "vnp_CurrCode"   => "VND",
                    "vnp_IpAddr"     => $request->ip(),
                    "vnp_Locale"     => "vn",
                    "vnp_OrderInfo"  => "Thanh toán hóa đơn phí dịch vụ",
                    "vnp_OrderType"  => "billpayment",
                    "vnp_ReturnUrl"  => $vnp_Returnurl,
                    "vnp_TxnRef"     => $order->id,
                ];

                ksort($inputData);
                $hashData = urldecode(http_build_query($inputData));
                $vnp_SecureHash = hash_hmac('sha512', $hashData, $vnp_HashSecret);
                $vnp_Url = $vnp_Url . '?' . http_build_query($inputData) . '&vnp_SecureHash=' . $vnp_SecureHash;

                return redirect($vnp_Url);
            }

            return redirect('/')->with('success', 'Đặt hàng thành công!');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Place Order failed: ' . $e->getMessage());
            return back()->with('error', 'Có lỗi khi đặt hàng: ' . $e->getMessage());
        }
    }



    public function callback(Request $request)
    {
        $vnp_HashSecret = '2J57J7TFGX8K25CXTREYFWFQGG9OXKR0'; // Replace with your real hash secret
        $inputData = $request->all();

        // Remove the vnp_SecureHash from the input data before calculating the hash
        unset($inputData['vnp_SecureHash']);

        // Sort the input data by key
        ksort($inputData);

        // Prepare the string for hashing
        $hashData = "";
        foreach ($inputData as $key => $value) {
            $hashData .= urlencode($key) . "=" . urlencode($value) . '&';
        }

        // Remove the last '&' character from the string
        $hashData = rtrim($hashData, '&');

        // Calculate the secure hash using SHA-512
        $secureHash = hash_hmac('sha512', $hashData, $vnp_HashSecret);

        // Compare the calculated hash with the one sent by VNPay
        if ($secureHash === $request->input('vnp_SecureHash')) {
            // Hash matches, verify the response code
            if (trim($request->input('vnp_ResponseCode')) === '00') {
                // return "ok" . $request->input('vnp_ResponseCode');

                $orderId = $request->input('vnp_TxnRef');
                $amount = $request->input('vnp_Amount') / 100;

                $order = Order::find($orderId);
                if (!$order) {
                    return redirect('/')->with('error', 'Không tìm thấy đơn hàng.');
                }

                // Nếu VNPAY trả về amount * 100 thì cần chia trước
                $vnPayAmount = (int) $request->input('vnp_Amount');
                $amount = $vnPayAmount / 100;

                if (!is_numeric($amount)) {
                    return redirect('/')->with('error', 'Dữ liệu thanh toán không hợp lệ.');
                }

                if ($order->total != $amount) {
                    return redirect('/')->with('error', 'Số tiền không khớp.');
                }

                if ($order->status_payment == 'paid') {
                    return redirect('/')->with('error', 'Đơn hàng này đã thanh toán trước đó.');
                }
                if ($order && $order->total == $amount) {
                    $order->update([
                        'status_payment' => 'paid',
                        'payment_method' => 'VNPAY',
                    ]);
                    return redirect('/')->with('success', 'Thanh toán thành công!');
                }
            }

            return redirect('/')->with('error', 'Thanh toán thất bại!');
        } else {
            return redirect('/')->with('error', 'Sai mã xác thực!');
        }
    }



    public function generateQr(Request $request)
    {
        // Giả sử bạn đã validate và tạo đơn hàng
        $order = Order::create([
            'user_id' => auth()->id(),
            'total_amount' => $request->input('total_amount'),
            'status' => 'pending',
            'payment_method' => 'MB'
        ]);

        // Tạo mã QR chuyển khoản
        $qrResponse = Http::post('https://api.vietqr.io/v2/generate', [
            'accountNo' => '0398623059', //Số tài khoản người nhận
            'accountName' => 'TRAN HUY HUNG',
            'acqId' => '970422',
            'amount' => $order->total_amount,
            'addInfo' =>  $order->id,
            'template' => 'compact2',
        ]);

        $qrUrl = $qrResponse->json('data.qrCodeURL');

        // Lưu URL mã QR vào đơn hàng (giả sử có cột qr_code_url)
        // $order->update([
        //     'qr_code_url' => $qrUrl,
        // ]);

        return redirect()->route('orders.show', $order->id);
    }

    public function checkout(Request $request)
    {
        // return $request->id;
        $vnp_TmnCode = '3XN2ER8H'; // Thay bằng mã của bạn
        $vnp_HashSecret = '2J57J7TFGX8K25CXTREYFWFQGG9OXKR0'; // Thay bằng mã của bạn
        $vnp_Url = 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html';
        // $vnp_Url = 'https://pay.vnpay.vn/vpcpay.html';
        $vnp_Returnurl = route('vnpay.return');

        $vnp_TxnRef = $request->id;
        $vnp_OrderInfo = "Thanh toán hóa đơn phí dich vụ";
        $vnp_Amount = $request->total * 100; // Tính theo đơn vị VND
        $vnp_Locale = 'vn';
        $vnp_IpAddr = $request->ip();

        $inputData = [
            "vnp_Version" => "2.0.0",
            "vnp_TmnCode" => $vnp_TmnCode,
            "vnp_Amount" => $vnp_Amount,
            "vnp_Command" => "pay",
            "vnp_CreateDate" => now()->format('YmdHis'),
            "vnp_CurrCode" => "VND",
            "vnp_IpAddr" => $vnp_IpAddr,
            "vnp_Locale" => $vnp_Locale,
            "vnp_OrderInfo" => $vnp_OrderInfo,
            "vnp_OrderType" => "billpayment",
            "vnp_ReturnUrl" => $vnp_Returnurl,
            "vnp_TxnRef" => $vnp_TxnRef,

        ];

        ksort($inputData);

        // 1. Tạo hashData (KHÔNG ENCODE)
        $hashData = '';
        foreach ($inputData as $key => $value) {
            $hashData .= $key . '=' . $value . '&';
        }
        $hashData = rtrim($hashData, '&');

        // 2. Tạo query (CÓ ENCODE)
        $query = http_build_query($inputData);

        // 3. Tạo mã chữ ký
        $vnp_SecureHash = hash_hmac('sha512', $hashData, $vnp_HashSecret);

        // 4. Tạo URL cuối cùng
        $vnp_Url = $vnp_Url . '?' . $query . '&vnp_SecureHash=' . $vnp_SecureHash;

        // 5. Redirect
        return redirect($vnp_Url);
    }
}
