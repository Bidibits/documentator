<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the app itself can call the API with the signed-in person's cookie.
 *
 * Another site cannot add the X-Documentator header without a CORS preflight, which this server never answers, and
 * a browser always sends Origin on such requests. Together with SameSite=Strict this replaces a CSRF token, which
 * the app does not send.
 */
class GuardApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->header('X-Documentator') !== '1') {
            throw ApiError::forbidden('header');
        }

        $origin = $request->header('Origin');
        if ($origin !== null && $origin !== $request->getSchemeAndHttpHost()) {
            throw ApiError::forbidden('origin');
        }

        return $next($request);
    }
}
