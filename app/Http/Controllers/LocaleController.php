<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLocaleRequest;
use App\Support\Locale;
use Illuminate\Http\RedirectResponse;

/**
 * Switches the interface language (`PUT /locale`).
 *
 * Guests: stored in the session. Signed-in users: also saved to their profile (`users.locale`,
 * the same field `PATCH /me` writes), because SetLocale gives the saved preference precedence.
 */
class LocaleController extends Controller
{
    public function update(UpdateLocaleRequest $request): RedirectResponse
    {
        $locale = $request->locale();

        $request->session()->put(Locale::SESSION_KEY, $locale->value);
        $request->user()?->forceFill(['locale' => $locale->value])->save();

        return back();
    }
}
