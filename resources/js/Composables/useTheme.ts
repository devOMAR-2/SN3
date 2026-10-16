/**
 * Theme preference: `system` (default, follows `prefers-color-scheme`), `light` or `dark`
 * (DESIGN_SYSTEM.md §11, DS-ACC-004).
 *
 * The inline script in resources/views/app.blade.php applies the same rules before first paint; keep the
 * storage key and attribute names in sync with it. Guests persist their choice in localStorage. A signed-in
 * user's saved preference (PATCH /me, FND-004) can be applied with `setPreference(value, { persist: false })`,
 * or rendered server-side as `data-theme-preference` on <html>, which the inline script reads first.
 */
import { computed, readonly, ref, watchEffect, type ComputedRef, type DeepReadonly, type Ref } from 'vue';

export type ThemePreference = 'system' | 'light' | 'dark';
export type ResolvedTheme = 'light' | 'dark';

export const themePreferences: readonly ThemePreference[] = ['system', 'light', 'dark'];
export const THEME_STORAGE_KEY = 'sn3.theme';

const DARK_QUERY = '(prefers-color-scheme: dark)';

export function isThemePreference(value: unknown): value is ThemePreference {
  return value === 'system' || value === 'light' || value === 'dark';
}

const preference = ref<ThemePreference>('system');
const systemPrefersDark = ref(false);
const resolved = computed<ResolvedTheme>(() =>
  preference.value === 'system' ? (systemPrefersDark.value ? 'dark' : 'light') : preference.value,
);

let initialized = false;

function storePreference(value: ThemePreference): void {
  try {
    if (value === 'system') {
      window.localStorage.removeItem(THEME_STORAGE_KEY);
    } else {
      window.localStorage.setItem(THEME_STORAGE_KEY, value);
    }
  } catch {
    // Storage can be unavailable (private mode, blocked site data); the choice then lasts for this page only.
  }
}

/** Starts tracking the OS preference and applies the theme to <html>. Safe to call more than once. */
export function initTheme(): void {
  if (initialized || typeof window === 'undefined') {
    return;
  }

  initialized = true;

  const root = document.documentElement;
  const initial = root.getAttribute('data-theme-preference');
  preference.value = isThemePreference(initial) ? initial : 'system';

  const media = window.matchMedia(DARK_QUERY);
  systemPrefersDark.value = media.matches;
  media.addEventListener('change', (event) => {
    systemPrefersDark.value = event.matches;
  });

  // Keep several open tabs in step.
  window.addEventListener('storage', (event) => {
    if (event.key === THEME_STORAGE_KEY) {
      preference.value = isThemePreference(event.newValue) ? event.newValue : 'system';
    }
  });

  watchEffect(() => {
    root.setAttribute('data-theme-preference', preference.value);
    root.setAttribute('data-theme', resolved.value);
  });
}

export function setThemePreference(value: ThemePreference, options: { persist?: boolean } = {}): void {
  initTheme();
  preference.value = value;

  if (options.persist ?? true) {
    storePreference(value);
  }
}

export function useTheme(): {
  preference: DeepReadonly<Ref<ThemePreference>>;
  resolved: ComputedRef<ResolvedTheme>;
  setPreference: typeof setThemePreference;
} {
  initTheme();

  return {
    preference: readonly(preference),
    resolved,
    setPreference: setThemePreference,
  };
}
