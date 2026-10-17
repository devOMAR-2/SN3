<script setup lang="ts">
import { X } from '@lucide/vue';
import AppButton from '@/Components/ui/AppButton.vue';
import { dismissToast, useToasts } from '@/Composables/useToast';
import { useI18n } from '@/i18n';

/** Live region for toasts: polite for success/info, assertive for errors; never takes focus. */
const { toasts } = useToasts();
const { t } = useI18n();

const tone = {
  success: 'border-success/40 text-success',
  info: 'border-info/40 text-info',
  error: 'border-danger/40 text-danger',
};
</script>

<template>
  <div class="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex flex-col items-center gap-2 p-4 md:items-end">
    <div aria-live="polite" class="flex w-full flex-col items-center gap-2 md:items-end">
      <template v-for="toast in toasts" :key="toast.id">
        <div
          v-if="toast.variant !== 'error'"
          class="pointer-events-auto flex w-full max-w-sm items-start gap-2 rounded-card border bg-surface p-3 text-body shadow-panel"
          :class="tone[toast.variant]"
        >
          <p class="flex-1 text-text">{{ toast.message }}</p>
          <AppButton variant="ghost" size="sm" icon-only :aria-label="t('ui.dismiss')" @click="dismissToast(toast.id)">
            <X class="size-4" aria-hidden="true" />
          </AppButton>
        </div>
      </template>
    </div>
    <div aria-live="assertive" class="flex w-full flex-col items-center gap-2 md:items-end">
      <template v-for="toast in toasts" :key="toast.id">
        <div
          v-if="toast.variant === 'error'"
          role="alert"
          class="pointer-events-auto flex w-full max-w-sm items-start gap-2 rounded-card border bg-surface p-3 text-body shadow-panel"
          :class="tone.error"
        >
          <p class="flex-1 text-text">{{ toast.message }}</p>
          <AppButton variant="ghost" size="sm" icon-only :aria-label="t('ui.dismiss')" @click="dismissToast(toast.id)">
            <X class="size-4" aria-hidden="true" />
          </AppButton>
        </div>
      </template>
    </div>
  </div>
</template>
