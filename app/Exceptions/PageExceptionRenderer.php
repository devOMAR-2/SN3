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
 * Only GET and HEAD page loads get it. /api/v1 keeps its JSON envelope (ApiExceptionRenderer); other
 * methods (a 419 or 429 on `PUT /locale`, say), requests that never reached the web middleware (no
 * route, so no session or locale), server errors in debug mode, and any failure while rendering the
 * page (the database being down, for instance) keep the default response.
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

        // Only page loads get the page. A failed PUT/POST/DELETE (419 CSRF, 429 throttle, …) keeps the
        // framework's answer: the URL of a non-GET request is no page to show or reload.
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $response;
        }

        if ($request->route() === null || ! $request->hasSession() || ($status >= 500 && config()->boolean('app.debug'))) {
            return $response;
        }

        try {
            // A route-binding 404 can be thrown before HandleInertiaRequests ran (SubstituteBindings sorts
            // earlier), so the shared props (locale, auth, workspace) are shared here explicitly.
            Inertia::share(app(HandleInertiaRequests::class)->share($request));
            $page = Inertia::render('Error', ['status' => $status])->toResponse($request);
        } catch (Throwable) {
            // The shared props need the database (auth, workspace); when it is down, or anything else
            // fails here, the framework's own error response is still a correct answer.
            return $response;
        }

        $page->setStatusCode($status);

        if ($response->headers->has('Retry-After')) {
            $page->headers->set('Retry-After', $response->headers->get('Retry-After'));
        }

        return $page;
    }
}
