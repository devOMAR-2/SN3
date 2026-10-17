<script setup lang="ts">
import AppAlert from '@/Components/ui/AppAlert.vue';
import { useI18n } from '@/i18n';
import type { Lock } from '@/types/domain';

/**
 * Read-only banner for an archived team or project (every write answers 423, ADR-022 §2). `read_only` is
 * shown when a write was refused with 423 after the page loaded.
 */
defineProps<{ lock: Lock | 'read_only' }>();
const { t } = useI18n();
</script>

<template>
  <AppAlert v-if="lock" variant="warning" :title="t(`lock.${lock}.title`)" class="mb-4">
    {{ t(`lock.${lock}.body`) }}
  </AppAlert>
</template>
