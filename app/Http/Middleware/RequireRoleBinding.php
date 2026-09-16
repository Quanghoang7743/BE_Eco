<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRoleBinding
{
    public function handle(Request $request, Closure $next, string $requiredRole, string $entityType = 'system', ?string $entityRouteParam = null): Response
    {
        $claims = (array) $request->attributes->get('rbac_claims', []);
        $bindings = (array) ($claims['rbac'] ?? []);

        $entityId = null;
        if ($entityType !== 'system') {
            $routeParam = $entityRouteParam ?: $entityType . '_id';
            $entityId = (string) $request->route($routeParam, '');
        }

        // Check whether the JWT payload has a role binding for the required context.
        foreach ($bindings as $binding) {
            $role = (string) ($binding['role'] ?? '');
            $ctxType = (string) ($binding['entity_type'] ?? '');
            $ctxId = isset($binding['entity_id']) ? (string) $binding['entity_id'] : null;

            if ($role !== $requiredRole || $ctxType !== $entityType) {
                continue;
            }

            // For non-system scopes, require exact entity_id match from route param.
            if ($entityType !== 'system' && $ctxId !== $entityId) {
                continue;
            }

            return $next($request);
        }

        return response()->json(['message' => 'Forbidden. Role binding is missing for this context.'], 403);
    }
}
