<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';
import { computed, useId } from 'vue';

defineOptions({ inheritAttrs: false });

export type TextFieldType = 'text' | 'email' | 'password' | 'search' | 'tel' | 'url' | 'number';

const props = withDefaults(
  defineProps<{
    /** Persistent visible label; placeholders are never the only label (DESIGN_SYSTEM.md §8). */
    label: string;
    type?: TextFieldType;
    autocomplete?: string;
    hint?: string;
    error?: string;
    required?: boolean;
    id?: string;
    name?: string;
  }>(),
  {
    type: 'text',
    autocomplete: undefined,
    hint: undefined,
    error: undefined,
    required: false,
    id: undefined,
    name: undefined,
  },
);

const model = defineModel<string>({ default: '' });

const generatedId = useId();
const inputId = computed(() => props.id ?? `field-${generatedId}`);
const hintId = computed(() => `${inputId.value}-hint`);
const errorId = computed(() => `${inputId.value}-error`);

const describedBy = computed(
  () => [props.hint ? hintId.value : null, props.error ? errorId.value : null].filter(Boolean).join(' ') || undefined,
);

// Email addresses, URLs and phone numbers are LTR even in Arabic (DESIGN_SYSTEM.md §7). `dir` can be overridden.
const direction = computed(() => (['email', 'url', 'tel'].includes(props.type) ? 'ltr' : undefined));
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label :for="inputId" class="text-small font-medium text-text">
      {{ label }}<span v-if="required" class="text-danger" aria-hidden="true"> *</span>
    </label>
    <input
      :id="inputId"
      v-model="model"
      :type="type"
      :name="name"
      :autocomplete="autocomplete"
      :required="required"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="describedBy"
      :dir="direction"
      v-bind="$attrs"
      class="h-9 w-full min-w-0 rounded-control border bg-surface px-3 text-body text-text transition-[background-color,border-color] duration-(--motion-hover) placeholder:text-text-subtle disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted pointer-coarse:h-11"
      :class="error ? 'border-danger' : 'border-border-control'"
    />
    <p v-if="hint" :id="hintId" class="text-caption text-text-muted">{{ hint }}</p>
    <p v-if="error" :id="errorId" class="flex items-start gap-1.5 text-caption text-danger">
      <CircleAlert class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
      <span>{{ error }}</span>
    </p>
  </div>
</template>
