<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AccountLogoutButton from '@/Components/AccountLogoutButton.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import { useApiForm } from '@/Composables/useApiForm';
import { useI18n } from '@/i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const form = useApiForm('auth', {});
const result = ref<string | null>(null);

async function resend(): Promise<void> {
  const response = await form.submit<{ status: string }>('post', '/auth/email/resend');
  result.value = response?.status ?? null;
}
</script>

<template>
  <GuestLayout :title="t('auth.verify.title')">
    <div class="flex flex-col gap-4">
      <AppAlert v-if="user?.email_verified || result === 'already_verified'" variant="success">{{
        t('auth.verify.already')
      }}</AppAlert>
      <template v-else>
        <p class="text-body text-text-muted">{{ t('auth.verify.body', { email: user?.email ?? '' }) }}</p>
        <AppAlert v-if="result === 'verification_sent'" variant="success">{{ t('auth.verify.sent') }}</AppAlert>
        <AppAlert v-if="form.message.value" variant="error">{{ form.message.value }}</AppAlert>
        <AppButton :loading="form.processing.value" class="w-full" @click="resend">{{
          t('auth.verify.resend')
        }}</AppButton>
      </template>
      <AccountLogoutButton variant="ghost" class="w-full" />
    </div>
  </GuestLayout>
</template>
