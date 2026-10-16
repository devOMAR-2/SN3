<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLocaleRequest;
use App\Support\Locale;
use Illuminate\Http\RedirectResponse;

/**
 * Stores a guest's interface language in the session (`PUT /locale`).
 *
 * Signed-in users persist their language through `PATCH /me` (FND-004); their saved
 * preference takes precedence over this session value in SetLocale.
 */
class LocaleController extends Controller
{
    public function update(UpdateLocaleRequest $request): RedirectResponse
    {
        $request->session()->put(Locale::SESSION_KEY, $request->locale()->value);

        return back();
    }
}
