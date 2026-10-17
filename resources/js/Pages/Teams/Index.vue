<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { Link, router } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppBadge from '@/Components/ui/AppBadge.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import ButtonLink from '@/Components/ui/ButtonLink.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import LoadError from '@/Components/ui/LoadError.vue';
import SkeletonRows from '@/Components/ui/SkeletonRows.vue';
import PageHeader from '@/Components/Workspace/PageHeader.vue';
import WorkspaceLayout from '@/Components/Workspace/WorkspaceLayout.vue';
import { useAction } from '@/Composables/useAction';
import { useCursorList } from '@/Composables/useCursorList';
import { useFormat } from '@/Composables/useFormat';
import { showToast } from '@/Composables/useToast';
import { useI18n } from '@/i18n';
import { api, type ApiEnvelope } from '@/lib/api';
import { pages } from '@/lib/routes';
import type { Team, TrashedTeam } from '@/types/domain';

/** `/teams`: every team of the user (GET /api/v1/teams) and their own teams in the 30-day trash. */
defineProps<{ deletedTeams: TrashedTeam[]; can: { create_team: boolean } }>();

const { t } = useI18n();
const format = useFormat();
const list = useCursorList<Team>(() => '/teams');
const restore = useAction();

onMounted(() => void list.reload());

async function restoreTeam(team: TrashedTeam): Promise<void> {
  const restored = await restore.run(
    async () => (await api.post<ApiEnvelope<Team>>(`/teams/${team.id}/restore-deleted`)).data.data,
  );

  if (restored) {
    showToast(t('team.restored'));
    router.visit(pages.team(restored.slug));
  }
}
</script>

<template>
  <WorkspaceLayout :title="t('nav.teams')" :breadcrumbs="[{ label: t('nav.teams') }]">
    <PageHeader :title="t('nav.teams')">
      <template #actions>
        <ButtonLink v-if="can.create_team" :href="pages.newTeam()">
          <Plus class="size-4" aria-hidden="true" />{{ t('team.create') }}
        </ButtonLink>
      </template>
    </PageHeader>

    <SkeletonRows v-if="list.loading.value" />
    <LoadError v-else-if="list.error.value" :error="list.error.value" @retry="list.reload" />
    <EmptyState
      v-else-if="list.items.value.length === 0"
      :title="t('empty.teams.title')"
      :description="t('empty.teams.body')"
    />
    <ul v-else class="flex flex-col divide-y rounded-card border bg-surface">
      <li v-for="team in list.items.value" :key="team.id">
        <Link :href="pages.team(team.slug)" class="flex flex-wrap items-center gap-3 p-4 hover:bg-surface-hover">
          <span class="min-w-0 flex-1 truncate font-medium">{{ team.name }}</span>
          <AppBadge v-if="team.role">{{ t(`team.roles.${team.role}`) }}</AppBadge>
          <AppBadge v-if="team.archived_at" variant="warning">{{ t('team.archived') }}</AppBadge>
        </Link>
      </li>
    </ul>
    <AppButton
      v-if="list.hasMore.value"
      class="mt-3"
      variant="secondary"
      :loading="list.loadingMore.value"
      @click="list.loadMore"
      >{{ t('ui.load_more') }}</AppButton
    >

    <section v-if="deletedTeams.length > 0" aria-labelledby="deleted-teams" class="mt-10 flex flex-col gap-3">
      <h2 id="deleted-teams" class="text-heading font-semibold">{{ t('trash.deleted_teams') }}</h2>
      <p class="text-small text-text-muted">{{ t('trash.teams_hint') }}</p>
      <AppAlert v-if="restore.message.value" variant="error">{{ restore.message.value }}</AppAlert>
      <ul class="flex flex-col divide-y rounded-card border bg-surface">
        <li v-for="team in deletedTeams" :key="team.id" class="flex flex-wrap items-center gap-3 p-4">
          <div class="min-w-0 flex-1">
            <p class="truncate font-medium">{{ team.name }}</p>
            <p class="text-small text-text-muted">
              {{ t('trash.recoverable_until', { date: format.dateTime(team.recoverable_until) }) }}
            </p>
          </div>
          <AppButton variant="secondary" size="sm" :loading="restore.processing.value" @click="restoreTeam(team)">
            {{ t('trash.restore') }}
          </AppButton>
        </li>
      </ul>
    </section>
  </WorkspaceLayout>
</template>
