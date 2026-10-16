<script setup lang="ts">
import { ref } from 'vue';
import TextLink from '@/Components/TextLink.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import TextField from '@/Components/ui/TextField.vue';
import { useApiForm } from '@/Composables/useApiForm';
import { useI18n } from '@/i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const { t } = useI18n();
const form = useApiForm('auth', { email: '' });
const sentTo = ref<string | null>(null);

async function submit(): Promise<void> {
  const result = await form.submit<{ status: string }>('post', '/auth/password/forgot');

  if (result !== null) {
    sentTo.value = form.data.email.trim();
  }
}
</script>

<template>
  <GuestLayout :title="t('auth.forgot.title')">
    <AppAlert v-if="sentTo" variant="success" class="mb-4">{{ t('auth.forgot.sent', { email: sentTo }) }}</AppAlert>

    <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <p class="text-body text-text-muted">{{ t('auth.forgot.intro') }}</p>
      <AppAlert v-if="form.message.value" variant="error">{{ form.message.value }}</AppAlert>

      <TextField
        v-model="form.data.email"
        :label="t('auth.fields.email')"
        type="email"
        name="email"
        autocomplete="email"
        required
        :error="form.errors.email"
      />

      <AppButton type="submit" :loading="form.processing.value" class="w-full">{{ t('auth.forgot.submit') }}</AppButton>
    </form>

    <p class="mt-6 text-center text-small">
      <TextLink href="/login">{{ t('auth.forgot.back') }}</TextLink>
    </p>
  </GuestLayout>
</template>
