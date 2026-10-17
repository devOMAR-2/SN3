/**
 * The signed-in user's unread notification count for the Inbox links (CORE-009, ADR-029 §6). It starts
 * from the shared `inbox` prop of each page (computed by the server with the inbox's read-time access
 * rules) and the inbox page sets it after marking notifications read, so the badges follow at once.
 */
import { usePage } from '@inertiajs/vue3';
import { ref, watch, type Ref } from 'vue';

const count = ref(0);

export function useUnreadCount(): { count: Ref<number>; set: (value: number) => void } {
  const page = usePage();

  watch(
    () => page.props.inbox?.unread_count,
    (value) => {
      if (typeof value === 'number') {
        count.value = value;
      }
    },
    { immediate: true },
  );

  return { count, set: (value) => (count.value = Math.max(0, value)) };
}

/**
 * The server stops counting here (`Inbox::UNREAD_COUNT_CAP`): `100` means "100 or more", so a client
 * cannot count down from it and has to ask the server again.
 */
export const UNREAD_COUNT_CAP = 100;

/** The badge text: the count, or `99+` beyond 99 (the capped `100` included). */
export function badgeText(value: number): string {
  return value > 99 ? '99+' : String(value);
}
