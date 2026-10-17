<script setup lang="ts">
import { Settings } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import PriorityBadge from '@/Components/Tasks/PriorityBadge.vue';
import QuickAddTask from '@/Components/Tasks/QuickAddTask.vue';
import StatusChip from '@/Components/Tasks/StatusChip.vue';
import AppBadge from '@/Components/ui/AppBadge.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import ButtonLink from '@/Components/ui/ButtonLink.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import LoadError from '@/Components/ui/LoadError.vue';
import SelectField from '@/Components/ui/SelectField.vue';
import SkeletonRows from '@/Components/ui/SkeletonRows.vue';
import TechnicalId from '@/Components/ui/TechnicalId.vue';
import LockBanner from '@/Components/Workspace/LockBanner.vue';
import PageHeader from '@/Components/Workspace/PageHeader.vue';
import VisibilityBadge from '@/Components/Workspace/VisibilityBadge.vue';
import WorkspaceLayout from '@/Components/Workspace/WorkspaceLayout.vue';
import { useCursorList } from '@/Composables/useCursorList';
import { useFormat } from '@/Composables/useFormat';
import { showToast } from '@/Composables/useToast';
import { useI18n } from '@/i18n';
import { pages } from '@/lib/routes';
import type { Lock, Project, ProjectAbilities, ProjectStatus, Task, Team, TeamAbilities } from '@/types/domain';

/**
 * `/t/{team_slug}/p/{project_key}`: project header and a deliberately simple task list (CORE-006).
 * The list is GET /api/v1/projects/{project}/tasks in board order with a status filter and paging, just
 * enough to reach and create tasks. CORE-007 replaces this section with the Board and List views.
 */
const props = defineProps<{
  team: Team;
  project: Project;
  statuses: ProjectStatus[];
  can: ProjectAbilities;
  teamCan: TeamAbilities;
  lock: Lock;
}>();

const { t } = useI18n();
const format = useFormat();
const statusFilter = ref('');
const statusById = computed(() => new Map(props.statuses.map((status) => [status.id, status])));

const tasks = useCursorList<Task>(
  () => `/projects/${props.project.id}/tasks`,
  () => ({ sort: 'position', ...(statusFilter.value ? { status_id: [statusFilter.value] } : {}) }),
  50,
);

onMounted(() => void tasks.reload());
watch(statusFilter, () => void tasks.reload());

function onCreated(task: Task): void {
  showToast(t('task.created', { key: task.key }));
  void tasks.reload();
}
</script>

<template>
  <WorkspaceLayout
    :title="project.name"
    :team="team"
    :team-can="teamCan"
    :project-id="project.id"
    :breadcrumbs="[
      { label: team.name, href: pages.team(team.slug) },
      { label: t('nav.projects'), href: pages.projects(team.slug) },
      { label: project.name },
    ]"
  >
    <LockBanner :lock="lock" />
    <PageHeader :title="project.name">
      <template #subtitle>
        <span class="flex flex-wrap items-center gap-2">
          <TechnicalId>{{ project.key }}</TechnicalId>
          <VisibilityBadge :visibility="project.visibility" />
          <AppBadge v-if="project.role">{{ t(`project.roles.${project.role}`) }}</AppBadge>
        </span>
      </template>
      <template #actions>
        <ButtonLink v-if="can.view_settings" variant="secondary" :href="pages.projectSettings(team.slug, project.key)">
          <Settings class="size-4" aria-hidden="true" />{{ t('project.settings.title') }}
        </ButtonLink>
      </template>
    </PageHeader>

    <p v-if="project.description" class="mb-6 text-body whitespace-pre-line text-text-muted" dir="auto">
      {{ project.description }}
    </p>

    <!-- CORE-007 replaces this section with the Board and List views (tabs, drag and drop, filters). -->
    <section aria-labelledby="project-tasks" class="flex flex-col gap-4" data-replaceable="core-007">
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 id="project-tasks" class="text-heading font-semibold">{{ t('task.list.title') }}</h2>
          <p class="text-small text-text-muted">{{ t('task.list.simple_notice') }}</p>
        </div>
        <SelectField v-model="statusFilter" :label="t('task.list.filter_status')" class="w-48">
          <option value="">{{ t('task.list.all_statuses') }}</option>
          <option v-for="status in statuses" :key="status.id" :value="status.id">{{ status.name }}</option>
        </SelectField>
      </div>

      <QuickAddTask v-if="can.contribute" :project-id="project.id" @created="onCreated" />

      <SkeletonRows v-if="tasks.loading.value" />
      <LoadError v-else-if="tasks.error.value" :error="tasks.error.value" @retry="tasks.reload" />
      <EmptyState
        v-else-if="tasks.items.value.length === 0"
        :title="t('empty.tasks')"
        :description="can.contribute ? t('empty.tasks_writer') : undefined"
      />
      <ul v-else class="flex flex-col divide-y rounded-card border bg-surface" :aria-label="t('task.list.title')">
        <li v-for="task in tasks.items.value" :key="task.id">
          <Link
            :href="pages.task(task.id)"
            class="flex flex-wrap items-center gap-x-3 gap-y-1 p-3 hover:bg-surface-hover"
          >
            <TechnicalId class="text-small text-text-muted">{{ task.key }}</TechnicalId>
            <span class="min-w-0 flex-1 basis-48 font-medium break-words" dir="auto">{{ task.title }}</span>
            <StatusChip :status="statusById.get(task.status_id)" />
            <PriorityBadge :priority="task.priority" />
            <span v-if="task.due_date" class="text-small text-text-muted">{{
              t('task.due_on', { date: format.date(task.due_date) })
            }}</span>
          </Link>
        </li>
      </ul>
      <AppButton
        v-if="tasks.hasMore.value"
        class="self-start"
        variant="secondary"
        :loading="tasks.loadingMore.value"
        @click="tasks.loadMore"
      >
        {{ t('ui.load_more') }}
      </AppButton>
    </section>
  </WorkspaceLayout>
</template>
