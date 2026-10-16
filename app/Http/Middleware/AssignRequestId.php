<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation ID (API_SPEC.md §1 `request_id`, ARCHITECTURE.md §11.2).
 *
 * An incoming X-Request-Id (e.g. set by the reverse proxy) is reused only when it matches a
 * strict pattern, so a client cannot inject line breaks or markup into logs. Otherwise a new
 * `req_<ULID>` is generated. The ID is echoed in the response header, stored on the request
 * as the `request_id` attribute, and added to the log Context, which Laravel also carries
 * into jobs dispatched during the request. It is a correlation aid only, never an
 * authorization input.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public const ATTRIBUTE = 'request_id';

    public const PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]{7,63}\z/';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);

        $requestId = is_string($incoming) && preg_match(self::PATTERN, $incoming) === 1
            ? $incoming
            : 'req_'.Str::ulid();

        $request->attributes->set(self::ATTRIBUTE, $requestId);
        Context::add(self::ATTRIBUTE, $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
