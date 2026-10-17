<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/inbox` (API_SPEC.md §1.1, DESIGN_SYSTEM.md §3.4 "Inbox"; CORE-009, ADR-029 §6). The page reads
 * the inbox from `/api/v1/notifications`, which applies the read-time access rules (Inbox); the page
 * itself carries no notification data.
 */
class InboxPageController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Inbox/Index');
    }
}
