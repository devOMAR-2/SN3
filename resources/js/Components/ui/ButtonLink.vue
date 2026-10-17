<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { ButtonSize, ButtonVariant } from '@/Components/ui/AppButton.vue';

/** An Inertia link styled as a button (navigation, not an action). */
withDefaults(defineProps<{ href: string; variant?: ButtonVariant; size?: ButtonSize }>(), {
  variant: 'primary',
  size: 'md',
});

const variants: Record<ButtonVariant, string> = {
  primary: 'bg-primary text-primary-on hover:bg-primary-hover',
  secondary: 'border border-border-control bg-surface text-text hover:bg-surface-hover',
  ghost: 'text-text hover:bg-surface-hover',
  danger: 'bg-danger text-danger-on hover:opacity-90',
};

const sizes: Record<ButtonSize, string> = {
  sm: 'h-8 px-3 text-small pointer-coarse:h-11',
  md: 'h-9 px-4 text-body pointer-coarse:h-11',
  lg: 'h-11 px-5 text-body',
};
</script>

<template>
  <Link
    :href="href"
    class="inline-flex shrink-0 items-center justify-center gap-2 rounded-control font-medium whitespace-nowrap transition-[background-color,opacity] duration-(--motion-hover)"
    :class="[variants[variant], sizes[size]]"
  >
    <slot />
  </Link>
</template>
