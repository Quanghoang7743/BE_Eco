<?php

namespace App\Http\Controllers;

use App\Services\StoreAdmin\StoreAdminStaffAccess;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    protected function resolveStoreAdminAccessContext(Request $request): array
    {
        [$user, $store, $error] = app(StoreAdminStaffAccess::class)->resolveStoreContext($request);

        return [
            'user' => $user,
            'store' => $store,
            'error' => $error,
        ];
    }
}
