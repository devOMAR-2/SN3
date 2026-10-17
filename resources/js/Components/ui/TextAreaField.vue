<script setup lang="ts">
import { computed, useId } from 'vue';

defineOptions({ inheritAttrs: false });

/** Labelled multi-line input with optional character counter (DESIGN_SYSTEM.md §4 TextInput / Textarea). */
const props = withDefaults(
  defineProps<{ label: string; hint?: string; error?: string; maxlength?: number; rows?: number; id?: string }>(),
  { hint: undefined, error: undefined, maxlength: undefined, rows: 4, id: undefined },
);
const model = defineModel<string>({ default: '' });

const generatedId = useId();
const fieldId = computed(() => props.id ?? `textarea-${generatedId}`);
const describedBy = computed(
  () =>
    [props.hint ? `${fieldId.value}-hint` : null, props.error ? `${fieldId.value}-error` : null]
      .filter(Boolean)
      .join(' ') || undefined,
);
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label :for="fieldId" class="text-small font-medium text-text">{{ label }}</label>
    <textarea
      :id="fieldId"
      v-model="model"
      v-bind="$attrs"
      :rows="rows"
      :maxlength="maxlength"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="describedBy"
      dir="auto"
      class="w-full min-w-0 rounded-control border bg-surface px-3 py-2 text-body text-text disabled:cursor-not-allowed disabled:bg-surface-muted"
      :class="error ? 'border-danger' : 'border-border-control'"
    />
    <div class="flex justify-between gap-2 text-caption text-text-muted">
      <p v-if="hint" :id="`${fieldId}-hint`">{{ hint }}</p>
      <span v-if="maxlength" class="ms-auto" dir="ltr">{{ model.length }} / {{ maxlength }}</span>
    </div>
    <p v-if="error" :id="`${fieldId}-error`" class="text-caption text-danger">{{ error }}</p>
  </div>
</template>
