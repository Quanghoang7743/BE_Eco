<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserRoleBinding;
use App\Services\Auth\JwtTokenService;
use App\Services\Rbac\RoleBindingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class RbacAuthController extends Controller
{
    public function __construct(
        private readonly JwtTokenService $jwtTokenService,
        private readonly RoleBindingService $roleBindingService
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        $user = User::query()->where('email', $validated['email'])->first();
        if (!$user || !Hash::check($validated['password'], (string) $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if (! $user->isActiveForAuth()) {
            return response()->json(['message' => 'Account is inactive.'], 403);
        }

        $bindings = UserRoleBinding::query()
            ->with('role:id,name')
            ->where('user_id', $user->id)
            ->get()
            ->map(fn (UserRoleBinding $binding) => [
                'role' => (string) optional($binding->role)->name,
                'entity_type' => $binding->entity_type,
                'entity_id' => $binding->entity_id,
            ])
            ->values()
            ->all();

        $token = $this->jwtTokenService->issue([
            'sub' => (string) $user->id,
            'email' => (string) $user->email,
            'rbac' => $bindings,
        ]);

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token,
            'expires_in' => 3600,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
            ],
            'rbac' => $bindings,
        ]);
    }

    public function assignRole(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => ['required'],
            'role' => ['required', 'string', 'max:100'],
            'entity_type' => ['required', 'string', 'max:50'],
            'entity_id' => ['nullable'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        $binding = $this->roleBindingService->assignRoleToUser(
            $validated['user_id'],
            $validated['role'],
            $validated['entity_type'],
            $validated['entity_id'] ?? null
        );

        return response()->json([
            'data' => [
                'id' => $binding->id,
                'user_id' => $binding->user_id,
                'role_id' => $binding->role_id,
                'entity_type' => $binding->entity_type,
                'entity_id' => $binding->entity_id,
            ],
        ]);
    }

    public function checkStoreContext(Request $request, string $store_id): JsonResponse
    {
        $claims = (array) $request->attributes->get('rbac_claims', []);

        return response()->json([
            'message' => 'Authorized for store context.',
            'store_id' => $store_id,
            'subject' => $claims['sub'] ?? null,
        ]);
    }
}
