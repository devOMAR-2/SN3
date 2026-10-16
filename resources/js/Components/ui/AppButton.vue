<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from '@/i18n';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger';
export type ButtonSize = 'sm' | 'md' | 'lg';

const props = withDefaults(
  defineProps<{
    variant?: ButtonVariant;
    size?: ButtonSize;
    type?: 'button' | 'submit' | 'reset';
    /** Shows a spinner and blocks activation while keeping the button focusable. */
    loading?: boolean;
    disabled?: boolean;
    /** Square button for a single icon. Give it an accessible name with `aria-label`. */
    iconOnly?: boolean;
  }>(),
  {
    variant: 'primary',
    size: 'md',
    type: 'button',
    loading: false,
    disabled: false,
    iconOnly: false,
  },
);

const { t } = useI18n();

// Heights: 36px with a fine pointer, 44px on touch (DESIGN_SYSTEM.md §2.2).
const sizeClasses: Record<ButtonSize, { text: string; square: string }> = {
  sm: { text: 'h-8 px-3 text-small pointer-coarse:h-11', square: 'size-8 pointer-coarse:size-11' },
  md: { text: 'h-9 px-4 text-body pointer-coarse:h-11', square: 'size-9 pointer-coarse:size-11' },
  lg: { text: 'h-11 px-5 text-body', square: 'size-11' },
};

const variantClasses: Record<ButtonVariant, { base: string; hover: string }> = {
  primary: { base: 'bg-primary text-primary-on', hover: 'hover:bg-primary-hover' },
  secondary: { base: 'border border-border-control bg-surface text-text', hover: 'hover:bg-surface-hover' },
  ghost: { base: 'text-text', hover: 'hover:bg-surface-hover' },
  danger: { base: 'bg-danger text-danger-on', hover: 'hover:opacity-90' },
};

const inactive = computed(() => props.disabled || props.loading);

const classes = computed(() => [
  'inline-flex shrink-0 items-center justify-center gap-2 rounded-control font-medium whitespace-nowrap select-none',
  'transition-[background-color,opacity] duration-(--motion-hover) ease-(--motion-ease)',
  props.iconOnly ? sizeClasses[props.size].square : sizeClasses[props.size].text,
  variantClasses[props.variant].base,
  inactive.value ? 'cursor-not-allowed' : variantClasses[props.variant].hover,
  props.disabled && 'opacity-60',
]);

function onClick(event: MouseEvent): void {
  // aria-disabled (not `disabled`) keeps focus on the button while it is busy; block activation explicitly.
  if (props.loading) {
    event.preventDefault();
    event.stopImmediatePropagation();
  }
}
</script>

<template>
  <button
    :type="type"
    :class="classes"
    :disabled="disabled"
    :aria-disabled="loading ? 'true' : undefined"
    :aria-busy="loading ? 'true' : undefined"
    @click="onClick"
  >
    <span
      v-if="loading"
      class="size-4 shrink-0 animate-spin rounded-full border-2 border-current border-e-transparent"
      aria-hidden="true"
    />
    <span v-if="loading" class="sr-only">{{ t('common.loading') }}</span>
    <slot />
  </button>
</template>
