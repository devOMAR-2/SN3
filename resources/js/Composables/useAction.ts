/**
 * State for one API action (a button that archives, restores, removes…): pending flag and a translated
 * error message. The server's message is already localized (Accept-Language / user locale).
 */
import { computed, ref, shallowRef, type ComputedRef, type Ref, type ShallowRef } from 'vue';
import { useI18n } from '@/i18n';
import { toApiError, type ApiError } from '@/lib/api';

export interface Action {
  processing: Ref<boolean>;
  error: ShallowRef<ApiError | null>;
  message: ComputedRef<string | null>;
  run<R>(task: () => Promise<R>): Promise<R | null>;
  clear(): void;
}

export function useAction(): Action {
  const { t } = useI18n();
  const processing = ref(false);
  const error = shallowRef<ApiError | null>(null);
  const message = computed(() => {
    if (error.value === null) {
      return null;
    }

    return error.value.code === 'network_error' ? t('validation.network_error') : error.value.message;
  });

  async function run<R>(task: () => Promise<R>): Promise<R | null> {
    processing.value = true;
    error.value = null;

    try {
      return await task();
    } catch (thrown) {
      error.value = toApiError(thrown);

      return null;
    } finally {
      processing.value = false;
    }
  }

  return { processing, error, message, run, clear: () => (error.value = null) };
}
