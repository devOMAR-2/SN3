<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the interface locale for every web request.
 *
 * Precedence: the signed-in user's saved `locale` → a guest choice stored in the session
 * (`PUT /locale`) → the browser's `Accept-Language` → Arabic (API_SPEC.md §1.1).
 * Only `ar` and `en` are ever applied; any other value falls through to the next source.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolve($request)->value);

        return $next($request);
    }

    public function resolve(Request $request): Locale
    {
        // The `locale` column is added by FND-004; read it defensively until then.
        return Locale::fromValue($request->user()?->getAttribute('locale'))
            ?? ($request->hasSession() ? Locale::fromValue($request->session()->get(Locale::SESSION_KEY)) : null)
            ?? $this->fromAcceptLanguage($request)
            ?? Locale::DEFAULT;
    }

    /**
     * First supported primary language subtag in quality order: `ar-SA` → ar, `en-GB` → en.
     * Entries with `q=0` mean "not acceptable" and are skipped.
     */
    private function fromAcceptLanguage(Request $request): ?Locale
    {
        $header = $request->headers->get('Accept-Language');

        if ($header === null || $header === '') {
            return null;
        }

        foreach (AcceptHeader::fromString($header)->all() as $item) {
            if ($item->getQuality() <= 0) {
                continue;
            }

            $primary = strtolower(strtok($item->getValue(), '-_') ?: '');

            if ($locale = Locale::fromValue($primary)) {
                return $locale;
            }
        }

        return null;
    }
}
