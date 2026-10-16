# ADR-014 — Design foundation: tokens, fonts, localization and theme

- **Status:** Accepted
- **Date:** 2026-10-16
- **Task:** FND-006 (#7)
- **Decision inputs:** DESIGN_SYSTEM.md §2, §3, §7, §8, §11, §15; API_SPEC.md §1.1 and §3 (`PATCH /me` preferences); ARCHITECTURE.md §4 and §10; DATABASE.md §3 (`users.locale`); AGENTS.md §2 and §5

## Context

FND-006 delivers the SN3 design tokens, self-hosted fonts, the application shells, RTL/LTR and light/dark themes. FND-004 (authentication) builds its pages on this contract. DESIGN_SYSTEM.md is binding for visual values. This ADR records the engineering choices it leaves open.

## Decisions

### 1. Server-side locale resolution

`App\Http\Middleware\SetLocale` runs in the `web` group, before `HandleInertiaRequests`. It resolves the first supported value from:

1. the signed-in user's `locale` attribute (the column arrives with FND-004 and is read defensively);
2. a guest's choice stored in the session (key `locale`);
3. `Accept-Language`, in quality order, matching the primary subtag (`ar-SA` → `ar`); `q=0` entries are skipped;
4. `ar`.

Only `ar` and `en` are accepted (`App\Support\Locale`). Anything else falls through to the next source.

- **Spec note for the owner.** API_SPEC.md §1.1 says the language falls back to `ar`. DESIGN_SYSTEM.md §7 says a new user gets Arabic when the browser language starts with Arabic, and English otherwise. Both agree for Arabic and English browsers. They differ only for a browser that names neither, such as `fr`. This implementation follows API_SPEC and the FND-004 contract and returns `ar`. Changing it is one line in `SetLocale::resolve()`.
- `APP_LOCALE` keeps applying where there is no HTTP request (console commands, queued jobs).

Guests switch with `PUT /locale` (`locale=ar|en`). The route is CSRF-protected, throttled to 30 requests per minute, validated by a Form Request, and redirects back. It is a browser page route, not part of `/api/v1`, and it does not collide with API_SPEC paths. Signed-in users persist their language through `PATCH /me` (FND-004). Their saved value takes precedence over the session.

Inertia shares `locale` and `dir` with every page. `resources/js/types/inertia.d.ts` types them by augmenting `InertiaConfig.sharedPageProps` from `@inertiajs/core` with `SharedProps`.

### 2. Frontend i18n without a new dependency

`resources/js/i18n/index.ts` (about 100 lines) provides `useI18n()` → `{ t, locale, dir }`:

- dot-path lookup with `{name}` interpolation;
- numbers formatted with `Intl.NumberFormat`;
- a missing key returns the key itself and logs a dev-only warning.

`vue-i18n` was not added. The app needs no message compiler, pluralization DSL or runtime locale loading yet, and the server owns locale choice. Pluralization is not implemented. When a feature needs it, it should use `Intl.PluralRules`, because Arabic has six plural categories.

Dictionaries are split per namespace and per locale: `locales/<locale>/<namespace>.ts`, aggregated by `locales/<locale>/index.ts`. The English index exports `type Messages = typeof en`. Every Arabic file ends with `satisfies Messages['<namespace>']`, and the Arabic index ends with `satisfies Messages`. A missing Arabic key or namespace is therefore a `vue-tsc` error (TS1360 and TS2741), and so is an extra one (TS2353).

Both dictionaries are bundled. Lazy-loading per locale is possible later without changing the API.

**Intl tags.** Arabic uses `ar-u-nu-latn`: the Gregorian calendar (plain `ar`, not `ar-SA`, whose default calendar is Umm al-Qura) and Latin digits, consistent with LTR task keys such as `WEB-104`. This is a visible choice that DESIGN_SYSTEM.md does not fix. Changing it is one constant (`intlLocales`).

Server-side translations: the framework files were published with `php artisan lang:publish`. Complete MSA versions live in `lang/ar/{auth,pagination,passwords,validation}.php`. They include `validation.attributes` for common fields, so `:attribute` never shows an English field name inside an Arabic sentence. Counts are phrased to avoid number–noun agreement errors with interpolated values. A Pest test enforces key and placeholder parity between `lang/en` and `lang/ar`.

### 3. Tokens in Tailwind 4

`resources/css/tokens.css` defines the DESIGN_SYSTEM §2.1 values as CSS variables on `:root` and on `:root[data-theme='dark']`. `@theme inline` exposes them as Tailwind colours with the same names: `bg-bg`, `bg-surface`, `text-text-muted`, `border-border`, `bg-primary`, `text-primary-on`, `outline-focus`, and so on. A theme switch is therefore a variable swap with no rebuild.

The default Tailwind palette, radii, shadows and type scale are reset (`--color-*: initial` etc.), so views cannot fall back to arbitrary palette classes (DESIGN_SYSTEM.md §10). The replacements are:

- radii: `rounded-tiny`, `rounded-control`, `rounded-card`, `rounded-dialog`, `rounded-pill`;
- type scale: `text-caption`, `text-small`, `text-body`, `text-heading`, `text-title`, `text-display`;
- layout: `h-topbar`, `w-sidebar`, `w-rail`, `max-w-reading`;
- shadow: `shadow-panel`.

The 4px spacing scale and the 768px/1024px breakpoints are Tailwind's defaults (`md`, `lg`) and match §2.2 and §3.

Arabic text gets a line height of 1.65 for caption, small and body sizes (§2.2). It is applied by overriding the type-scale line-height variables on `[lang]:lang(ar)` elements.

Derived tokens, allowed by §2.1 ("adjust … if necessary without changing the visual direction"):

- `--border-control`: 80% `--text-subtle` mixed with `--border`. `--border` is a divider colour at 1.29:1 (light) and 1.56:1 (dark) against the surface. That is below the 3:1 that WCAG 1.4.11 requires to identify a form control. The mix reaches ≥3.3:1 in both themes.
- `--{info,success,warning,danger}-subtle`: 10% of the status colour over the surface. This gives the "pale background in light / dark tint in dark" treatment from §2.1.
- `--danger-on`: text on a solid danger button.

Motion uses `--motion-hover`, `--motion-panel` and `--motion-dialog` (120/180/220ms, ease-out). They become 0 under `prefers-reduced-motion`, which also neutralises animations and transitions globally.

#### Measured contrast (WCAG 2.x relative luminance)

| Pairing | Light | Dark | Needed |
|---|---|---|---|
| `--primary` text on `--surface` | 8.24:1 | 9.03:1 | 4.5 |
| `--primary` text on `--bg` | 7.82:1 | 10.25:1 | 4.5 |
| `--primary-on` on `--primary` (button) | 8.24:1 | 8.75:1 | 4.5 |
| `--primary-on` on `--primary-hover` | 11.49:1 | 10.98:1 | 4.5 |
| `--focus` ring on `--surface` | 4.62:1 | 11.34:1 | 3 |
| `--focus` ring on `--bg` | 4.39:1 | 12.87:1 | 3 |
| `--focus` ring on `--surface-hover` | 4.06:1 | 8.23:1 | 3 |
| `--text-subtle` on `--surface` / `--bg` | 4.76 / 4.52:1 | 6.30 / 7.14:1 | 4.5 |
| `--border-control` on `--surface` / `--bg` | 3.51 / 3.33:1 | 4.92 / 5.58:1 | 3 |
| Status text on its `-subtle` background (lowest) | 5.45:1 (danger) | 7.00:1 (danger) | 4.5 |
| `--danger-on` on `--danger` | 6.47:1 | 8.24:1 | 4.5 |

`--text-subtle` on `--surface-muted` is only 4.34:1 in light mode. Don't use that pairing for text.

The focus ring is drawn with a 2px offset, so it sits on the page background rather than on the button fill.

### 4. Self-hosted fonts

| Package | Version | License | Use |
|---|---|---|---|
| `@fontsource-variable/space-grotesk` | 5.3.0 | OFL-1.1 | Variable wght 300–700; latin, latin-ext and vietnamese subsets, each behind `unicode-range` |
| `@fontsource/ibm-plex-sans-arabic` | 5.3.0 | OFL-1.1 | Arabic subset only, weights 400/500/600/700 |

For Plex, `resources/css/fonts.css` declares its own `@font-face` rules with the package's Arabic `unicode-range` and does not import the package CSS. Latin characters on Arabic pages (`SN3`, task keys, URLs) therefore always render in Space Grotesk, and Arabic glyphs always render in Plex.

All rules use `font-display: swap`. Vite fingerprints the files and serves them from `/build/assets`, so nothing reaches a third-party CDN at runtime (DS-ACC-012). Browsers download a subset only when the page contains a character in its range. In practice:

- an English page loads Space Grotesk latin (22 KB), plus Plex 500/600 (about 91 KB) for the Arabic brand name and the "العربية" switcher label;
- an Arabic page loads Space Grotesk latin plus Plex 400/500/600 (about 156 KB in total).

### 5. Theme

The three modes are `system` (default), `light` and `dark`. An inline script in `resources/views/app.blade.php` runs before the stylesheet. It reads the preference in this order:

1. a server-rendered `data-theme-preference` attribute, which is FND-004's hook for the signed-in user's saved theme;
2. `localStorage['sn3.theme']`, wrapped in try/catch;
3. `system`.

It then sets `data-theme="light|dark"` on `<html>`, so the wrong theme never flashes (DS-ACC-004).

`resources/js/Composables/useTheme.ts` keeps the attribute in sync. It follows OS changes while the preference is `system`, syncs between tabs through the `storage` event, and exposes `setPreference(value, { persist })`. `ThemeToggle` emits `change`, so a signed-in shell can also `PATCH /me`.

There is no `prefers-color-scheme` CSS fallback for disabled JavaScript, because the Inertia app cannot render without JavaScript.

The script supports a CSP nonce through `Vite::cspNonce()` when one is configured.

### 6. Components and layout primitives

- **Directory.** Components live in `resources/js/Components/ui`, with capital C. This matches the Inertia `Pages`/`Layouts` convention already in the repository and the ARCHITECTURE.md §4 tree. DESIGN_SYSTEM.md §4 writes the path in lower case, so the two documents disagree on casing only.
- **Icons.** `@lucide/vue` 1.48.0 (ISC), with per-icon imports that are tree-shaken. `lucide-vue-next` is deprecated upstream in favour of this package.
- **Mobile drawer.** It uses the native `<dialog>` element with `showModal()`, which gives a focus trap, an inert background and Escape for free. Focus is restored to the menu button explicitly.
- **Reka UI.** Not added yet. Nothing in FND-006 needs a headless menu or combobox. It should be introduced, pinned, by the first task that does: menus, combobox or select.
- **Responsive shell.**
  - `AppLayout` uses a 248px sidebar from 1024px.
  - From 768px to 1023px it shows a 64px rail, and the `navigation` slot receives `collapsed: true`.
  - Below 768px it shows the modal drawer, with the language and theme controls moved into it.
  - Manual collapse of the desktop sidebar is left to the task that adds real navigation (CORE-006).
- **Logical properties only.** No `ml/mr/pl/pr/left/right/text-left/text-right` utilities are used in `resources/js`. Email, URL and phone inputs are forced LTR (§7).

## Consequences

- FND-004 builds auth pages with `GuestLayout`, `TextField`, `AppButton` and `AppAlert`. It adds `users.locale` and `PATCH /me`, which make the user preference win automatically. It can render `data-theme-preference` for signed-in users.
- New UI strings are added as a namespace file in both `locales/en` and `locales/ar`, plus one index line each. Typecheck enforces Arabic completeness.
- The Pest suite covers locale precedence, the switch route (validation, CSRF, method, throttling), shared props, `<html lang dir>`, the pre-paint theme script and server catalogue parity.
- Bundle impact, measured with `vite build` on `origin/dev` and on this branch with a clean `storage/framework/views`:
  - CSS grows from 4.86 to 27.99 KB (from 1.67 to 6.58 KB gzip);
  - the app JS grows from 255.78 to 261.80 KB (from 87.68 to 90.27 KB gzip);
  - the Welcome chunk, which now holds the shell, components and icons, grows from 0.42 to 11.62 KB (4.62 KB gzip);
  - the font files above are fetched lazily, per unicode-range.
