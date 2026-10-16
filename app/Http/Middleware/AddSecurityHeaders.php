<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every response, whatever serves the app (Apache image, a host
 * web server or `php artisan serve`): no framing by other sites (clickjacking), no MIME sniffing.
 *
 * Only the `frame-ancestors` directive of Content-Security-Policy is set for now. The full,
 * nonce-based CSP that API_SPEC.md §13 asks for is a separate change (app.blade.php already
 * renders Vite's CSP nonce on its inline script when one is set).
 */
class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $response->headers->set('X-Content-Type-Options', 'nosniff', false);

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        }

        return $response;
    }
}
