<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import TextLink from '@/Components/TextLink.vue';
import WorkspaceLayout from '@/Components/Workspace/WorkspaceLayout.vue';
import { hasTranslation, useI18n } from '@/i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { pages } from '@/lib/routes';

/**
 * Error page for browser routes (App\Exceptions\PageExceptionRenderer, ADR-026 §4). A hidden team, project
 * or task gets the same generic "unavailable" text as a missing one: nothing confirms that it exists
 * (DESIGN_SYSTEM.md §6, §9.4; DS-ACC-014). Always offers a way back (no dead ends).
 */
const props = defineProps<{ status: number }>();

const { t, locale } = useI18n();
const page = usePage();
const signedIn = computed(() => page.props.auth.user !== null);
const key = computed(() =>
  hasTranslation(locale.value, `error.page.${props.status}.title`) ? String(props.status) : '500',
);
</script>

<template>
  <component :is="signedIn ? WorkspaceLayout : GuestLayout" :title="t(`error.page.${key}.title`)">
    <div class="flex max-w-xl flex-col gap-3">
      <p class="text-small font-medium text-text-muted" dir="ltr">{{ status }}</p>
      <h1 class="text-title font-semibold">{{ t(`error.page.${key}.title`) }}</h1>
      <p class="text-body text-text-muted">{{ t(`error.page.${key}.body`) }}</p>
      <p class="flex flex-wrap gap-4">
        <TextLink :href="signedIn ? pages.home() : '/login'">{{
          t(signedIn ? 'error.back_home' : 'error.sign_in')
        }}</TextLink>
        <TextLink v-if="signedIn" :href="pages.teams()">{{ t('nav.teams') }}</TextLink>
      </p>
    </div>
  </component>
</template>
