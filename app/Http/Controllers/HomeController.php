<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/`: the public welcome page for guests, a minimal signed-in home otherwise.
 * Workspace navigation (teams, my work) arrives with CORE-006.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render($request->user() === null ? 'Welcome' : 'Home');
    }
}
