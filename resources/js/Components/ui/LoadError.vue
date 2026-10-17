<script setup lang="ts">
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import { useI18n } from '@/i18n';
import type { ApiError } from '@/lib/api';

/** A failed read: what happened, a safe Retry, and the non-sensitive request ID for support (DESIGN_SYSTEM.md §9.3). */
defineProps<{ error: ApiError }>();
const emit = defineEmits<{ retry: [] }>();
const { t } = useI18n();
</script>

<template>
  <AppAlert variant="error" :title="t('error.load_failed')">
    <p>{{ error.code === 'network_error' ? t('validation.network_error') : t('error.generic') }}</p>
    <p v-if="error.requestId" class="mt-1 text-caption text-text-muted">
      {{ t('error.request_id') }} <code>{{ error.requestId }}</code>
    </p>
    <AppButton class="mt-2" variant="secondary" size="sm" @click="emit('retry')">{{ t('ui.retry') }}</AppButton>
  </AppAlert>
</template>
