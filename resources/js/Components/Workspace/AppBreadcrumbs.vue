<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';

/**
 * Breadcrumb (DESIGN_SYSTEM.md §4): ancestor links, the current page last. On narrow screens only the parent
 * and the current page stay visible. The separator chevron follows the reading direction.
 */
export interface Crumb {
  label: string;
  href?: string;
  /** A task key or other identifier: rendered LTR inside Arabic text (DESIGN_SYSTEM.md §7). */
  technical?: boolean;
}

defineProps<{ items: Crumb[] }>();
const { t, dir } = useI18n();
</script>

<template>
  <nav :aria-label="t('nav.breadcrumb')">
    <ol class="flex min-w-0 flex-wrap items-center gap-1 text-small text-text-muted">
      <li
        v-for="(item, index) in items"
        :key="index"
        class="flex min-w-0 items-center gap-1"
        :class="index < items.length - 2 && 'max-md:hidden'"
      >
        <Link
          v-if="item.href && index < items.length - 1"
          :href="item.href"
          class="truncate hover:text-text hover:underline"
          :dir="item.technical ? 'ltr' : undefined"
          >{{ item.label }}</Link
        >
        <span v-else class="truncate text-text" aria-current="page" :dir="item.technical ? 'ltr' : undefined">{{
          item.label
        }}</span>
        <component
          :is="dir === 'rtl' ? ChevronLeft : ChevronRight"
          v-if="index < items.length - 1"
          class="size-3.5 shrink-0"
          aria-hidden="true"
        />
      </li>
    </ol>
  </nav>
</template>
