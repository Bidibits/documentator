<?php

use App\Exceptions\ApiError;
use App\Http\Middleware\GuardApiRequest;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequireSignIn;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Validation\ValidationException;
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

        $middleware->alias(['documentator.signed-in' => RequireSignIn::class, 'documentator.can' => RequirePermission::class]);

        // a stranger gets 401 before any record is looked up, so a 404 can never tell them which ids exist
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: RequirePermission::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: RequireSignIn::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(ApiError::class);

        // API errors in the shape the app reads: {"error": "<code>"}
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $error = match (true) {
                // {"error": "invalid", "field": "title", "message": "..."}: the first thing wrong with the form
                $e instanceof ValidationException => new ApiError(422, 'invalid', [
                    'field' => explode('.', (string) array_key_first($e->errors()))[0],
                    'message' => (string) collect($e->errors())->flatten()->first(),
                ]),
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => new ApiError(404, 'route'),
                $e instanceof MethodNotAllowedHttpException => new ApiError(405, 'method'),
                $e instanceof PostTooLargeException => new ApiError(413, 'too_large'),
                $e instanceof HttpExceptionInterface => new ApiError($e->getStatusCode(), 'http_'.$e->getStatusCode()),
                default => new ApiError(500, 'server'),
            };

            return $error->render();
        });
    })->create();
