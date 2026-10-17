<script setup lang="ts">
/**
 * Text badge with a semantic treatment (DESIGN_SYSTEM.md §4 Badge / StatusChip). Meaning is always in the
 * text; the colour only reinforces it (DS-ACC-015).
 */
export type BadgeVariant = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

withDefaults(defineProps<{ variant?: BadgeVariant }>(), { variant: 'neutral' });

const classes: Record<BadgeVariant, string> = {
  neutral: 'border-border bg-surface-muted text-text-muted',
  info: 'border-info/40 bg-info-subtle text-info',
  success: 'border-success/40 bg-success-subtle text-success',
  warning: 'border-warning/40 bg-warning-subtle text-warning',
  danger: 'border-danger/40 bg-danger-subtle text-danger',
};
</script>

<template>
  <span
    class="inline-flex max-w-full items-center gap-1 truncate rounded-pill border px-2 py-0.5 text-caption font-medium"
    :class="classes[variant]"
  >
    <slot />
  </span>
</template>
