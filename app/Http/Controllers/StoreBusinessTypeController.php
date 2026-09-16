<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\StoreBusinessType;
use App\Services\StoreBusinessTypeService;
use App\Support\AdminCmsAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class StoreBusinessTypeController extends Controller
{
    public function __construct(private readonly StoreBusinessTypeService $businessTypeService)
    {
    }

    public function index(Request $request)
    {
        $this->ensureSuperAdmin();

        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:all,active,inactive'],
        ]);

        $keyword = trim((string) ($validated['keyword'] ?? ''));
        $status = (string) ($validated['status'] ?? 'all');

        $businessTypes = StoreBusinessType::query()
            ->withCount('stores')
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($scope) use ($keyword): void {
                    $scope->where('name', 'like', '%' . $keyword . '%')
                        ->orWhere('slug', 'like', '%' . $keyword . '%');
                });
            })
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.store_business_types.index', compact('businessTypes', 'keyword', 'status'));
    }

    public function create()
    {
        $this->ensureSuperAdmin();

        return view('admin.store_business_types.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureSuperAdmin();

        $payload = $this->validatePayload($request);
        $slugSource = trim((string) ($payload['slug'] ?? '')) ?: $payload['name'];

        StoreBusinessType::query()->create([
            'name' => trim($payload['name']),
            'slug' => $this->businessTypeService->uniqueSlug($slugSource),
            'icon_url' => $this->nullableString($payload['icon_url'] ?? null),
            'is_active' => $request->boolean('is_active', true),
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
        ]);

        return redirect()
            ->route('admin.store-business-types.index')
            ->with('success', 'Da tao loai hinh kinh doanh.');
    }

    public function edit(StoreBusinessType $storeBusinessType)
    {
        $this->ensureSuperAdmin();

        return view('admin.store_business_types.edit', compact('storeBusinessType'));
    }

    public function update(Request $request, StoreBusinessType $storeBusinessType): RedirectResponse
    {
        $this->ensureSuperAdmin();

        $payload = $this->validatePayload($request, (int) $storeBusinessType->id);
        $slugSource = trim((string) ($payload['slug'] ?? '')) ?: $payload['name'];

        $storeBusinessType->update([
            'name' => trim($payload['name']),
            'slug' => $this->businessTypeService->uniqueSlug($slugSource, (int) $storeBusinessType->id),
            'icon_url' => $this->nullableString($payload['icon_url'] ?? null),
            'is_active' => $request->boolean('is_active', true),
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
        ]);

        return redirect()
            ->route('admin.store-business-types.index')
            ->with('success', 'Da cap nhat loai hinh kinh doanh.');
    }

    public function destroy(StoreBusinessType $storeBusinessType): RedirectResponse
    {
        $this->ensureSuperAdmin();

        $linkedStoresCount = $this->linkedStoresCount($storeBusinessType);
        if ($linkedStoresCount > 0) {
            return back()
                ->withErrors(['store_business_type' => "Loai hinh nay dang duoc su dung boi {$linkedStoresCount} cua hang, khong the xoa."]);
        }

        $storeBusinessType->delete();

        return redirect()
            ->route('admin.store-business-types.index')
            ->with('success', 'Da xoa loai hinh kinh doanh.');
    }

    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('store_business_types', 'name')->ignore($ignoreId),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('store_business_types', 'slug')->ignore($ignoreId),
            ],
            'icon_url' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function linkedStoresCount(StoreBusinessType $businessType): int
    {
        if (! Schema::hasTable('stores')) {
            return 0;
        }

        $hasBusinessTypeId = Schema::hasColumn('stores', 'business_type_id');
        $hasBusinessTypeName = Schema::hasColumn('stores', 'business_type');

        if (! $hasBusinessTypeId && ! $hasBusinessTypeName) {
            return 0;
        }

        $query = Store::query();
        $query->where(function ($scope) use ($businessType, $hasBusinessTypeId, $hasBusinessTypeName): void {
            if ($hasBusinessTypeId) {
                $scope->where('business_type_id', $businessType->id);
            }

            if ($hasBusinessTypeName) {
                $method = $hasBusinessTypeId ? 'orWhere' : 'where';
                $scope->{$method}('business_type', $businessType->name);
            }
        });

        return $query->count();
    }

    private function ensureSuperAdmin(): void
    {
        abort_unless(AdminCmsAccess::isSuperAdmin(), 403, 'Chi super_admin moi co quyen quan ly loai hinh kinh doanh.');
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
