<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppDialog from '@/Components/ui/AppDialog.vue';
import TextField from '@/Components/ui/TextField.vue';
import { useI18n } from '@/i18n';

/**
 * Explicit confirmation for consequential and destructive actions (DESIGN_SYSTEM.md §4 ConfirmDialog, §5.2).
 * With `confirmText`, the user types that text (a name or key) before the action is enabled.
 */
const props = withDefaults(
  defineProps<{
    title: string;
    description?: string;
    confirmLabel: string;
    variant?: 'primary' | 'danger';
    confirmText?: string;
    loading?: boolean;
    error?: string | null;
    /** Keeps the action disabled until the dialog's own fields are complete. */
    confirmDisabled?: boolean;
  }>(),
  {
    description: undefined,
    variant: 'danger',
    confirmText: undefined,
    loading: false,
    error: null,
    confirmDisabled: false,
  },
);
const emit = defineEmits<{ confirm: [] }>();
const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const typed = ref('');
const ready = computed(
  () => !props.confirmDisabled && (props.confirmText === undefined || typed.value.trim() === props.confirmText),
);

watch(open, (value) => {
  if (value) {
    typed.value = '';
  }
});
</script>

<template>
  <AppDialog v-model:open="open" :title="title" :description="description">
    <form id="confirm-form" class="flex flex-col gap-4" novalidate @submit.prevent="ready && emit('confirm')">
      <slot />
      <TextField
        v-if="confirmText !== undefined"
        v-model="typed"
        :label="t('ui.type_to_confirm', { text: confirmText })"
        autocomplete="off"
        dir="auto"
      />
      <AppAlert v-if="error" variant="error">{{ error }}</AppAlert>
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="open = false">{{ t('ui.cancel') }}</AppButton>
      <AppButton type="submit" form="confirm-form" :variant="variant" :disabled="!ready" :loading="loading">
        {{ confirmLabel }}
      </AppButton>
    </template>
  </AppDialog>
</template>
