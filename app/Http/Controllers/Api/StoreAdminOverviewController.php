<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StoreAdmin\StoreAdminPageOverviewService;
use App\Services\StoreAdmin\StoreAdminStaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreAdminOverviewController extends Controller
{
    public function __construct(
        private readonly StoreAdminStaffAccess $access,
        private readonly StoreAdminPageOverviewService $overview,
    ) {
    }

    public function dashboard(Request $request): JsonResponse
    {
        return $this->withStore($request, fn ($store) => $this->overview->dashboardOverview($store, $request));
    }

    public function orders(Request $request): JsonResponse
    {
        return $this->withStore($request, fn ($store) => $this->overview->ordersOverview($store, $request));
    }

    public function menu(Request $request): JsonResponse
    {
        return $this->withStore($request, fn ($store) => $this->overview->menuOverview($store, $request));
    }

    public function reviews(Request $request): JsonResponse
    {
        return $this->withStore($request, fn ($store) => $this->overview->reviewsOverview($store, $request));
    }

    public function sla(Request $request): JsonResponse
    {
        return $this->withStore($request, fn ($store) => $this->overview->slaOverview($store, $request));
    }

    public function profile(Request $request): JsonResponse
    {
        return $this->withStore($request, fn ($store) => $this->overview->profileOverview($store));
    }

    private function withStore(Request $request, callable $resolver): JsonResponse
    {
        [$user, $store, $error] = $this->access->resolveStoreContext($request);
        if ($error) {
            return $error;
        }

        $result = $resolver($store);
        $partialErrors = $result['partial_errors'] ?? [];

        return response()->json([
            'data' => $result['data'] ?? $result,
            'meta' => [
                'authenticated' => $user !== null,
                'has_store' => $store !== null,
                'request_at' => now()->toIso8601String(),
                'partial_errors' => $partialErrors,
            ],
            'errors' => [],
        ]);
    }
}
