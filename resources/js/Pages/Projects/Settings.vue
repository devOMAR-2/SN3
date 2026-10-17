<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, useId, watch } from 'vue';
import ProjectMembersPanel from '@/Components/Projects/ProjectMembersPanel.vue';
import WorkflowEditor from '@/Components/Projects/WorkflowEditor.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import TextAreaField from '@/Components/ui/TextAreaField.vue';
import TextField from '@/Components/ui/TextField.vue';
import LockBanner from '@/Components/Workspace/LockBanner.vue';
import PageHeader from '@/Components/Workspace/PageHeader.vue';
import WorkspaceLayout from '@/Components/Workspace/WorkspaceLayout.vue';
import { useAction } from '@/Composables/useAction';
import { useApiForm } from '@/Composables/useApiForm';
import { invalidateNavProjects } from '@/Composables/useNavProjects';
import { showToast } from '@/Composables/useToast';
import { useI18n } from '@/i18n';
import { api, type ApiEnvelope, type ApiError } from '@/lib/api';
import { ifMatch } from '@/lib/http';
import { pages } from '@/lib/routes';
import type {
  Lock,
  Project,
  ProjectAbilities,
  ProjectSettings,
  ProjectStatus,
  Team,
  TeamAbilities,
  Visibility,
} from '@/types/domain';

/**
 * `/t/{team_slug}/p/{project_key}/settings` (CORE-006): general fields and visibility, the sprints switch,
 * workflow statuses, explicit members and the danger zone (archive / unarchive / delete to the trash).
 * Project writes send `If-Match` with the project version (API_SPEC.md §1.4); a 412 asks to reload.
 */
const props = defineProps<{
  team: Team;
  project: Project;
  statuses: ProjectStatus[];
  settings: ProjectSettings;
  workflowVersion: number;
  can: ProjectAbilities;
  teamCan: TeamAbilities;
  lock: Lock;
}>();

const { t } = useI18n();
const version = ref(props.project.version);
watch(
  () => props.project.version,
  (value) => (version.value = value),
);
const conflict = ref(false);

// A write answered 423 (the project or its team was archived meanwhile): show the read-only banner at
// once, and reload the server's abilities so the editors disappear.
const lockedNow = ref(false);

function onLocked(): void {
  lockedNow.value = true;
  archiveOpen.value = false;
  deleteOpen.value = false;
  router.reload();
}

function lockedBy(error: ApiError | null): boolean {
  if (error?.status !== 423) {
    return false;
  }

  onLocked();

  return true;
}
const visibilityName = `visibility-${useId()}`;

function reload(): void {
  conflict.value = false;
  router.reload();
}

// General.
const form = useApiForm('project', {
  name: props.project.name,
  key: props.project.key,
  description: props.project.description ?? '',
  visibility: props.project.visibility as Visibility,
});
const becomesPrivate = computed(() => props.project.visibility === 'team' && form.data.visibility === 'private');

// A frozen key is sent unchanged, which the API accepts as a no-op (ADR-021 §5).
async function save(): Promise<void> {
  conflict.value = false;
  const updated = await form.submit<Project>('patch', `/projects/${props.project.id}`, {
    headers: ifMatch(version.value),
  });

  if (!updated) {
    conflict.value = form.error.value?.status === 412;
    lockedBy(form.error.value);

    return;
  }

  version.value = updated.version;
  invalidateNavProjects(props.team.id);
  showToast(t('ui.saved'));
  router.visit(pages.projectSettings(props.team.slug, updated.key), { replace: true, preserveScroll: true });
}

// Sprints (FLEX-005 adds the sprint screens; this is the project switch).
const sprints = useAction();
const sprintsEnabled = ref(props.settings.sprints_enabled);

async function toggleSprints(): Promise<void> {
  const next = !sprintsEnabled.value;
  const updated = await sprints.run(
    async () =>
      (
        await api.patch<ApiEnvelope<ProjectSettings>>(
          `/projects/${props.project.id}/settings`,
          { sprints_enabled: next },
          {
            headers: ifMatch(version.value),
          },
        )
      ).data.data,
  );

  if (updated) {
    sprintsEnabled.value = updated.sprints_enabled;
    version.value = updated.version;
    showToast(t('ui.saved'));
  } else {
    conflict.value = sprints.error.value?.status === 412;
    lockedBy(sprints.error.value);
  }
}

