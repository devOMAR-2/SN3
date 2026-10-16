<script setup lang="ts">
import { useI18n } from '@/i18n';

const { t } = useI18n();
</script>

<template>
  <!--
    Temporary lettermark until the final SVG mark (DESIGN_SYSTEM.md §2: "Temporary accessible lettermark س is
    acceptable"). Logos are never mirrored. Both scripts of the name are always shown.
  -->
  <span class="inline-flex min-w-0 items-center gap-2">
    <span
      class="grid size-8 shrink-0 place-items-center rounded-control bg-primary text-heading font-semibold text-primary-on"
      lang="ar"
      aria-hidden="true"
      >س</span
    >
    <span class="truncate text-heading font-semibold">
      <span lang="ar" dir="rtl">{{ t('common.brand.arabic') }}</span>
      <span class="text-text-muted" aria-hidden="true"> · </span>
      <span lang="en" dir="ltr">{{ t('common.brand.latin') }}</span>
    </span>
  </span>
</template>
