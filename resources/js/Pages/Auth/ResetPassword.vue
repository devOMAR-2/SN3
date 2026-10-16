<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import TextLink from '@/Components/TextLink.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import TextField from '@/Components/ui/TextField.vue';
import { useApiForm } from '@/Composables/useApiForm';
import { useI18n } from '@/i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { readResetLink } from '@/lib/resetLink';

// The token arrives in the URL fragment, which the server never sees. Read it once, then drop it
// from Inertia's page URL (which Inertia keeps writing back into the address bar and history) so it
// is not left behind on the device.
const link = readResetLink(window.location.hash);
onMounted(() => {
  if (window.location.hash !== '') {
    router.replace({
      url: window.location.pathname + window.location.search,
      preserveState: true,
      preserveScroll: true,
    });
  }
});

const { t } = useI18n();
const form = useApiForm('auth', { token: link.token, email: link.email, password: '' });
const missingToken = link.token === '';
const done = ref(false);

async function submit(): Promise<void> {
  const result = await form.submit<{ status: string }>('post', '/auth/password/reset');

  if (result !== null) {
    done.value = true;
  } else {
    form.reset('password');
  }
}
</script>

<template>
  <GuestLayout :title="t('auth.reset.title')">
    <template v-if="done">
      <AppAlert variant="success">{{ t('auth.reset.done') }}</AppAlert>
      <p class="mt-6 text-center text-small">
        <TextLink href="/login">{{ t('auth.forgot.back') }}</TextLink>
      </p>
    </template>

    <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <AppAlert v-if="missingToken || form.errors.token" variant="error">
        {{ form.errors.token || t('auth.errors.token.invalid_token') }}
        <TextLink href="/forgot-password">{{ t('auth.forgot.submit') }}</TextLink>
      </AppAlert>
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
        autocomplete="new-password"
        minlength="12"
        maxlength="128"
        required
        :hint="t('auth.hints.password')"
        :error="form.errors.password"
      />

      <AppButton type="submit" :loading="form.processing.value" class="w-full">{{ t('auth.reset.submit') }}</AppButton>
    </form>
  </GuestLayout>
</template>
