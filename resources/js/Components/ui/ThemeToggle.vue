<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { useId, type Component } from 'vue';
import { useTheme, type ThemePreference } from '@/Composables/useTheme';
import { useI18n } from '@/i18n';

const emit = defineEmits<{
  /** Fired after the user picks a theme, e.g. to also save it to the signed-in user's profile. */
  change: [preference: ThemePreference];
}>();

const { t } = useI18n();
const { preference, setPreference } = useTheme();
const groupName = `theme-${useId()}`;

const options: { value: ThemePreference; icon: Component }[] = [
  { value: 'system', icon: Monitor },
  { value: 'light', icon: Sun },
  { value: 'dark', icon: Moon },
];

function select(value: ThemePreference): void {
  setPreference(value);
  emit('change', value);
}
</script>

<template>
  <!-- Native radios give arrow-key navigation and checked-state announcements in both directions. -->
  <fieldset
    class="inline-flex shrink-0 items-center gap-px rounded-control border border-border-control bg-surface p-px"
  >
    <legend class="sr-only">{{ t('common.theme.label') }}</legend>
    <label
      v-for="option in options"
      :key="option.value"
      :title="t(`common.theme.${option.value}`)"
      class="relative inline-flex size-8 cursor-pointer items-center justify-center rounded-tiny text-text-muted transition-[background-color,color] duration-(--motion-hover) hover:text-text has-checked:bg-surface-hover has-checked:text-primary has-focus-visible:outline-2 has-focus-visible:outline-offset-1 has-focus-visible:outline-focus pointer-coarse:size-11"
    >
      <input
        type="radio"
        class="peer sr-only"
        :name="groupName"
        :value="option.value"
        :checked="preference === option.value"
        @change="select(option.value)"
      />
      <component :is="option.icon" class="size-4" aria-hidden="true" />
      <span class="sr-only">{{ t(`common.theme.${option.value}`) }}</span>
      <!-- Selected state is also shown by an underline, not by colour alone. -->
      <span
        class="absolute inset-x-2 bottom-1 hidden h-0.5 rounded-pill bg-primary peer-checked:block"
        aria-hidden="true"
      />
    </label>
  </fieldset>
</template>
