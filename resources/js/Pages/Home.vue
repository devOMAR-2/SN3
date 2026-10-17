<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import StatusAlert from '@/Components/StatusAlert.vue';
import TextLink from '@/Components/TextLink.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppBadge from '@/Components/ui/AppBadge.vue';
import ButtonLink from '@/Components/ui/ButtonLink.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import WorkspaceLayout from '@/Components/Workspace/WorkspaceLayout.vue';
import { useI18n } from '@/i18n';
import { pages } from '@/lib/routes';

/** Signed-in home: the user's teams and the way to create one (CORE-006: no dead ends). */
const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const teams = computed(() => page.props.workspace?.teams ?? []);
const verified = computed(() => user.value?.email_verified === true);
</script>

<template>
  <WorkspaceLayout :title="t('account.home.title')">
    <StatusAlert />

    <div class="flex flex-col gap-6">
      <h1 class="text-title font-semibold">{{ t('account.home.greeting', { name: user?.name ?? '' }) }}</h1>

      <AppAlert v-if="user && !verified" variant="warning">
        {{ t('account.home.unverified') }}
        <TextLink :href="pages.verifyEmail()">{{ t('account.home.verify_link') }}</TextLink>
      </AppAlert>

      <section aria-labelledby="home-teams" class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 id="home-teams" class="text-heading font-semibold">{{ t('nav.your_teams') }}</h2>
          <ButtonLink v-if="verified" :href="pages.newTeam()">
            <Plus class="size-4" aria-hidden="true" />{{ t('team.create') }}
          </ButtonLink>
        </div>

        <EmptyState v-if="teams.length === 0" :title="t('empty.teams.title')" :description="t('empty.teams.body')" />

        <ul v-else class="grid gap-3 sm:grid-cols-2">
          <li v-for="team in teams" :key="team.id">
            <Link
              :href="pages.team(team.slug)"
              class="flex h-full flex-col gap-2 rounded-card border bg-surface p-4 hover:bg-surface-hover"
            >
              <span class="truncate font-semibold">{{ team.name }}</span>
              <span class="flex flex-wrap gap-2">
                <AppBadge v-if="team.role">{{ t(`team.roles.${team.role}`) }}</AppBadge>
                <AppBadge v-if="team.archived" variant="warning">{{ t('team.archived') }}</AppBadge>
              </span>
            </Link>
          </li>
        </ul>
        <p v-if="page.props.workspace?.has_more">
          <TextLink :href="pages.teams()">{{ t('nav.all_teams') }}</TextLink>
        </p>
      </section>

      <p>
        <TextLink :href="pages.profile()">{{ t('account.home.settings_link') }}</TextLink>
      </p>
    </div>
  </WorkspaceLayout>
</template>