// Lifecycle.
const lifecycle = useAction();
const archiveOpen = ref(false);
const deleteOpen = ref(false);

async function setArchived(archived: boolean): Promise<void> {
  const done = await lifecycle.run(() =>
    api.post(`/projects/${props.project.id}/${archived ? 'archive' : 'restore'}`, undefined, {
      headers: ifMatch(version.value),
    }),
  );

  if (done !== null) {
    archiveOpen.value = false;
    showToast(t(archived ? 'project.danger.archived' : 'project.danger.unarchived'));
    router.reload();
  } else {
    conflict.value = lifecycle.error.value?.status === 412;
    lockedBy(lifecycle.error.value);
  }
}

async function confirmDelete(): Promise<void> {
  const done = await lifecycle.run(() =>
    api.delete(`/projects/${props.project.id}`, { headers: ifMatch(version.value) }),
  );

  if (done !== null) {
    deleteOpen.value = false;
    invalidateNavProjects(props.team.id);
    showToast(t('project.danger.deleted'));
    router.visit(pages.projects(props.team.slug));
  } else {
    conflict.value = lifecycle.error.value?.status === 412;
    lockedBy(lifecycle.error.value);
  }
}

function openDelete(): void {
  lifecycle.clear();
  deleteOpen.value = true;
}
</script>

