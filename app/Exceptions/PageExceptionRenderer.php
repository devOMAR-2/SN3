<?php

namespace App\Exceptions;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Error answers of browser page routes as the Inertia `Error` page (CORE-006, ADR-026 §4), in the
 * user's language and inside the app's look, instead of the framework's HTML error views. A hidden
 * team, project or task and a missing one get the same generic "unavailable" page
 * (DESIGN_SYSTEM.md §6, §9.4): the page shows only the status, never the resource.
 *
 * /api/v1 keeps its JSON envelope (ApiExceptionRenderer). Requests that never reached the web
 * middleware (no route, so no session or locale) and server errors in debug mode keep the default
 * response.
 */
final class PageExceptionRenderer
{
    /** @var list<int> */
    public const STATUSES = [403, 404, 419, 429, 500, 503];

    public static function respond(Response $response, Throwable $e, Request $request): Response
    {
        $status = $response->getStatusCode();

        if (ApiExceptionRenderer::handles($request) || $request->expectsJson() || ! in_array($status, self::STATUSES, true)) {
            return $response;
        }

        if ($request->route() === null || ! $request->hasSession() || ($status >= 500 && config()->boolean('app.debug'))) {
            return $response;
        }

        // A route-binding 404 can be thrown before HandleInertiaRequests ran (SubstituteBindings sorts
        // earlier), so the shared props (locale, auth, workspace) are shared here explicitly.
        Inertia::share(app(HandleInertiaRequests::class)->share($request));
        $page = Inertia::render('Error', ['status' => $status])->toResponse($request);
        $page->setStatusCode($status);

        if ($response->headers->has('Retry-After')) {
            $page->headers->set('Retry-After', $response->headers->get('Retry-After'));
        }

        return $page;
    }
}
