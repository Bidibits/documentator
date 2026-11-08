<?php

namespace App\Http\Middleware;

use App\Auth\Access;
use App\Auth\SessionUser;
use App\Exceptions\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * documentator.can:<permission>: the person must be signed in (401 otherwise) and their role must hold the permission
 * (403 "forbidden" otherwise). Runs before route-model binding, so a person who may not see something cannot tell from
 * a 404 whether it exists.
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = SessionUser::resolve($request);
        if ($user === null) {
            throw new ApiError(401, 'signin');
        }
        if (! Access::can($user, $permission)) {
            throw ApiError::forbidden();
        }

        return $next($request);
    }
}
