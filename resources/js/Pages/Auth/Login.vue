<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import StatusAlert from '@/Components/StatusAlert.vue';
import TextLink from '@/Components/TextLink.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import TextField from '@/Components/ui/TextField.vue';
import { useApiForm } from '@/Composables/useApiForm';
import { useI18n } from '@/i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const { t } = useI18n();
const page = usePage();
const form = useApiForm('auth', { email: '', password: '', remember: false });

async function submit(): Promise<void> {
  const user = await form.submit('post', '/auth/login');

  if (user !== null) {
    // A full visit so the new session, CSRF token and saved language/theme all apply.
    router.visit('/', { replace: true });
  } else {
    form.reset('password');
  }
}
</script>

<template>
  <GuestLayout :title="t('auth.login.title')">
    <StatusAlert />
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <AppAlert v-if="form.message.value" variant="error">{{ form.message.value }}</AppAlert>

      <TextField
        v-model="form.data.email"
        :label="t('auth.fields.email')"
        type="email"
        name="email"
        autocomplete="username"
        required
        :error="form.errors.email"
      />

      <TextField
        v-model="form.data.password"
        :label="t('auth.fields.password')"
        type="password"
        name="password"
        autocomplete="current-password"
        required
        :error="form.errors.password"
      />

      <div class="flex flex-wrap items-center justify-between gap-2">
        <label class="inline-flex items-center gap-2 text-small text-text">
          <input
            v-model="form.data.remember"
            type="checkbox"
            name="remember"
            class="size-4 rounded-tiny border-border-control accent-primary pointer-coarse:size-5"
          />
          {{ t('auth.fields.remember') }}
        </label>
        <TextLink href="/forgot-password" class="text-small">{{ t('auth.login.forgot') }}</TextLink>
      </div>

      <AppButton type="submit" :loading="form.processing.value" class="w-full">{{ t('auth.login.submit') }}</AppButton>
    </form>

    <p v-if="page.props.features.registration" class="mt-6 text-center text-small text-text-muted">
      {{ t('auth.login.no_account') }}
      <TextLink href="/register">{{ t('auth.login.register_link') }}</TextLink>
    </p>
  </GuestLayout>
</template>
