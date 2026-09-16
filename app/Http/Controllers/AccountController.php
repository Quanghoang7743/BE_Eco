<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\banner;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    public function index()
    {
        return view('user');
    }

    public function agency()
    {
        $user = auth()->user();
        $teamSales = Order::whereNotNull('referrer_id')->sum('total');
        $userSale = Order::where('referrer_id', $user->id)->sum('total');
        $banners = banner::where('position', 3)->where('status', 1)->get();
        // $commission_pending = DB::table('order_items')
        //     ->join('orders', 'order_items.order_id', '=', 'orders.id')
        //     ->where('order_items.referrer_id', $user->id)
        //     ->where('orders.status', '!=', 'completed')
        //     ->where('orders.status', '!=', 'cancelled')
        //     ->sum('order_items.commission_amount');
        // $commission_completed = DB::table('order_items')
        //     ->join('orders', 'order_items.order_id', '=', 'orders.id')
        //     ->where('order_items.referrer_id', $user->id)
        //     ->where('orders.status', 'completed')
        //     ->sum('order_items.commission_amount');
        $commission_pending = DB::table('commission_users')
            ->join('order_items', 'commission_users.order_item_id', '=', 'order_items.id')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('commission_users.user_id', $user->id)
            ->where('commission_users.status', 'pending')
            ->sum('commission_users.amount');

        $commission_completed = DB::table('commission_users')
            ->join('order_items', 'commission_users.order_item_id', '=', 'order_items.id')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('commission_users.user_id', $user->id)
            ->where('commission_users.status', 'paid')
            ->sum('commission_users.amount');

        $count_order_completed = Order::where('referrer_id', $user->id)->where('status', 'completed')->count();
        $count_user_referrer = Order::whereNotNull('referrer_id')->distinct('referrer_id')->count('referrer_id');
        return view('agency', compact('user', 'teamSales', 'userSale', 'commission_pending', 'commission_completed', 'count_user_referrer', 'count_order_completed', 'banners'));
    }

    public function accoutPayment()
    {
        $banks = Bank::where('is_active', true)->orderBy('name')->get();
        $bankAccount = BankAccount::where('user_id', auth()->id())->first();
        return view('payment_account', compact('banks', 'bankAccount'));
    }

    public function storeAccountPayment(Request $request)
    {
        $validated = $request->validate([
            'bank_name' => 'required|string|max:255',
            'account_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:50',
        ], [
            'bank_name.required' => 'Vui lòng chọn ngân hàng.',
            'account_name.required' => 'Vui lòng nhập tên chủ tài khoản.',
            'account_number.required' => 'Vui lòng nhập số tài khoản.',
        ]);

        $bankId = null;
        if (! empty($validated['bank_name'])) {
            $bank = Bank::where('name', $validated['bank_name'])->first();
            if ($bank) {
                $bankId = $bank->id;
            }
        }

        $bankAccount = BankAccount::firstOrNew(['user_id' => auth()->id()]);
        $bankAccount->bank_id = $bankId;
        $bankAccount->account_name = $validated['account_name'];
        $bankAccount->account_number = $validated['account_number'];
        $bankAccount->save();

        return redirect()->route('account.accoutPayment')->with('success', 'Thông tin tài khoản đã được lưu thành công.');
    }
}