<template>
  <WorkspaceLayout
    :title="t('project.settings.title')"
    :team="team"
    :team-can="teamCan"
    :project-id="project.id"
    :breadcrumbs="[
      { label: team.name, href: pages.team(team.slug) },
      { label: project.name, href: pages.project(team.slug, project.key) },
      { label: t('project.settings.title') },
    ]"
  >
    <LockBanner :lock="lock ?? (lockedNow ? 'read_only' : null)" />
    <PageHeader :title="t('project.settings.title')" :subtitle="project.name" />

    <AppAlert v-if="conflict" variant="warning" :title="t('conflict.title')" class="mb-6">
      {{ t('conflict.project') }}
      <AppButton class="mt-2 block" variant="secondary" size="sm" @click="reload">{{ t('conflict.reload') }}</AppButton>
    </AppAlert>

    <section v-if="can.update" aria-labelledby="project-general" class="mb-10 flex max-w-xl flex-col gap-4">
      <h2 id="project-general" class="text-heading font-semibold">{{ t('project.settings.general') }}</h2>
      <form class="flex flex-col gap-4" novalidate @submit.prevent="save">
        <AppAlert v-if="form.message.value && !conflict" variant="error">{{ form.message.value }}</AppAlert>
        <TextField
          v-model="form.data.name"
          :label="t('project.fields.name')"
          maxlength="180"
          required
          dir="auto"
          :error="form.errors.name"
        />
        <TextField
          v-model="form.data.key"
          :label="t('project.fields.key')"
          :hint="settings.key_frozen ? t('project.key_frozen') : t('project.key_hint')"
          :disabled="settings.key_frozen"
          maxlength="10"
          dir="ltr"
          autocomplete="off"
          class="uppercase"
          :error="form.errors.key"
        />
        <TextAreaField
          v-model="form.data.description"
          :label="t('project.fields.description')"
          :maxlength="5000"
          :rows="3"
          :error="form.errors.description"
        />
        <fieldset class="flex flex-col gap-2">
          <legend class="mb-1 text-small font-medium">{{ t('project.fields.visibility') }}</legend>
          <label
            v-for="option in ['team', 'private'] as const"
            :key="option"
            class="flex cursor-pointer items-start gap-3 rounded-control border border-border-control p-3 has-checked:border-primary"
          >
            <input
              v-model="form.data.visibility"
              type="radio"
              :name="visibilityName"
              :value="option"
              class="mt-1 size-4 accent-(--primary)"
            />
            <span>
              <span class="block font-medium">{{ t(`project.visibility.${option}`) }}</span>
              <span class="block text-small text-text-muted">{{ t(`project.visibility_help.${option}`) }}</span>
            </span>
          </label>
        </fieldset>
        <AppAlert v-if="becomesPrivate" variant="warning">{{ t('project.becomes_private') }}</AppAlert>
        <AppButton type="submit" class="self-start" :loading="form.processing.value">{{ t('ui.save') }}</AppButton>
      </form>
    </section>

    <section v-if="can.update" aria-labelledby="project-sprints" class="mb-10 flex max-w-xl flex-col gap-3">
      <h2 id="project-sprints" class="text-heading font-semibold">{{ t('project.settings.sprints') }}</h2>
      <p class="text-small text-text-muted">{{ t('project.settings.sprints_help') }}</p>
      <AppAlert v-if="sprints.message.value && !conflict" variant="error">{{ sprints.message.value }}</AppAlert>
      <button
        type="button"
        role="switch"
        :aria-checked="sprintsEnabled ? 'true' : 'false'"
        :disabled="sprints.processing.value"
        class="flex items-center gap-3 self-start rounded-control py-1"
        @click="toggleSprints"
      >
        <span
          class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-pill border border-border-control transition-colors duration-(--motion-hover)"
          :class="sprintsEnabled ? 'bg-primary' : 'bg-surface-muted'"
          aria-hidden="true"
        >
          <span
            class="absolute size-4 rounded-pill bg-surface shadow-panel transition-[inset-inline-start] duration-(--motion-hover)"
            :class="sprintsEnabled ? 'start-6' : 'start-1'"
          />
        </span>
        <span class="font-medium">{{ t('project.settings.sprints_switch') }}</span>
        <span class="text-small text-text-muted">{{ sprintsEnabled ? t('ui.on') : t('ui.off') }}</span>
      </button>
    </section>

    <section aria-labelledby="project-workflow" class="mb-10 flex flex-col gap-3">
      <h2 id="project-workflow" class="text-heading font-semibold">{{ t('workflow.title') }}</h2>
      <p class="text-small text-text-muted">{{ t('workflow.help') }}</p>
      <WorkflowEditor
        :project-id="project.id"
        :initial-statuses="statuses"
        :initial-workflow-version="workflowVersion"
        :editable="can.manage_workflow"
        @locked="onLocked"
      />
    </section>

    <section aria-labelledby="project-members" class="mb-10 flex flex-col gap-3">
      <h2 id="project-members" class="text-heading font-semibold">{{ t('nav.members') }}</h2>
      <ProjectMembersPanel
        :project-id="project.id"
        :team-id="team.id"
        :editable="can.manage_members"
        :is-private="project.visibility === 'private'"
        @locked="onLocked"
      />
    </section>

    <section
      v-if="can.archive || can.unarchive || can.delete"
      aria-labelledby="project-danger"
      class="flex max-w-xl flex-col gap-4 rounded-card border border-danger/40 p-4"
    >
      <h2 id="project-danger" class="text-heading font-semibold text-danger">{{ t('ui.danger_zone') }}</h2>
      <AppAlert v-if="lifecycle.message.value && !archiveOpen && !deleteOpen && !conflict" variant="error">{{
        lifecycle.message.value
      }}</AppAlert>
      <div v-if="can.archive" class="flex flex-col gap-2">
        <p class="text-small text-text-muted">{{ t('project.danger.archive_body') }}</p>
        <AppButton class="self-start" variant="secondary" @click="archiveOpen = true">{{
          t('project.danger.archive')
        }}</AppButton>
      </div>
      <div v-if="can.unarchive" class="flex flex-col gap-2">
        <p class="text-small text-text-muted">{{ t('project.danger.unarchive_body') }}</p>
        <AppButton
          class="self-start"
          variant="secondary"
          :loading="lifecycle.processing.value"
          @click="setArchived(false)"
        >
          {{ t('project.danger.unarchive') }}
        </AppButton>
      </div>
      <div v-if="can.delete" class="flex flex-col gap-2">
        <p class="text-small text-text-muted">{{ t('project.danger.delete_body') }}</p>
        <AppButton class="self-start" variant="danger" @click="openDelete">{{ t('project.danger.delete') }}</AppButton>
      </div>
    </section>

    <ConfirmDialog
      v-model:open="archiveOpen"
      :title="t('project.danger.archive_title', { project: project.name })"
      :description="t('project.danger.archive_body')"
      :confirm-label="t('project.danger.archive')"
      variant="primary"
      :loading="lifecycle.processing.value"
      :error="lifecycle.message.value"
      @confirm="setArchived(true)"
    />
    <ConfirmDialog
      v-model:open="deleteOpen"
      :title="t('project.danger.delete_title', { project: project.name })"
      :description="t('project.danger.delete_dialog_body')"
      :confirm-label="t('project.danger.delete')"
      :confirm-text="project.key"
      :loading="lifecycle.processing.value"
      :error="lifecycle.message.value"
      @confirm="confirmDelete"
    />
  </WorkspaceLayout>
</template>
