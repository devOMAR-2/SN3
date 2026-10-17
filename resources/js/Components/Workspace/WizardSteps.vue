<script setup lang="ts">
import { useI18n } from '@/i18n';

/** Step indicator of a short wizard; the current step is marked for assistive technology. */
defineProps<{ steps: string[]; current: number }>();
const { t } = useI18n();
</script>

<template>
  <ol class="flex flex-wrap gap-2 text-small" :aria-label="t('ui.steps')">
    <li
      v-for="(step, index) in steps"
      :key="step"
      class="flex items-center gap-2 rounded-pill border px-3 py-1"
      :class="index === current ? 'border-primary font-semibold text-primary' : 'text-text-muted'"
      :aria-current="index === current ? 'step' : undefined"
    >
      <span dir="ltr">{{ index + 1 }}</span>
      <span>{{ step }}</span>
    </li>
  </ol>
</template>
