<script setup lang="ts">
import AppBadge from '@/Components/ui/AppBadge.vue';
import { useI18n } from '@/i18n';
import type { Priority } from '@/types/domain';

/** Priority as text (never colour alone); `none` renders nothing unless `showNone`. */
withDefaults(defineProps<{ priority: Priority; showNone?: boolean }>(), { showNone: false });
const { t } = useI18n();

const variants = { none: 'neutral', low: 'neutral', medium: 'info', high: 'warning', urgent: 'danger' } as const;
</script>

<template>
  <AppBadge v-if="priority !== 'none' || showNone" :variant="variants[priority]">
    {{ t('task.priority_label', { priority: t(`task.priorities.${priority}`) }) }}
  </AppBadge>
</template>
