@php($locale = \App\Support\Locale::fromValue(app()->getLocale()) ?? \App\Support\Locale::DEFAULT)
<!DOCTYPE html>
@php($themeAttribute = auth()->user() instanceof \App\Models\User ? ' data-theme-preference="'.e(auth()->user()->theme()).'"' : '')
<html lang="{{ $locale->value }}" dir="{{ $locale->direction() }}"{!! $themeAttribute !!}>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">

        {{-- Applies the theme before first paint (DESIGN_SYSTEM.md §11, DS-ACC-004). Keep in sync with resources/js/Composables/useTheme.ts. --}}
        <script @if ($nonce = Vite::cspNonce()) nonce="{{ $nonce }}" @endif>
            (function () {
                var root = document.documentElement;
                var valid = { system: 1, light: 1, dark: 1 };
                var preference = root.getAttribute('data-theme-preference');
                if (!valid[preference]) {
                    try { preference = window.localStorage.getItem('sn3.theme'); } catch (e) { preference = null; }
                }
                if (!valid[preference]) preference = 'system';
                var dark = preference === 'dark' || (preference === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                root.setAttribute('data-theme-preference', preference);
                root.setAttribute('data-theme', dark ? 'dark' : 'light');
            })();
        </script>

        <title inertia>{{ config('app.name', 'SN3') }}</title>

        @vite('resources/js/app.ts')
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
