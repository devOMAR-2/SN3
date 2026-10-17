/**
 * Locale- and time-zone-aware formatting (DESIGN_SYSTEM.md §7: Intl with the user's locale and time zone;
 * the server stores UTC).
 */
import { intlLocales, type Locale } from '@/i18n';

/** A calendar date (`YYYY-MM-DD`, no time zone) such as a task's due date. */
export function formatDate(locale: Locale, value: string | null, style: 'medium' | 'full' = 'medium'): string {
  if (value === null) {
    return '';
  }

  const [year, month, day] = value.split('-').map(Number);

  if (!year || !month || !day) {
    return value;
  }

  // Noon UTC formatted in UTC, so no time zone can move the date.
  return new Intl.DateTimeFormat(intlLocales[locale], { dateStyle: style, timeZone: 'UTC' }).format(
    new Date(Date.UTC(year, month - 1, day, 12)),
  );
}

/** An instant (RFC 3339 UTC) in the user's time zone. */
export function formatDateTime(locale: Locale, value: string | null, timeZone = 'UTC'): string {
  if (value === null) {
    return '';
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  try {
    return new Intl.DateTimeFormat(intlLocales[locale], { dateStyle: 'medium', timeStyle: 'short', timeZone }).format(
      date,
    );
  } catch {
    return new Intl.DateTimeFormat(intlLocales[locale], { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  }
}

/** A suggested project key from its name: uppercase ASCII letters and digits, 2–10 characters, starting with a letter. */
export function suggestProjectKey(name: string): string {
  const words = name
    .normalize('NFKD')
    .replace(/[^A-Za-z0-9\s]/g, ' ')
    .split(/\s+/)
    // A key starts with a letter, so leading digits of a word never start it.
    .map((word) => word.replace(/^[0-9]+/, '').toUpperCase())
    .filter(Boolean);
  const initials = words.map((word) => word[0]).join('');
  const key = (words.length > 1 && initials.length >= 2 ? initials : (words[0] ?? '')).slice(0, 10);

  return /^[A-Z][A-Z0-9]{1,9}$/.test(key) ? key : '';
}
