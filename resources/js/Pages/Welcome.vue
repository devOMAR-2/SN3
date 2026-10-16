<script setup lang="ts">
import AppAlert from '@/Components/ui/AppAlert.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { useI18n } from '@/i18n';

const { t } = useI18n();
</script>

<template>
  <GuestLayout>
    <div class="flex flex-col items-center gap-4 text-center">
      <h1 class="text-display font-semibold">
        <span lang="ar" dir="rtl">{{ t('common.brand.arabic') }}</span>
        <span class="text-text-subtle" aria-hidden="true"> · </span>
        <span lang="en" dir="ltr">{{ t('common.brand.latin') }}</span>
      </h1>
      <p class="text-body text-text-muted">{{ t('welcome.tagline') }}</p>
      <AppAlert class="w-full text-start">{{ t('welcome.status') }}</AppAlert>
    </div>
  </GuestLayout>
</template>
