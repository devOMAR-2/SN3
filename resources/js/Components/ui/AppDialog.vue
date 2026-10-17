<script setup lang="ts">
import { X } from '@lucide/vue';
import { nextTick, useId, useTemplateRef, watch } from 'vue';
import AppButton from '@/Components/ui/AppButton.vue';
import { useI18n } from '@/i18n';

/**
 * Modal dialog (DESIGN_SYSTEM.md §4 Dialog, §8): the native <dialog> with showModal() traps focus, makes the
 * page inert and closes on Escape; focus returns to the element that opened it. The title and description
 * are announced through aria-labelledby / aria-describedby. Bottom-aligned full-width sheet on mobile.
 */
const props = withDefaults(
  defineProps<{
    title: string;
    description?: string;
    /** Where focus goes on close, when the opener may be gone (e.g. re-rendered elsewhere); else the opener. */
    returnFocus?: () => HTMLElement | null;
  }>(),
  { description: undefined, returnFocus: undefined },
);
const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const dialog = useTemplateRef<HTMLDialogElement>('dialog');
const titleId = `dialog-title-${useId()}`;
const descriptionId = `dialog-description-${useId()}`;
let opener: HTMLElement | null = null;

watch(
  open,
  async (value) => {
    await nextTick();
    const element = dialog.value;

    if (!element) {
      return;
    }

    if (value && !element.open) {
      opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      element.showModal?.();
    } else if (!value && element.open) {
      element.close();
    }
  },
  { immediate: true },
);

function onClose(): void {
  open.value = false;
  const target = props.returnFocus?.() ?? null;
  (target ?? opener)?.focus();
  opener = null;
}

function onClick(event: MouseEvent): void {
  if (event.target === dialog.value) {
    open.value = false;
  }
}
</script>

<template>
  <dialog
    ref="dialog"
    :aria-labelledby="titleId"
    :aria-describedby="props.description ? descriptionId : undefined"
    class="m-0 mt-auto w-full max-w-none rounded-t-dialog border bg-surface p-0 text-text shadow-panel backdrop:bg-overlay md:m-auto md:w-[min(32rem,calc(100vw-2rem))] md:rounded-dialog"
    @close="onClose"
    @click="onClick"
  >
    <div v-if="open" class="flex max-h-[85dvh] flex-col">
      <div class="flex items-start justify-between gap-3 border-b p-4 md:px-6">
        <div class="min-w-0">
          <h2 :id="titleId" class="text-heading font-semibold">{{ title }}</h2>
          <p v-if="description" :id="descriptionId" class="mt-1 text-small text-text-muted">{{ description }}</p>
        </div>
        <AppButton variant="ghost" size="sm" icon-only :aria-label="t('ui.close')" @click="open = false">
          <X class="size-4" aria-hidden="true" />
        </AppButton>
      </div>
      <div class="flex-1 overflow-y-auto p-4 md:px-6">
        <slot />
      </div>
      <div v-if="$slots.footer" class="flex flex-wrap justify-end gap-2 border-t p-4 md:px-6">
        <slot name="footer" />
      </div>
    </div>
  </dialog>
</template>
