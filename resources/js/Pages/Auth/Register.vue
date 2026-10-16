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
const form = useApiForm('auth', { name: '', email: '', password: '' });
const sentTo = ref<string | null>(null);

async function submit(): Promise<void> {
  // The response is the same whether or not the email already has an account (no enumeration).
  const result = await form.submit<{ status: string }>('post', '/auth/register');

  if (result !== null) {
    sentTo.value = form.data.email.trim();
    form.reset();
  } else {
    form.reset('password');
  }
}
</script>

<template>
  <GuestLayout :title="sentTo ? t('auth.register.sent_title') : t('auth.register.title')">
    <template v-if="sentTo">
      <AppAlert variant="success">{{ t('auth.register.sent_body', { email: sentTo }) }}</AppAlert>
      <p class="mt-6 text-center text-small">
        <TextLink href="/login">{{ t('auth.register.login_link') }}</TextLink>
      </p>
    </template>

    <template v-else>
      <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <AppAlert v-if="form.message.value" variant="error">{{ form.message.value }}</AppAlert>

        <TextField
          v-model="form.data.name"
          :label="t('auth.fields.name')"
          name="name"
          autocomplete="name"
          maxlength="100"
          required
          :error="form.errors.name"
        />

        <TextField
          v-model="form.data.email"
          :label="t('auth.fields.email')"
          type="email"
          name="email"
          autocomplete="email"
          required
          :error="form.errors.email"
        />

        <TextField
          v-model="form.data.password"
          :label="t('auth.fields.password')"
          type="password"
          name="password"
          autocomplete="new-password"
          minlength="12"
          maxlength="128"
          required
          :hint="t('auth.hints.password')"
          :error="form.errors.password"
        />

        <AppButton type="submit" :loading="form.processing.value" class="w-full">{{
          t('auth.register.submit')
        }}</AppButton>
      </form>

      <p class="mt-6 text-center text-small text-text-muted">
        {{ t('auth.register.have_account') }}
        <TextLink href="/login">{{ t('auth.register.login_link') }}</TextLink>
      </p>
    </template>
  </GuestLayout>
</template>
