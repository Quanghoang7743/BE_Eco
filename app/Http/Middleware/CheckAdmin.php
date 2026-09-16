<?php

namespace App\Http\Middleware;

use App\Support\AdminCmsAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && AdminCmsAccess::isLocked($user)) {
            auth()->logout();

            return redirect()->route('login')->withErrors([
                'phone' => 'Tai khoan cua ban dang bi khoa.',
            ]);
        }

        if ($user && ! AdminCmsAccess::isCmsUser($user)) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
