<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AccountLogoutButton from '@/Components/AccountLogoutButton.vue';
import StatusAlert from '@/Components/StatusAlert.vue';
import TextLink from '@/Components/TextLink.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { useI18n } from '@/i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
  <AppLayout :title="t('account.home.title')">
    <template #user-menu>
      <AccountLogoutButton variant="ghost" size="sm" />
    </template>

    <StatusAlert />

    <div class="flex flex-col gap-4">
      <h1 class="text-title font-semibold">{{ t('account.home.greeting', { name: user?.name ?? '' }) }}</h1>

      <AppAlert v-if="user && !user.email_verified" variant="warning">
        {{ t('account.home.unverified') }}
        <TextLink href="/verify-email">{{ t('account.home.verify_link') }}</TextLink>
      </AppAlert>

      <p class="text-body text-text-muted">{{ t('account.home.intro') }}</p>
      <p>
        <TextLink href="/settings">{{ t('account.home.settings_link') }}</TextLink>
      </p>
    </div>
  </AppLayout>
</template>
