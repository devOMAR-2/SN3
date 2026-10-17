/**
 * A /api/v1 list read page by page with the opaque cursor (API_SPEC.md §1.1, §1.4), with honest
 * loading, empty and error states (DESIGN_SYSTEM.md §9). The server scopes and paginates; the client
 * never holds more than the pages the user asked for.
 */
import { ref, shallowRef, type Ref, type ShallowRef } from 'vue';
import { api, toApiError, type ApiError } from '@/lib/api';
import type { CursorPage } from '@/types/domain';

export interface CursorList<T> {
  items: ShallowRef<T[]>;
  /** True while the first page is loading (show a skeleton). */
  loading: Ref<boolean>;
  /** True while a further page is loading. */
  loadingMore: Ref<boolean>;
  error: ShallowRef<ApiError | null>;
  hasMore: Ref<boolean>;
  /** Discards what was loaded and reads the first page again. */
  reload: () => Promise<void>;
  loadMore: () => Promise<void>;
  /** Replaces one loaded item (after a successful write) without a reload. */
  replace: (match: (item: T) => boolean, next: T | null) => void;
}

/**
 * Appends a page, skipping items already loaded (by `id`). Keyset pages can return a row the client already
 * shows when it moved past the cursor meanwhile (a board card moved below the last loaded one).
 */
function appendNew<T>(loaded: T[], page: T[]): T[] {
  const idOf = (item: T): unknown => {
    const value: unknown = item;

    return typeof value === 'object' && value !== null && 'id' in value ? value.id : undefined;
  };
  const seen = new Set<unknown>(loaded.map(idOf).filter((id) => id !== undefined));

  return [...loaded, ...page.filter((item) => idOf(item) === undefined || !seen.has(idOf(item)))];
}

export function useCursorList<T>(
  url: () => string,
  params: () => Record<string, unknown> = () => ({}),
  limit = 25,
): CursorList<T> {
  const items = shallowRef<T[]>([]);
  const loading = ref(true);
  const loadingMore = ref(false);
  const error = shallowRef<ApiError | null>(null);
  const hasMore = ref(false);
  let cursor: string | null = null;
  let generation = 0;

  async function fetchPage(append: boolean): Promise<void> {
    const current = ++generation;
    error.value = null;

    try {
      const response = await api.get<CursorPage<T>>(url(), {
        params: { ...params(), limit, ...(append && cursor ? { cursor } : {}) },
      });

      // A newer reload started meanwhile (filter changed): drop this answer.
      if (current !== generation) {
        return;
      }

      items.value = append ? appendNew(items.value, response.data.data) : response.data.data;
      cursor = response.data.meta.next_cursor;
      hasMore.value = response.data.meta.has_more;
    } catch (thrown) {
      if (current === generation) {
        error.value = toApiError(thrown);
      }
    }
  }

  async function reload(): Promise<void> {
    loading.value = true;
    cursor = null;
    await fetchPage(false);
    loading.value = false;
  }

  async function loadMore(): Promise<void> {
    if (!hasMore.value || loadingMore.value) {
      return;
    }

    loadingMore.value = true;
    await fetchPage(true);
    loadingMore.value = false;
  }

  function replace(match: (item: T) => boolean, next: T | null): void {
    items.value = items.value.flatMap((item) => (match(item) ? (next === null ? [] : [next]) : [item]));
  }

  return { items, loading, loadingMore, error, hasMore, reload, loadMore, replace };
}
