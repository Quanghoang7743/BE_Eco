<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Voucher;
use App\Services\VoucherFollowerNotificationService;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    public function __construct(
        private readonly VoucherFollowerNotificationService $voucherFollowerNotificationService
    ) {
    }

    // Hiển thị danh sách voucher
    public function index()
    {
        $vouchers = Voucher::with('store')->latest()->paginate(10);
        return view('admin.vocher.index', compact('vouchers'));
    }

    // Hiển thị form tạo mới
    public function create()
    {
        $stores = Store::query()->orderBy('name')->get(['id', 'name']);
        return view('admin.vocher.create', compact('stores'));
    }

    // Lưu voucher mới
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:vouchers,code',
            'description' => 'nullable|string',
            'type' => 'required|in:percentage,fixed',
            'discount_value' => 'required|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0',
            'min_order_value' => 'nullable|numeric|min:0',
            'quantity' => 'required|integer|min:0',
            'store_id' => 'nullable|exists:stores,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_active' => 'required|boolean',
        ]);

        $voucher = Voucher::create($validated);
        $this->voucherFollowerNotificationService->dispatchForVoucher($voucher);

        return redirect()->route('admin.vouchers.index')->with('success', 'Tạo voucher thành công.');
    }

    // Hiển thị chi tiết voucher
    public function show(Voucher $voucher)
    {
        return view('admin.vocher.show', compact('voucher'));
    }

    // Hiển thị form chỉnh sửa
    public function edit(Voucher $voucher)
    {
        $stores = Store::query()->orderBy('name')->get(['id', 'name']);
        return view('admin.vocher.edit', compact('voucher', 'stores'));
    }

    // Cập nhật voucher
    public function update(Request $request, Voucher $voucher)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:vouchers,code,' . $voucher->id,
            'description' => 'nullable|string',
            'type' => 'required|in:percentage,fixed',
            'discount_value' => 'required|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0',
            'min_order_value' => 'nullable|numeric|min:0',
            'quantity' => 'required|integer|min:0',
            'store_id' => 'nullable|exists:stores,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_active' => 'required|boolean',
        ]);

        $voucher->update($validated);
        $voucher->refresh();
        $this->voucherFollowerNotificationService->dispatchForVoucher($voucher);

        return redirect()->route('admin.vouchers.index')->with('success', 'Cập nhật voucher thành công.');
    }

    // Xóa voucher
    public function destroy(Voucher $voucher)
    {
        $voucher->delete();
        return redirect()->route('admin.vouchers.index')->with('success', 'Xóa voucher thành công.');
    }
}
