<script setup lang="ts">
import { computed, useAttrs, useId } from 'vue';

defineOptions({ inheritAttrs: false });

/** Labelled native <select> (keyboard and screen-reader friendly in both directions). Options go in the slot. */
const props = withDefaults(
  defineProps<{ label: string; hint?: string; error?: string; id?: string; hideLabel?: boolean }>(),
  { hint: undefined, error: undefined, id: undefined, hideLabel: false },
);
const model = defineModel<string>({ default: '' });

// `class` sizes the field (wrapper); everything else (disabled, name, listeners) goes to the <select>.
const attrs = useAttrs();
const selectAttrs = computed(() => Object.fromEntries(Object.entries(attrs).filter(([name]) => name !== 'class')));

const generatedId = useId();
const selectId = computed(() => props.id ?? `select-${generatedId}`);
const describedBy = computed(
  () =>
    [props.hint ? `${selectId.value}-hint` : null, props.error ? `${selectId.value}-error` : null]
      .filter(Boolean)
      .join(' ') || undefined,
);
</script>

<template>
  <div class="flex min-w-0 flex-col gap-1.5" :class="attrs.class">
    <label :for="selectId" class="text-small font-medium text-text" :class="hideLabel && 'sr-only'">{{ label }}</label>
    <select
      :id="selectId"
      v-model="model"
      v-bind="selectAttrs"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="describedBy"
      class="h-9 w-full min-w-0 rounded-control border bg-surface px-2 text-body text-text disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted pointer-coarse:h-11"
      :class="error ? 'border-danger' : 'border-border-control'"
    >
      <slot />
    </select>
    <p v-if="hint" :id="`${selectId}-hint`" class="text-caption text-text-muted">{{ hint }}</p>
    <p v-if="error" :id="`${selectId}-error`" class="text-caption text-danger">{{ error }}</p>
  </div>
</template>
