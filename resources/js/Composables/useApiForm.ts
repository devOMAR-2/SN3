/**
 * Form state for submitting to /api/v1 with field-level, translated validation errors.
 *
 * The API returns stable rule codes per field (`{"email": ["credentials"]}`, API_SPEC.md §1.2). A code is
 * shown as `<namespace>.errors.<field>.<code>` when that message exists, otherwise as the generic
 * `validation.<code>` message with the field's label (`<namespace>.fields.<field>`). Non-field errors show
 * the server's translated message.
 */
import { reactive, ref, shallowRef, type Ref, type ShallowRef } from 'vue';
import { hasTranslation, useI18n } from '@/i18n';
import { api, toApiError, type ApiEnvelope, type ApiError } from '@/lib/api';

export type FormMethod = 'post' | 'patch' | 'put';

export interface SubmitOptions {
  body?: Record<string, unknown>;
  headers?: Record<string, string>;
}

export interface ApiForm<T extends Record<string, unknown>> {
  data: T;
  errors: Partial<Record<keyof T & string, string>>;
  /** Error not tied to one field (rate limited, CSRF expired, network, server). */
  message: Ref<string | null>;
  processing: Ref<boolean>;
  /** The last failure (status and code), e.g. to react to 412 or 423 (CORE-006). */
  error: ShallowRef<ApiError | null>;
  /** `body` overrides the submitted data (e.g. to drop fields that do not apply); `headers` are extra request headers. */
  submit<R>(method: FormMethod, url: string, options?: SubmitOptions): Promise<R | null>;
  reset(...fields: (keyof T & string)[]): void;
}

export function useApiForm<T extends Record<string, unknown>>(namespace: string, initial: T): ApiForm<T> {
  const { t, locale } = useI18n();
  const data = reactive({ ...initial }) as T;
  const errors = reactive({}) as Partial<Record<keyof T & string, string>>;
  const message = ref<string | null>(null);
  const processing = ref(false);
  const error = shallowRef<ApiError | null>(null);

  function fieldMessage(field: string, code: string): string {
    const specific = `${namespace}.errors.${field}.${code}`;

    if (hasTranslation(locale.value, specific)) {
      return t(specific);
    }

    const generic = `validation.${code}`;
    const attributeKey = `${namespace}.fields.${field}`;
    const attribute = hasTranslation(locale.value, attributeKey) ? t(attributeKey) : field;

    return hasTranslation(locale.value, generic) ? t(generic, { attribute }) : t('validation.invalid', { attribute });
  }

  function clearErrors(): void {
    for (const key of Object.keys(errors)) {
      delete errors[key as keyof T & string];
    }

    message.value = null;
  }

  function showError(failure: ApiError): void {
    const fieldEntries = Object.entries(failure.fields);

    if (failure.status === 422 && fieldEntries.length > 0) {
      for (const [field, codes] of fieldEntries) {
        const code = codes[0];

        if (code !== undefined) {
          errors[field as keyof T & string] = fieldMessage(field, code);
        }
      }

      return;
    }

    message.value = failure.code === 'network_error' ? t('validation.network_error') : failure.message;
  }

  async function submit<R>(method: FormMethod, url: string, options: SubmitOptions = {}): Promise<R | null> {
    clearErrors();
    error.value = null;
    processing.value = true;

    try {
      const response = await api.request<ApiEnvelope<R>>({
        method,
        url,
        data: options.body ?? { ...data },
        headers: options.headers,
      });

      return response.data.data;
    } catch (thrown) {
      error.value = toApiError(thrown);
      showError(error.value);

      return null;
    } finally {
      processing.value = false;
    }
  }

  function reset(...fields: (keyof T & string)[]): void {
    const keys = fields.length > 0 ? fields : (Object.keys(initial) as (keyof T & string)[]);

    for (const key of keys) {
      data[key] = initial[key];
    }
  }

  return { data, errors, message, processing, error, submit, reset };
}
