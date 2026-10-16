<script setup lang="ts">
import { useI18n } from '@/i18n';

withDefaults(defineProps<{ target?: string }>(), { target: 'main-content' });

const { t } = useI18n();
</script>

<template>
  <a
    :href="`#${target}`"
    class="sr-only focus:not-sr-only focus:fixed focus:start-2 focus:top-2 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-2 focus:text-body focus:font-medium focus:text-primary focus:shadow-panel"
  >
    {{ t('layout.skip_to_content') }}
  </a>
</template>
