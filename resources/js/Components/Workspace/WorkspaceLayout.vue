<script setup lang="ts">
import { Bell, CircleUser } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import AccountLogoutButton from '@/Components/AccountLogoutButton.vue';
import ToastRegion from '@/Components/ui/ToastRegion.vue';
import AppBreadcrumbs, { type Crumb } from '@/Components/Workspace/AppBreadcrumbs.vue';
import WorkspaceNav from '@/Components/Workspace/WorkspaceNav.vue';
import { badgeText, useUnreadCount } from '@/Composables/useUnreadCount';
import { useI18n } from '@/i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { pages } from '@/lib/routes';
import type { Team, TeamAbilities } from '@/types/domain';

/**
 * The signed-in workspace shell for CORE-006 pages: AppLayout (FND-006) with the team navigation in its
 * `navigation` slot, the account menu with the notifications link (CORE-009), breadcrumbs
 * (`Team / Project / View`) and the toast live region.
 */
withDefaults(
  defineProps<{
    title: string;
    team?: Team | null;
    teamCan?: TeamAbilities | null;
    projectId?: string | null;
    breadcrumbs?: Crumb[];
  }>(),
  { team: null, teamCan: null, projectId: null, breadcrumbs: () => [] },
);

const { t } = useI18n();
const unread = useUnreadCount();
</script>

<template>
  <AppLayout :title="title">
    <template #navigation="{ collapsed }">
      <WorkspaceNav :team="team" :team-can="teamCan" :project-id="projectId" :collapsed="collapsed" />
    </template>
    <template #user-menu>
      <Link
        :href="pages.inbox()"
        class="relative flex size-9 items-center justify-center rounded-control hover:bg-surface-hover pointer-coarse:size-11"
        :aria-label="
          unread.count.value > 0 ? t('inbox.link_unread', { count: badgeText(unread.count.value) }) : t('nav.inbox')
        "
        :title="t('nav.inbox')"
      >
        <Bell class="size-5" aria-hidden="true" />
        <span
          v-if="unread.count.value > 0"
          aria-hidden="true"
          class="absolute -end-0.5 -top-0.5 rounded-pill bg-primary px-1 text-caption leading-4 font-semibold text-primary-on"
          >{{ badgeText(unread.count.value) }}</span
        >
      </Link>
      <Link
        :href="pages.profile()"
        class="flex size-9 items-center justify-center rounded-control hover:bg-surface-hover pointer-coarse:size-11"
        :aria-label="t('nav.profile')"
        :title="t('nav.profile')"
      >
        <CircleUser class="size-5" aria-hidden="true" />
      </Link>
      <AccountLogoutButton variant="ghost" size="sm" />
    </template>

    <AppBreadcrumbs v-if="breadcrumbs.length > 0" :items="breadcrumbs" class="mb-4" />
    <slot />
    <ToastRegion />
  </AppLayout>
</template>
