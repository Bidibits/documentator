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
        if (SessionUser::resolve($request) === null) {
            // the app's only status-based branch: 401 shows the sign-in screen
            throw new ApiError(401, 'signin');
        }

        return $next($request);
    }
}
