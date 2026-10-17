<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * `/invite` (CORE-002, ADR-025 §4): the page an invitation link opens, for guests and signed-in users
 * alike. The token stays in the URL fragment (`/invite#<token>`), so the server never receives it here;
 * the page reads it and calls the preview and accept endpoints.
 */
class InvitationPageController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Invitations/Accept');
    }
}
