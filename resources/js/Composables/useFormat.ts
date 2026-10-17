/** Formatting bound to the signed-in user's language and time zone (DESIGN_SYSTEM.md §7). */
import { usePage } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';
import { formatDate, formatDateTime } from '@/lib/format';

export function useFormat(): {
  date: (value: string | null) => string;
  dateTime: (value: string | null) => string;
} {
  const { locale } = useI18n();
  const page = usePage();

  return {
    date: (value) => formatDate(locale.value, value),
    dateTime: (value) => formatDateTime(locale.value, value, page.props.auth.user?.timezone ?? 'UTC'),
  };
}
