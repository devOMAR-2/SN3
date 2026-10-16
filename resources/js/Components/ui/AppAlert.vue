<script setup lang="ts">
import { CircleAlert, CircleCheck, Info, TriangleAlert } from '@lucide/vue';
import { computed, type Component } from 'vue';

export type AlertVariant = 'info' | 'success' | 'warning' | 'error';

const props = withDefaults(defineProps<{ variant?: AlertVariant; title?: string }>(), {
  variant: 'info',
  title: undefined,
});

// Each variant has its own icon shape as well as colour, so meaning never relies on colour alone.
const variants: Record<AlertVariant, { icon: Component; box: string; accent: string }> = {
  info: { icon: Info, box: 'border-info/40 bg-info-subtle', accent: 'text-info' },
  success: { icon: CircleCheck, box: 'border-success/40 bg-success-subtle', accent: 'text-success' },
  warning: { icon: TriangleAlert, box: 'border-warning/40 bg-warning-subtle', accent: 'text-warning' },
  error: { icon: CircleAlert, box: 'border-danger/40 bg-danger-subtle', accent: 'text-danger' },
};

const current = computed(() => variants[props.variant]);

// Errors interrupt (assertive); everything else is announced politely.
const role = computed(() => (props.variant === 'error' ? 'alert' : 'status'));
</script>

<template>
  <div :role="role" class="flex items-start gap-3 rounded-control border p-3 text-body text-text" :class="current.box">
    <component :is="current.icon" class="mt-0.5 size-4 shrink-0" :class="current.accent" aria-hidden="true" />
    <div class="min-w-0 flex-1">
      <p v-if="title" class="font-semibold" :class="current.accent">{{ title }}</p>
      <div><slot /></div>
    </div>
  </div>
</template>
