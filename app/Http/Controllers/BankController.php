<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBankRequest;
use App\Http\Requests\UpdateBankRequest;
use App\Models\Bank;
use App\Services\BankService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class BankController extends Controller
{
    private const ADMIN_INDEX_CACHE_TTL_MINUTES = 10;
    private const DATATABLE_CACHE_TTL_MINUTES = 5;

    public function index(Request $request)
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 20;
        $cacheKey = BankService::makeCacheKey(sprintf('banks.admin.index.page.%d.per.%d', $page, $perPage));

        $banks = Cache::remember($cacheKey, now()->addMinutes(self::ADMIN_INDEX_CACHE_TTL_MINUTES), function () use ($perPage, $page) {
            return Bank::orderBy('name')->paginate($perPage, ['*'], 'page', $page);
        });

        return view('admin.banks.index', compact('banks'));
    }

    public function datatable(Request $request)
    {
        $columns = [
            1 => 'id',
            2 => 'code',
            3 => 'name',
            4 => 'is_active',
        ];

        $search = (string) $request->input('search.value', '');
        $orderField = $columns[$request->input('order.0.column', 2)] ?? 'name';
        $orderDir = $request->input('order.0.dir', 'asc') === 'desc' ? 'desc' : 'asc';
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 20);

        $cachePayload = [
            'search' => $search,
            'orderField' => $orderField,
            'orderDir' => $orderDir,
            'start' => $start,
            'length' => $length,
        ];
        $cacheKey = BankService::makeCacheKey('banks.admin.datatable.' . sha1(json_encode($cachePayload)));

        $cached = Cache::remember($cacheKey, now()->addMinutes(self::DATATABLE_CACHE_TTL_MINUTES), function () use ($search, $orderField, $orderDir, $start, $length) {
            $query = Bank::query();
            $recordsTotal = $query->count();

            if ($search !== '') {
                $query->where(function ($query) use ($search) {
                    $query->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            }

            $recordsFiltered = $query->count();

            $banks = $query->orderBy($orderField, $orderDir)
                ->skip($start)
                ->take($length)
                ->get();

            $data = $banks->map(function (Bank $bank) {
                return [
                    'checkbox' => '<input type="checkbox" class="bank-row-checkbox" value="' . $bank->id . '">',
                    'id' => $bank->id,
                    'code' => $bank->code,
                    'name' => $bank->name,
                    'is_active' => $bank->is_active
                        ? '<span class="badge bg-success">Active</span>'
                        : '<span class="badge bg-secondary">Inactive</span>',
                    'action' => '<a href="' . route('admin.banks.edit', $bank) . '" class="btn btn-sm btn-primary">Sửa</a>',
                ];
            });

            return [
                'recordsTotal' => $recordsTotal,
                'recordsFiltered' => $recordsFiltered,
                'data' => $data,
            ];
        });

        return response()->json([
            'draw' => (int) $request->input('draw', 1),
            'recordsTotal' => $cached['recordsTotal'],
            'recordsFiltered' => $cached['recordsFiltered'],
            'data' => $cached['data'],
        ]);
    }

    public function create()
    {
        return view('admin.banks.create');
    }

    public function store(StoreBankRequest $request)
    {
        $bank = Bank::create([
            'code' => strtoupper(trim($request->code)),
            'name' => trim($request->name),
            'is_active' => $request->boolean('is_active', true),
        ]);

        if ($request->hasFile('image')) {
            $bank->image = $request->file('image')->store('banks', 'public');
            $bank->save();
        }

        return redirect()->route('admin.banks.index')->with('success', 'Ngân hàng đã được tạo.');
    }

    public function edit(Bank $bank)
    {
        return view('admin.banks.edit', compact('bank'));
    }

    public function update(UpdateBankRequest $request, Bank $bank)
    {
        $bank->code = strtoupper(trim($request->code));
        $bank->name = trim($request->name);
        $bank->is_active = $request->boolean('is_active', true);

        if ($request->hasFile('image')) {
            $bank->image = $request->file('image')->store('banks', 'public');
            if ($bank->image && Storage::disk('public')->exists($bank->image)) {
                Storage::disk('public')->delete($bank->image);
            }
        }   

        $bank->save();

        return redirect()->route('admin.banks.index')->with('success', 'Ngân hàng đã được cập nhật.');
    }

    public function destroy(Request $request, Bank $bank)
    {
        $bank->delete();

        if ($request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Ngân hàng đã được xóa.',
            ]);
        }

        return redirect()->route('admin.banks.index')->with('success', 'Ngân hàng đã được xóa.');
    }

    public function destroyMultiple(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|distinct|exists:banks,id',
        ]);

        $deleted = Bank::whereIn('id', $request->input('ids'))->delete();
        BankService::clearBankCaches();

        return response()->json([
            'success' => true,
            'message' => "Đã xóa {$deleted} ngân hàng.",
        ]);
    }
}
