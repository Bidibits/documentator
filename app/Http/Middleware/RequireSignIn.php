<?php

namespace App\Http\Middleware;

use App\Auth\SessionUser;
use App\Exceptions\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSignIn
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = SessionUser::resolve($request);
        if ($user === null) {
            // the app's only status-based branch: 401 shows the sign-in screen
            throw new ApiError(401, 'signin');
        }
        // an account an administrator just made, or whose password they set, must choose its own password first
        if ($user->must_change_password && ! $request->is('api/password')) {
            throw new ApiError(403, 'password_change_required');
        }

        return $next($request);
    }
}
