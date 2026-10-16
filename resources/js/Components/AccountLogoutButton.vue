<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton, { type ButtonVariant } from '@/Components/ui/AppButton.vue';
import { useI18n } from '@/i18n';
import { api } from '@/lib/api';

withDefaults(defineProps<{ variant?: ButtonVariant }>(), { variant: 'secondary' });

const { t } = useI18n();
const processing = ref(false);

async function logout(): Promise<void> {
  processing.value = true;

  try {
    await api.post('/auth/logout');
  } catch {
    // Signed out already (expired session) or offline: the full visit below shows the real state.
  } finally {
    // Full visit: the session and CSRF token were rotated by the server.
    router.visit('/login', { replace: true });
  }
}
</script>

<template>
  <AppButton :variant="variant" :loading="processing" @click="logout">{{ t('auth.logout') }}</AppButton>
</template>
