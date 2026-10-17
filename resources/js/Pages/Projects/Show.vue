<script setup lang="ts">
import { Settings } from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import TaskBoard from '@/Components/Board/TaskBoard.vue';
import TaskFiltersBar from '@/Components/Board/TaskFiltersBar.vue';
import TaskListView from '@/Components/Board/TaskListView.vue';
import AppBadge from '@/Components/ui/AppBadge.vue';
import ButtonLink from '@/Components/ui/ButtonLink.vue';
import TechnicalId from '@/Components/ui/TechnicalId.vue';
import LockBanner from '@/Components/Workspace/LockBanner.vue';
import PageHeader from '@/Components/Workspace/PageHeader.vue';
import VisibilityBadge from '@/Components/Workspace/VisibilityBadge.vue';
import WorkspaceLayout from '@/Components/Workspace/WorkspaceLayout.vue';
import { useI18n } from '@/i18n';
import { pages } from '@/lib/routes';
import { readQuery, writeQuery, type ListSort, type ProjectView, type TaskFilters } from '@/lib/taskFilters';
import type {
  Assignee,
  Label,
  Lock,
  Project,
  ProjectAbilities,
  ProjectStatus,
  Team,
  TeamAbilities,
} from '@/types/domain';

/**
 * `/t/{team_slug}/p/{project_key}`: the project's Board and List (CORE-007, DESIGN_SYSTEM.md §5.3).
 *
 * - The view, the filters and the List's sort live in the query string (`?view=list&priority=high…`), so a
 *   filtered view can be shared; the server applies every filter inside the viewer's own access.
 * - Without `?view`, the project's last view on this device is used (DESIGN_SYSTEM.md §16: "a user's
 *   explicit view choice persists per project"), else Board on wider screens and List on phones.
 */
const props = defineProps<{
  team: Team;
  project: Project;
  statuses: ProjectStatus[];
  labels: Label[];
  assignees: Assignee[];
  assigneesTruncated: boolean;
  can: ProjectAbilities;
  teamCan: TeamAbilities;
  lock: Lock;
}>();

const { t } = useI18n();
const initial = readQuery(window.location.search);
const viewKey = `sn3.projectView.${props.project.id}`;

function storedView(): ProjectView | null {
  try {
    const value = window.localStorage.getItem(viewKey);

    return value === 'board' || value === 'list' ? value : null;
  } catch {
    return null;
  }
}

const view = ref<ProjectView>(
  initial.view ?? storedView() ?? (window.matchMedia('(min-width: 48rem)').matches ? 'board' : 'list'),
);
const filters = ref<TaskFilters>(initial.filters);
const sort = ref<ListSort>(initial.sort);
const locked = ref(props.lock !== null);
const canWrite = computed(() => props.can.contribute && !locked.value);

const labelsById = computed(() => new Map(props.labels.map((label) => [label.id, label])));
const assigneeNames = computed(() => new Map(props.assignees.map((person) => [person.user_id, person.name])));

function syncUrl(): void {
  router.replace({
    url: `${window.location.pathname}?${writeQuery(filters.value, view.value, sort.value)}`,
    preserveState: true,
    preserveScroll: true,
  });
}

function choose(next: ProjectView): void {
  view.value = next;

  try {
    window.localStorage.setItem(viewKey, next);
  } catch {
    // Storage blocked: the choice still holds for this visit.
  }
}

onMounted(syncUrl);
watch([view, sort, filters], syncUrl, { deep: true });

// Tabs (WAI-ARIA tabs pattern, automatic activation): arrows move to the other tab, Home and End to the
// first and last. With two tabs, both arrows lead to the other one, in either direction.
function onTabKey(event: KeyboardEvent): void {
  const next: ProjectView | null =
    event.key === 'Home'
      ? 'board'
      : event.key === 'End'
        ? 'list'
        : event.key === 'ArrowLeft' || event.key === 'ArrowRight'
          ? view.value === 'board'
            ? 'list'
            : 'board'
          : null;

  if (next !== null) {
    event.preventDefault();
    choose(next);
    document.getElementById(`project-view-${next}`)?.focus();
  }
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

    <section aria-labelledby="project-tasks" class="flex flex-col gap-4">
      <h2 id="project-tasks" class="sr-only">{{ t('task.list.title') }}</h2>
      <div role="tablist" :aria-label="t('board.views.label')" class="flex gap-1 border-b" @keydown="onTabKey">
        <button
          v-for="option in ['board', 'list'] as const"
          :id="`project-view-${option}`"
          :key="option"
          type="button"
          role="tab"
          :aria-selected="view === option"
          :aria-controls="view === option ? 'project-panel' : undefined"
          :tabindex="view === option ? 0 : -1"
          class="-mb-px border-b-2 px-4 py-2 text-body font-medium"
          :class="
            view === option ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text'
          "
          @click="choose(option)"
        >
          {{ t(`board.views.${option}`) }}
        </button>
      </div>

      <TaskFiltersBar
        v-model="filters"
        :labels="labels"
        :assignees="assignees"
        :assignees-truncated="assigneesTruncated"
        :statuses="statuses"
        :show-status="view === 'list'"
      />

      <div id="project-panel" role="tabpanel" :aria-labelledby="`project-view-${view}`">
        <TaskBoard
          v-if="view === 'board'"
          :project-id="project.id"
          :statuses="statuses"
          :filters="filters"
          :labels-by-id="labelsById"
          :assignee-names="assigneeNames"
          :can-write="canWrite"
          @locked="locked = true"
        />
        <TaskListView
          v-else
          v-model:sort="sort"
          :project-id="project.id"
          :project-name="project.name"
          :statuses="statuses"
          :filters="filters"
          :labels-by-id="labelsById"
          :assignee-names="assigneeNames"
          :can-write="canWrite"
        />
      </div>
    </section>
  </WorkspaceLayout>
</template>
