<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, useId } from 'vue';
import AccountLogoutButton from '@/Components/AccountLogoutButton.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import TextField from '@/Components/ui/TextField.vue';
import { useApiForm } from '@/Composables/useApiForm';
import { setThemePreference, themePreferences, type ThemePreference } from '@/Composables/useTheme';
import { localeNames, locales, useI18n, type Locale } from '@/i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { AuthUser } from '@/types/inertia';

const { t } = useI18n();
const page = usePage();
const user = page.props.auth.user as AuthUser;

const form = useApiForm('account', {
  name: user.name,
  locale: user.locale as Locale,
  timezone: user.timezone,
  theme: user.theme as ThemePreference,
});
const saved = ref(false);
const ids = { locale: useId(), timezone: useId(), theme: useId() };

// The browser's IANA list; the server validates the value too.
const timezones = computed<string[]>(() => {
  const supported = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [];
  const list = supported.includes('UTC') ? supported : ['UTC', ...supported];

  return list.includes(form.data.timezone) ? list : [form.data.timezone, ...list];
});

async function submit(): Promise<void> {
  saved.value = false;
  const languageChanged = form.data.locale !== page.props.locale;
  const result = await form.submit<AuthUser>('patch', '/me');

  if (result === null) {
    return;
  }

  setThemePreference(result.theme, { persist: false });
  saved.value = true;

  // Reload shared props (language, direction, profile) without a full page load.
  router.reload({ only: languageChanged ? [] : ['auth'] });
}

const selectClass =
  'h-9 w-full rounded-control border border-border-control bg-surface px-3 text-body text-text pointer-coarse:h-11';
</script>

<template>
  <AppLayout :title="t('account.settings.title')">
    <template #user-menu>
      <AccountLogoutButton variant="ghost" size="sm" />
    </template>

    <h1 class="mb-6 text-title font-semibold">{{ t('account.settings.title') }}</h1>

    <form class="flex max-w-md flex-col gap-4" novalidate @submit.prevent="submit">
      <AppAlert v-if="saved" variant="success">{{ t('account.settings.saved') }}</AppAlert>
      <AppAlert v-if="form.message.value" variant="error">{{ form.message.value }}</AppAlert>

      <TextField
        v-model="form.data.name"
        :label="t('account.fields.name')"
        name="name"
        autocomplete="name"
        maxlength="100"
        required
        :error="form.errors.name"
      />

      <div class="flex flex-col gap-1.5">
        <label :for="ids.locale" class="text-small font-medium text-text">{{ t('account.fields.locale') }}</label>
        <select :id="ids.locale" v-model="form.data.locale" name="locale" :class="selectClass">
          <option v-for="code in locales" :key="code" :value="code" :lang="code">{{ localeNames[code] }}</option>
        </select>
        <p v-if="form.errors.locale" class="text-small text-danger" role="alert">{{ form.errors.locale }}</p>
      </div>

      <div class="flex flex-col gap-1.5">
        <label :for="ids.timezone" class="text-small font-medium text-text">{{ t('account.fields.timezone') }}</label>
        <select :id="ids.timezone" v-model="form.data.timezone" name="timezone" dir="ltr" :class="selectClass">
          <option v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</option>
        </select>
        <p v-if="form.errors.timezone" class="text-small text-danger" role="alert">{{ form.errors.timezone }}</p>
      </div>

      <div class="flex flex-col gap-1.5">
        <label :for="ids.theme" class="text-small font-medium text-text">{{ t('account.fields.theme') }}</label>
        <select :id="ids.theme" v-model="form.data.theme" name="theme" :class="selectClass">
          <option v-for="theme in themePreferences" :key="theme" :value="theme">
            {{ t(`common.theme.${theme}`) }}
          </option>
        </select>
        <p v-if="form.errors.theme" class="text-small text-danger" role="alert">{{ form.errors.theme }}</p>
      </div>

      <AppButton type="submit" :loading="form.processing.value" class="self-start">{{
        t('account.settings.submit')
      }}</AppButton>
    </form>
  </AppLayout>
</template>
