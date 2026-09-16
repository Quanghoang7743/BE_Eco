<?php

namespace App\Http\Controllers;

use App\Models\Reward;
use App\Http\Requests\StoreRewardRequest;
use App\Http\Requests\UpdateRewardRequest;
use App\Models\Product;
use App\Models\Voucher;
use Illuminate\Http\Request;

class RewardController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $rewards = Reward::with(['product', 'voucher'])->get();
        return view('admin.rewards.index', compact('rewards'));
    }

    public function create()
    {
        $products = Product::all();
        $vouchers = Voucher::all();
        return view('admin.rewards.create', compact('products', 'vouchers'));
        // return view('admin.rewards.create');
    }

    public function store(Request $request)
    {
        $data = $request->only(['name', 'type', 'value', 'quantity', 'probability', 'product_id', 'voucher_id']);

        if ($data['type'] !== 'product') {
            $data['product_id'] = null;
        }

        if ($data['type'] !== 'voucher') {
            $data['voucher_id'] = null;
        }

        if (!in_array($data['type'], ['point', 'extra_spin'])) {
            $data['value'] = null;
        }

        Reward::create($data);
        return redirect()->route('admin.rewards.index')->with('success', 'Đã thêm phần thưởng!');
    }


    public function edit(Reward $reward)
    {
        $products = Product::all();
        $vouchers = Voucher::all();
        return view('admin.rewards.create', compact('reward', 'products', 'vouchers'));
    }

    public function update(Request $request, Reward $reward)
    {
        $data = $request->only(['name', 'type', 'value', 'quantity', 'probability', 'product_id', 'voucher_id']);

        if ($data['type'] !== 'product') {
            $data['product_id'] = null;
        }

        if ($data['type'] !== 'voucher') {
            $data['voucher_id'] = null;
        }

        if (!in_array($data['type'], ['point', 'extra_spin'])) {
            $data['value'] = null;
        }

        $reward->update($data);

        return redirect()->route('admin.rewards.index')->with('success', 'Đã cập nhật!');
    }


    public function destroy(Reward $reward)
    {
        $reward->delete();
        return redirect()->route('admin.rewards.index')->with('success', 'Đã xoá!');
    }
}
