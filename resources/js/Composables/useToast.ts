/**
 * Success and error toasts (DESIGN_SYSTEM.md §4 Toast, §9.5): announced through an `aria-live` region,
 * never stealing focus. Success toasts disappear after a few seconds; error toasts stay until dismissed.
 */
import { ref } from 'vue';

export type ToastVariant = 'success' | 'info' | 'error';

export interface Toast {
  id: number;
  message: string;
  variant: ToastVariant;
}

const toasts = ref<Toast[]>([]);
let nextId = 1;

export function dismissToast(id: number): void {
  toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

export function showToast(message: string, variant: ToastVariant = 'success'): void {
  const id = nextId++;
  toasts.value = [...toasts.value, { id, message, variant }];

  if (variant !== 'error') {
    setTimeout(() => dismissToast(id), 5000);
  }
}

export function useToasts(): { toasts: typeof toasts } {
  return { toasts };
}
