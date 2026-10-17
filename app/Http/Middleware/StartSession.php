<?php

namespace App\Http\Middleware;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;

/**
 * Laravel's StartSession, except that a GET to the JSON API (`api.*` routes) is never remembered as
 * the session's "previous URL". That URL is stored in the session payload, and API paths and
 * queries can carry credentials: invitation tokens (`/api/v1/invitations/<token>/preview`, ADR-025
 * §2) and signed export links (ADR-024). Nothing redirects back to an API URL, so nothing is lost.
 */
class StartSession extends BaseStartSession
{
    /**
     * @param  Session  $session
     */
    protected function storeCurrentUrl(Request $request, $session): void
    {
        if ($request->routeIs('api.*')) {
            return;
        }

        parent::storeCurrentUrl($request, $session);
    }
}
