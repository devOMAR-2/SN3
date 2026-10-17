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
import TechnicalId from '@/Components/ui/TechnicalId.vue';
import LockBanner from '@/Components/Workspace/LockBanner.vue';
import PageHeader from '@/Components/Workspace/PageHeader.vue';
import VisibilityBadge from '@/Components/Workspace/VisibilityBadge.vue';
import WorkspaceLayout from '@/Components/Workspace/WorkspaceLayout.vue';
import { useAction } from '@/Composables/useAction';
import { useCursorList } from '@/Composables/useCursorList';
import { useFormat } from '@/Composables/useFormat';
import { invalidateNavProjects } from '@/Composables/useNavProjects';
import { showToast } from '@/Composables/useToast';
import { useI18n } from '@/i18n';
import { api, type ApiEnvelope } from '@/lib/api';
import { pages } from '@/lib/routes';
import type { Lock, Project, Team, TeamAbilities, TrashedProject } from '@/types/domain';

/**
 * `/t/{team_slug}/projects`: the projects the user can see (GET /api/v1/teams/{team}/projects; private
 * projects only for members and the Owner) and the deleted projects they may still recover.
 */
const props = defineProps<{ team: Team; can: TeamAbilities; lock: Lock; deletedProjects: TrashedProject[] }>();

const { t } = useI18n();
const format = useFormat();
const list = useCursorList<Project>(() => `/teams/${props.team.id}/projects`);
const restore = useAction();

onMounted(() => void list.reload());

async function restoreProject(project: TrashedProject): Promise<void> {
  const restored = await restore.run(
    async () => (await api.post<ApiEnvelope<Project>>(`/projects/${project.id}/restore-deleted`)).data.data,
  );

  if (restored) {
    invalidateNavProjects(props.team.id);
    showToast(t('project.restored'));
    router.visit(pages.project(props.team.slug, restored.key));
  }
}
</script>

<template>
  <WorkspaceLayout
    :title="t('nav.projects')"
    :team="team"
    :team-can="can"
    :breadcrumbs="[{ label: team.name, href: pages.team(team.slug) }, { label: t('nav.projects') }]"
  >
    <LockBanner :lock="lock" />
    <PageHeader :title="t('nav.projects')">
      <template #actions>
        <ButtonLink v-if="can.create_project" :href="pages.newProject(team.slug)">
          <Plus class="size-4" aria-hidden="true" />{{ t('project.create') }}
        </ButtonLink>
      </template>
    </PageHeader>

    <SkeletonRows v-if="list.loading.value" />
    <LoadError v-else-if="list.error.value" :error="list.error.value" @retry="list.reload" />
    <EmptyState
      v-else-if="list.items.value.length === 0"
      :title="t('empty.projects.title')"
      :description="can.create_project ? t('empty.projects.body_creator') : t('empty.projects.body')"
    >
      <ButtonLink v-if="can.create_project" :href="pages.newProject(team.slug)">{{ t('project.create') }}</ButtonLink>
    </EmptyState>
    <ul v-else class="flex flex-col divide-y rounded-card border bg-surface">
      <li v-for="project in list.items.value" :key="project.id">
        <Link
          :href="pages.project(team.slug, project.key)"
          class="flex flex-wrap items-center gap-3 p-4 hover:bg-surface-hover"
        >
          <span class="min-w-0 flex-1">
            <span class="block truncate font-medium" dir="auto">{{ project.name }}</span>
            <TechnicalId class="text-small text-text-muted">{{ project.key }}</TechnicalId>
          </span>
          <VisibilityBadge :visibility="project.visibility" />
          <AppBadge v-if="project.role">{{ t(`project.roles.${project.role}`) }}</AppBadge>
          <AppBadge v-if="project.archived_at" variant="warning">{{ t('project.archived') }}</AppBadge>
        </Link>
      </li>
    </ul>
    <AppButton
      v-if="list.hasMore.value"
      class="mt-3"
      variant="secondary"
      :loading="list.loadingMore.value"
      @click="list.loadMore"
    >
      {{ t('ui.load_more') }}
    </AppButton>

    <section v-if="deletedProjects.length > 0" aria-labelledby="deleted-projects" class="mt-10 flex flex-col gap-3">
      <h2 id="deleted-projects" class="text-heading font-semibold">{{ t('trash.deleted_projects') }}</h2>
      <p class="text-small text-text-muted">{{ t('trash.projects_hint') }}</p>
      <AppAlert v-if="restore.message.value" variant="error">{{ restore.message.value }}</AppAlert>
      <ul class="flex flex-col divide-y rounded-card border bg-surface">
        <li v-for="project in deletedProjects" :key="project.id" class="flex flex-wrap items-center gap-3 p-4">
          <div class="min-w-0 flex-1">
            <p class="truncate font-medium" dir="auto">
              {{ project.name }} <TechnicalId class="text-small text-text-muted">{{ project.key }}</TechnicalId>
            </p>
            <p class="text-small text-text-muted">
              {{ t('trash.recoverable_until', { date: format.dateTime(project.recoverable_until) }) }}
            </p>
          </div>
          <AppButton
            variant="secondary"
            size="sm"
            :disabled="lock !== null"
            :loading="restore.processing.value"
            @click="restoreProject(project)"
            >{{ t('trash.restore') }}</AppButton
          >
        </li>
      </ul>
    </section>
  </WorkspaceLayout>
</template>
