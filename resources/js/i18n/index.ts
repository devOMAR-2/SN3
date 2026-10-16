/**
 * Frontend localization (DESIGN_SYSTEM.md §7, FR-I18N-001..003).
 *
 * The server resolves the locale (App\Http\Middleware\SetLocale) and shares it as the `locale`/`dir`
 * Inertia props; this module only reads it. Dictionaries live in ./locales/<locale>/<namespace>.ts.
 */
import { usePage } from '@inertiajs/vue3';
import { computed, watchEffect, type ComputedRef } from 'vue';
import ar from './locales/ar';
import en, { type Messages } from './locales/en';

export type Locale = 'ar' | 'en';
export type Direction = 'rtl' | 'ltr';
export type TranslationParams = Record<string, string | number>;

export const locales: readonly Locale[] = ['ar', 'en'];
export const defaultLocale: Locale = 'ar';
export const directions: Readonly<Record<Locale, Direction>> = { ar: 'rtl', en: 'ltr' };

/** Language names in their own language, for the language switcher. */
export const localeNames: Readonly<Record<Locale, string>> = { ar: 'العربية', en: 'English' };

/**
 * BCP 47 tags handed to `Intl`. Arabic uses the Gregorian calendar (plain `ar`, not `ar-SA`, whose default
 * is the Umm al-Qura calendar) and Latin digits, matching task keys and other LTR technical identifiers.
 */
export const intlLocales: Readonly<Record<Locale, string>> = { ar: 'ar-u-nu-latn', en: 'en' };

const dictionaries: Readonly<Record<Locale, Messages>> = { ar, en };

export function isLocale(value: unknown): value is Locale {
  return value === 'ar' || value === 'en';
}

function lookup(dictionary: Messages, key: string): unknown {
  let node: unknown = dictionary;

  for (const segment of key.split('.')) {
    if (node === null || typeof node !== 'object' || !Object.hasOwn(node, segment)) {
      return undefined;
    }

    node = (node as Record<string, unknown>)[segment];
  }

  return node;
}

/**
 * Looks up `key` (dot path, e.g. `layout.open_navigation`) and replaces `{name}` placeholders.
 * Numbers are formatted with `Intl.NumberFormat` for the locale. A missing key returns the key itself.
 */
export function translate(locale: Locale, key: string, params?: TranslationParams): string {
  const message = lookup(dictionaries[locale], key);

  if (typeof message !== 'string') {
    if (import.meta.env.DEV) {
      console.warn(`[i18n] Missing translation "${key}" for locale "${locale}".`);
    }

    return key;
  }

  if (!params) {
    return message;
  }

  const numbers = new Intl.NumberFormat(intlLocales[locale]);

  return message.replace(/\{(\w+)\}/g, (placeholder, name: string) => {
    if (!Object.hasOwn(params, name)) {
      return placeholder;
    }

    const value = params[name];

    return typeof value === 'number' ? numbers.format(value) : String(value);
  });
}

export function applyDocumentLocale(locale: Locale): void {
  document.documentElement.lang = locale;
  document.documentElement.dir = directions[locale];
}

/**
 * Keeps `<html lang dir>` in step with the shared `locale` prop. The root template sets them on the first
 * load; this covers client-side visits, including same-URL visits after switching language, which Inertia
 * performs as history replacements (no `navigate` event). Call once at startup.
 */
export function syncDocumentLocale(): void {
  const page = usePage();

  watchEffect(() => {
    // `props` is undefined until Inertia has set the initial page.
    const value: unknown = page.props?.locale;

    if (isLocale(value)) {
      applyDocumentLocale(value);
    }
  });
}

export function useI18n(): {
  t: (key: string, params?: TranslationParams) => string;
  locale: ComputedRef<Locale>;
  dir: ComputedRef<Direction>;
} {
  const page = usePage();
  const locale = computed<Locale>(() => (isLocale(page.props.locale) ? page.props.locale : defaultLocale));
  const dir = computed<Direction>(() => directions[locale.value]);

  return {
    t: (key, params) => translate(locale.value, key, params),
    locale,
    dir,
  };
}
