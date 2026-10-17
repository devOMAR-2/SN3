<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\PageExceptionRenderer;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Support\Concurrency\StaleVersionException;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Exceptions\ResourceLockedException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            Route::group([], base_path('routes/health.php'));

            // JSON API on the session (web) stack: same-origin cookies + CSRF (API_SPEC.md §0.3, ADR-016).
            Route::middleware('web')
                ->prefix('api/v1')
                ->name('api.')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global and first, so health probes, 404s and errors are correlated too (ADR-015).
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(AddSecurityHeaders::class);

        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // After StartSession so signed-in users are limited per account, guests per IP (ADR-016).
            'throttle:requests',
        ]);

        // Resolve the language before auth can reject a request, so 401/403 messages are localized too.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, SetLocale::class);

        // Idempotency-Key for retry-safe creates (API_SPEC.md §1.4). After bindings, so the stored
        // record knows its team; route throttles listed before it count retries too.
        $middleware->alias(['idempotent' => EnforceIdempotency::class]);
        $middleware->appendToPriorityList(SubstituteBindings::class, EnforceIdempotency::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // /api/v1 always answers with the API_SPEC.md §1.2 error envelope.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => ApiExceptionRenderer::handles($request) || $request->expectsJson(),
        );
        $exceptions->render(ApiExceptionRenderer::render(...));
        // Browser pages answer errors with the Inertia `Error` page (CORE-006, ADR-026 §4).
        $exceptions->respond(PageExceptionRenderer::respond(...));

        // Expected outcomes of valid requests (409, 412, 423), not errors worth reporting.
        $exceptions->dontReport([
            DomainConflictException::class,
            ResourceLockedException::class,
            StaleVersionException::class,
        ]);
    })->create();
