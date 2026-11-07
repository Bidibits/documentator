<?php

use App\Exceptions\ApiError;
use App\Http\Middleware\GuardApiRequest;
use App\Http\Middleware\RequireSignIn;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // the API keeps a cookie session but has no CSRF token: GuardApiRequest takes its place
        $middleware->api(prepend: [
            GuardApiRequest::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
        ]);

        $middleware->alias(['documentator.signed-in' => RequireSignIn::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(ApiError::class);

        // API errors in the shape the app reads: {"error": "<code>"}
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $error = match (true) {
                $e instanceof NotFoundHttpException => new ApiError(404, 'route'),
                $e instanceof MethodNotAllowedHttpException => new ApiError(405, 'method'),
                $e instanceof PostTooLargeException => new ApiError(413, 'too_large'),
                $e instanceof HttpExceptionInterface => new ApiError($e->getStatusCode(), 'http_'.$e->getStatusCode()),
                default => new ApiError(500, 'server'),
            };

            return $error->render();
        });
    })->create();
