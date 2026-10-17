<script setup lang="ts">
import { FolderKanban, Plus, Settings, Users } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppBadge from '@/Components/ui/AppBadge.vue';
import ButtonLink from '@/Components/ui/ButtonLink.vue';
import TechnicalId from '@/Components/ui/TechnicalId.vue';
import LockBanner from '@/Components/Workspace/LockBanner.vue';
import PageHeader from '@/Components/Workspace/PageHeader.vue';
import WorkspaceLayout from '@/Components/Workspace/WorkspaceLayout.vue';
import { useI18n } from '@/i18n';
import { pages } from '@/lib/routes';
import type { Lock, Team, TeamAbilities } from '@/types/domain';

/** `/t/{team_slug}`: team overview with the way to every team page the user may open. */
const props = defineProps<{ team: Team; can: TeamAbilities; lock: Lock }>();
const { t } = useI18n();

const cards = computed(() => [
  {
    href: pages.projects(props.team.slug),
    title: t('nav.projects'),
    body: t('team.overview.projects'),
    icon: FolderKanban,
  },
  { href: pages.teamMembers(props.team.slug), title: t('nav.members'), body: t('team.overview.members'), icon: Users },
  ...(props.can.view_settings
    ? [
        {
          href: pages.teamSettings(props.team.slug),
          title: t('nav.settings'),
          body: t('team.overview.settings'),
          icon: Settings,
        },
      ]
    : []),
]);
</script>

<template>
  <WorkspaceLayout :title="team.name" :team="team" :team-can="can" :breadcrumbs="[{ label: team.name }]">
    <LockBanner :lock="lock" />
    <PageHeader :title="team.name">
      <template #subtitle>
        <span class="flex flex-wrap items-center gap-2">
          <AppBadge v-if="team.role">{{ t(`team.roles.${team.role}`) }}</AppBadge>
          <TechnicalId>{{ team.slug }}</TechnicalId>
        </span>
      </template>
      <template #actions>
        <ButtonLink v-if="can.create_project" :href="pages.newProject(team.slug)">
          <Plus class="size-4" aria-hidden="true" />{{ t('project.create') }}
        </ButtonLink>
      </template>
    </PageHeader>

    <p v-if="team.description" class="mb-6 text-body whitespace-pre-line text-text-muted" dir="auto">
      {{ team.description }}
    </p>

    <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <li v-for="card in cards" :key="card.href">
        <Link
          :href="card.href"
          class="flex h-full flex-col gap-2 rounded-card border bg-surface p-4 hover:bg-surface-hover"
        >
          <span class="flex items-center gap-2 font-semibold">
            <component :is="card.icon" class="size-4 text-primary" aria-hidden="true" />{{ card.title }}
          </span>
          <span class="text-small text-text-muted">{{ card.body }}</span>
        </Link>
      </li>
    </ul>
  </WorkspaceLayout>
</template>
