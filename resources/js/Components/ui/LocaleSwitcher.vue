<script setup lang="ts">
import { Languages } from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/Components/ui/AppButton.vue';
import { directions, localeNames, useI18n, type Locale } from '@/i18n';

const props = withDefaults(
  defineProps<{
    /** Guests use `PUT /locale`; a signed-in shell can point this at `PATCH /me` (FND-004). */
    url?: string;
    method?: 'put' | 'patch';
  }>(),
  { url: '/locale', method: 'put' },
);

const { t, locale } = useI18n();
const processing = ref(false);

// Two supported languages, so the switcher offers the other one, named in its own language.
const target = computed<Locale>(() => (locale.value === 'ar' ? 'en' : 'ar'));

function switchLocale(): void {
  router.visit(props.url, {
    method: props.method,
    data: { locale: target.value },
    // Keep component state (e.g. half-filled forms) and scroll position (DESIGN_SYSTEM.md §7).
    preserveState: true,
    preserveScroll: true,
    onStart: () => {
      processing.value = true;
    },
    onFinish: () => {
      processing.value = false;
    },
  });
}
</script>

<template>
  <AppButton variant="ghost" :loading="processing" @click="switchLocale">
    <Languages class="size-4 shrink-0" aria-hidden="true" />
    <!-- Trailing space keeps the accessible name "Change language to English" readable. -->
    <span class="sr-only">{{ `${t('common.locale.switch_to')} ` }}</span>
    <span :lang="target" :dir="directions[target]">{{ localeNames[target] }}</span>
  </AppButton>
</template>
