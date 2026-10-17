<script setup lang="ts">
import { useI18n } from '@/i18n';

/** Stable placeholder rows while a list loads (DESIGN_SYSTEM.md §9.1); announced once as "Loading". */
withDefaults(defineProps<{ rows?: number }>(), { rows: 3 });
const { t } = useI18n();
</script>

<template>
  <div role="status" class="flex flex-col gap-2">
    <span class="sr-only">{{ t('common.loading') }}</span>
    <div
      v-for="row in rows"
      :key="row"
      class="h-12 animate-pulse rounded-control bg-surface-muted"
      aria-hidden="true"
    />
  </div>
</template>
