<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { useI18n } from '@/i18n';

/** Shows the one-time `flash.status` set by the server (e.g. after following a verification link). */
const page = usePage();
const { t } = useI18n();

const known: Record<string, 'success' | 'warning'> = {
  email_verified: 'success',
  verification_link_invalid: 'warning',
};

const status = computed(() => {
  const value = page.props.flash.status;

  return value !== null && value in known ? value : null;
});
</script>

<template>
  <AppAlert v-if="status" :variant="known[status]" class="mb-4">{{ t(`auth.status.${status}`) }}</AppAlert>
</template>
