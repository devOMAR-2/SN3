<script setup lang="ts">
import { X } from '@lucide/vue';
import { Link, router } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref, useTemplateRef } from 'vue';
import QuickAddTask from '@/Components/Tasks/QuickAddTask.vue';
import StatusChip from '@/Components/Tasks/StatusChip.vue';
import TaskChecklist from '@/Components/Tasks/TaskChecklist.vue';
import TaskLabels from '@/Components/Tasks/TaskLabels.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import ButtonLink from '@/Components/ui/ButtonLink.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import SelectField from '@/Components/ui/SelectField.vue';
import TechnicalId from '@/Components/ui/TechnicalId.vue';
import TextAreaField from '@/Components/ui/TextAreaField.vue';
import TextField from '@/Components/ui/TextField.vue';
import LockBanner from '@/Components/Workspace/LockBanner.vue';
import WorkspaceLayout from '@/Components/Workspace/WorkspaceLayout.vue';
import { useAction } from '@/Composables/useAction';
import { useFormat } from '@/Composables/useFormat';
import { showToast } from '@/Composables/useToast';
import { hasTranslation, useI18n } from '@/i18n';
import { api, toApiError, type ApiEnvelope, type ApiError } from '@/lib/api';
import { ifMatch, type WriteOutcome } from '@/lib/http';
import { pages } from '@/lib/routes';
import { createTaskEditor, type TaskChanges } from '@/lib/taskEditor';
import {
  priorities,
  type Assignee,
  type ChecklistItem,
  type Label,
  type Lock,
  type Project,
  type ProjectStatus,
  type Task,
  type TaskAbilities,
  type TaskSummary,
  type Team,
  type TeamAbilities,
} from '@/types/domain';

/**
 * `/tasks/{task}`: task detail (CORE-006; DESIGN_SYSTEM.md §3.4, §5.2). Title and description use an explicit
 * Save; status, assignee, priority, due date and labels save on change with a visible saving/saved/failed
 * state and roll back on failure. Every edit is a PATCH with `If-Match` (lib/taskEditor): a 412 shows a
 * reload prompt and keeps the unsaved text (API_SPEC.md §14.3), a 423 shows the read-only banner.
 * On mobile the page is a full-screen sheet with a sticky header and Close.
 */
const props = defineProps<{
  team: Team;
  project: Project;
  task: Task;
  statuses: ProjectStatus[];
  labels: Label[];
  checklist: ChecklistItem[];
  assignees: Assignee[];
  parent: TaskSummary | null;
  subtasks: TaskSummary[];
  can: TaskAbilities;
  teamCan: TeamAbilities;
  lock: Lock;
}>();

const { t, locale } = useI18n();
const format = useFormat();

const task = ref<Task>(props.task);
const editor = createTaskEditor(api, props.task, (next) => {
  task.value = next;
  syncFields();
});

// Auto-saved fields mirror the task; on failure they snap back to the stored value.
const fields = reactive({
  status_id: props.task.status_id,
  assignee_user_id: props.task.assignee_user_id ?? '',
  priority: props.task.priority as string,
  due_date: props.task.due_date ?? '',
});

function syncFields(): void {
  fields.status_id = task.value.status_id;
  fields.assignee_user_id = task.value.assignee_user_id ?? '';
  fields.priority = task.value.priority;
  fields.due_date = task.value.due_date ?? '';
}

const projectLabels = ref<Label[]>([...props.labels]);
const statusById = computed(() => new Map(props.statuses.map((status) => [status.id, status])));
const assigneeName = computed(
  () => props.assignees.find((a) => a.user_id === task.value.assignee_user_id)?.name ?? null,
);

// Page-level state after a failed write.
const conflict = ref(false);
const lockedNow = ref(false);
const gone = ref(false);
const canEdit = computed(() => props.can.edit && !lockedNow.value && !gone.value);
const saving = ref(false);
const saveState = ref<'idle' | 'saving' | 'saved' | 'failed'>('idle');
const saveError = ref<string | null>(null);

function errorMessage(error: ApiError): string {
  if (error.code === 'network_error') {
    return t('validation.network_error');
  }

  const first = Object.entries(error.fields)[0];

  if (error.status === 422 && first) {
    const [field, codes] = first;
    const specific = `task.errors.${field}.${codes[0] ?? ''}`;

    return hasTranslation(locale.value, specific)
      ? t(specific)
      : t('task.errors.invalid', { field: t(`task.fields.${field}`) });
  }

  return error.message;
}

function onFailure(outcome: WriteOutcome, error: ApiError): void {
  syncFields();
  saveState.value = 'failed';
  saveError.value = null;

  if (outcome === 'conflict') {
    conflict.value = true;
  } else if (outcome === 'locked') {
    lockedNow.value = true;
  } else if (outcome === 'gone') {
    gone.value = true;
  } else {
    saveError.value = errorMessage(error);
  }
}

async function save(changes: TaskChanges): Promise<boolean> {
  saving.value = true;
  saveState.value = 'saving';
  const result = await editor.save(changes);
  saving.value = false;

  if (result.ok) {
    saveState.value = 'saved';
    saveError.value = null;

    return true;
  }

  onFailure(result.outcome, result.error);

  return false;
}

const reloading = ref(false);

async function reloadTask(): Promise<void> {
  reloading.value = true;

  try {
    const response = await api.get<ApiEnvelope<Task>>(`/tasks/${task.value.id}`);
    editor.reset(response.data.data);
    conflict.value = false;
    saveState.value = 'idle';
  } catch (thrown) {
    if (toApiError(thrown).status === 404) {
      gone.value = true;
      conflict.value = false;
    } else {
      saveError.value = errorMessage(toApiError(thrown));
    }
  } finally {
    reloading.value = false;
  }
}

// Title (explicit Save; Enter saves, Escape cancels).
const editingTitle = ref(false);
const titleDraft = ref('');
const titleButton = useTemplateRef<InstanceType<typeof AppButton>>('titleButton');

function startTitle(): void {
  titleDraft.value = task.value.title;
  editingTitle.value = true;
}

async function saveTitle(): Promise<void> {
  const title = titleDraft.value.trim();

  // An empty title stays in the editor (the field is required); an unchanged one just closes it.
  if (title === '') {
    return;
  }

  if (title === task.value.title) {
    editingTitle.value = false;

    return;
  }

  if (await save({ title })) {
    editingTitle.value = false;
    await nextTick();
    (titleButton.value?.$el as HTMLElement | undefined)?.focus();
  }
}

// Description (explicit Save; plain text with line breaks, never rendered as HTML).
const editingDescription = ref(false);
const descriptionDraft = ref('');

function startDescription(): void {
  descriptionDraft.value = task.value.description ?? '';
  editingDescription.value = true;
}

async function saveDescription(): Promise<void> {
  const description = descriptionDraft.value.trim() === '' ? null : descriptionDraft.value;

  if (description === task.value.description || (await save({ description }))) {
    editingDescription.value = false;
  }
}

// Auto-saved fields.
function saveField<K extends 'status_id' | 'assignee_user_id' | 'priority' | 'due_date'>(field: K): void {
  const value = fields[field];
  const changes: TaskChanges = {};

  if (field === 'status_id') changes.status_id = value as string;
  if (field === 'priority') changes.priority = value as Task['priority'];
  if (field === 'assignee_user_id') changes.assignee_user_id = value === '' ? null : (value as string);
  if (field === 'due_date') changes.due_date = value === '' ? null : (value as string);

  void save(changes);
}

function clearDueDate(): void {
  fields.due_date = '';
  saveField('due_date');
}

function onLabels(ids: string[]): void {
  void save({ label_ids: ids });
}

function onLabelCreated(label: Label): void {
  projectLabels.value = [...projectLabels.value, label].sort((a, b) => a.name.localeCompare(b.name));
}

// Subtasks.
const subtasks = ref<TaskSummary[]>([...props.subtasks]);

function onSubtask(created: Task): void {
  subtasks.value = [
    ...subtasks.value,
    { id: created.id, key: created.key, title: created.title, status_id: created.status_id },
  ];
  showToast(t('task.created', { key: created.key }));
}

// Delete (soft delete; restore is for the Owner and project managers).
const removeOpen = ref(false);
const removal = useAction();

async function confirmDelete(): Promise<void> {
  const done = await removal.run(() =>
    api.delete(`/tasks/${task.value.id}`, { headers: ifMatch(editor.current().version) }),
  );

  if (done !== null) {
    removeOpen.value = false;
    showToast(t('task.deleted', { key: task.value.key }));
    router.visit(pages.project(props.team.slug, props.project.key));
  } else if (removal.error.value?.status === 412) {
    removeOpen.value = false;
    conflict.value = true;
  }
}

const projectUrl = computed(() => pages.project(props.team.slug, props.project.key));
</script>

<template>
  <WorkspaceLayout
    :title="`${task.key} ${task.title}`"
    :team="team"
    :team-can="teamCan"
    :project-id="project.id"
    :breadcrumbs="[
      { label: team.name, href: pages.team(team.slug) },
      { label: project.name, href: projectUrl },
      { label: task.key, technical: true },
    ]"
  >
    <article class="flex flex-col gap-6" :aria-labelledby="'task-title'">
      <header
        class="sticky top-topbar z-20 -mx-4 flex items-start gap-3 border-b bg-bg px-4 py-3 md:static md:mx-0 md:border-0 md:p-0"
      >
        <div class="min-w-0 flex-1">
          <p class="flex flex-wrap items-center gap-2 text-small text-text-muted">
            <TechnicalId>{{ task.key }}</TechnicalId>
            <template v-if="parent">
              <span aria-hidden="true">·</span>
              <span>{{ t('task.subtask_of') }}</span>
              <Link :href="pages.task(parent.id)" class="hover:underline"
                ><TechnicalId>{{ parent.key }}</TechnicalId></Link
              >
            </template>
          </p>
          <form
            v-if="editingTitle"
            class="mt-1 flex flex-wrap items-end gap-2"
            novalidate
            @submit.prevent="saveTitle"
            @keydown.escape="editingTitle = false"
          >
            <div class="min-w-0 flex-1 basis-64">
              <TextField v-model="titleDraft" :label="t('task.fields.title')" maxlength="200" required dir="auto" />
            </div>
            <AppButton type="submit" :loading="saving">{{ t('ui.save') }}</AppButton>
            <AppButton variant="ghost" @click="editingTitle = false">{{ t('ui.cancel') }}</AppButton>
          </form>
          <div v-else class="mt-1 flex flex-wrap items-start gap-2">
            <h1 id="task-title" class="min-w-0 flex-1 text-title font-semibold break-words" dir="auto">
              {{ task.title }}
            </h1>
            <AppButton v-if="canEdit" ref="titleButton" variant="ghost" size="sm" @click="startTitle">{{
              t('task.edit_title')
            }}</AppButton>
          </div>
        </div>
        <ButtonLink :href="projectUrl" variant="ghost" size="sm" :aria-label="t('task.close')">
          <X class="size-4" aria-hidden="true" /><span class="max-sm:sr-only">{{ t('ui.close') }}</span>
        </ButtonLink>
      </header>

      <LockBanner :lock="lock ?? (lockedNow ? 'read_only' : null)" />
      <AppAlert v-if="conflict" variant="warning" :title="t('conflict.title')">
        <p>{{ t('conflict.task') }}</p>
        <AppButton class="mt-2" variant="secondary" size="sm" :loading="reloading" @click="reloadTask">{{
          t('conflict.reload')
        }}</AppButton>
      </AppAlert>
      <AppAlert v-if="gone" variant="error" :title="t('task.gone_title')">
        {{ t('task.gone_body') }}
        <Link :href="projectUrl" class="font-medium underline">{{ t('task.back_to_project') }}</Link>
      </AppAlert>
      <AppAlert v-if="saveError" variant="error">{{ saveError }}</AppAlert>
      <p class="sr-only" role="status" aria-live="polite">
        {{ saveState === 'saving' ? t('task.saving') : saveState === 'saved' ? t('task.saved') : '' }}
      </p>

      <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_18rem]">
        <div class="flex min-w-0 flex-col gap-8">
          <section aria-labelledby="task-description" class="flex flex-col gap-2">
            <div class="flex items-center justify-between gap-2">
              <h2 id="task-description" class="text-heading font-semibold">{{ t('task.fields.description') }}</h2>
              <AppButton v-if="canEdit && !editingDescription" variant="ghost" size="sm" @click="startDescription">{{
                t('ui.edit')
              }}</AppButton>
            </div>
            <form v-if="editingDescription" class="flex flex-col gap-2" novalidate @submit.prevent="saveDescription">
              <TextAreaField
                v-model="descriptionDraft"
                :label="t('task.fields.description')"
                :maxlength="50000"
                :rows="8"
              />
              <div class="flex gap-2">
                <AppButton type="submit" :loading="saving">{{ t('ui.save') }}</AppButton>
                <AppButton variant="ghost" @click="editingDescription = false">{{ t('ui.cancel') }}</AppButton>
              </div>
            </form>
            <p v-else-if="task.description" class="text-body break-words whitespace-pre-wrap" dir="auto">
              {{ task.description }}
            </p>
            <p v-else class="text-small text-text-muted">{{ t('task.no_description') }}</p>
          </section>

          <TaskChecklist :task-id="task.id" :initial-items="checklist" :editable="canEdit" @locked="lockedNow = true" />

          <section aria-labelledby="task-subtasks" class="flex flex-col gap-3">
            <h2 id="task-subtasks" class="text-heading font-semibold">{{ t('task.subtasks') }}</h2>
            <p v-if="subtasks.length === 0" class="text-small text-text-muted">{{ t('task.no_subtasks') }}</p>
            <ul v-else class="flex flex-col divide-y rounded-card border bg-surface">
              <li v-for="subtask in subtasks" :key="subtask.id">
                <Link
                  :href="pages.task(subtask.id)"
                  class="flex flex-wrap items-center gap-3 p-3 hover:bg-surface-hover"
                >
                  <TechnicalId class="text-small text-text-muted">{{ subtask.key }}</TechnicalId>
                  <span class="min-w-0 flex-1 break-words" dir="auto">{{ subtask.title }}</span>
                  <StatusChip :status="statusById.get(subtask.status_id)" />
                </Link>
              </li>
            </ul>
            <QuickAddTask
              v-if="canEdit"
              :project-id="project.id"
              :parent-task-id="task.id"
              :label="t('task.add_subtask')"
              @created="onSubtask"
            />
          </section>

          <!--
            EXTENSION POINT (CORE-008): comments, activity and attachments mount here, e.g.
            <TaskComments :task-id="task.id" :can-comment="can.edit" />. CORE-006 builds none of them.
          -->
          <div data-extension-point="task-collaboration" class="empty:hidden"></div>
        </div>

        <aside :aria-label="t('task.details')" class="flex flex-col gap-4 lg:border-s lg:ps-6">
          <h2 class="sr-only">{{ t('task.details') }}</h2>
          <template v-if="canEdit">
            <SelectField
              v-model="fields.status_id"
              :label="t('task.fields.status_id')"
              :disabled="saving"
              @change="saveField('status_id')"
            >
              <option v-for="status in statuses" :key="status.id" :value="status.id">{{ status.name }}</option>
            </SelectField>
            <SelectField
              v-model="fields.assignee_user_id"
              :label="t('task.fields.assignee_user_id')"
              :disabled="saving"
              @change="saveField('assignee_user_id')"
            >
              <option value="">{{ t('task.unassigned') }}</option>
              <option v-for="person in assignees" :key="person.user_id" :value="person.user_id">
                {{ person.name }}
              </option>
            </SelectField>
            <SelectField
              v-model="fields.priority"
              :label="t('task.fields.priority')"
              :disabled="saving"
              @change="saveField('priority')"
            >
              <option v-for="priority in priorities" :key="priority" :value="priority">
                {{ t(`task.priorities.${priority}`) }}
              </option>
            </SelectField>
            <div class="flex items-end gap-2">
              <div class="min-w-0 flex-1">
                <label for="task-due-date" class="mb-1.5 block text-small font-medium">{{
                  t('task.fields.due_date')
                }}</label>
                <input
                  id="task-due-date"
                  v-model="fields.due_date"
                  type="date"
                  dir="ltr"
                  :disabled="saving"
                  class="h-9 w-full rounded-control border border-border-control bg-surface px-2 text-body pointer-coarse:h-11"
                  :aria-describedby="fields.due_date ? 'task-due-date-full' : undefined"
                  @change="saveField('due_date')"
                />
              </div>
              <AppButton v-if="fields.due_date" variant="ghost" size="sm" :disabled="saving" @click="clearDueDate">{{
                t('task.clear_due_date')
              }}</AppButton>
            </div>
            <p v-if="task.due_date" id="task-due-date-full" class="-mt-2 text-caption text-text-muted">
              {{ format.date(task.due_date) }}
            </p>
          </template>
          <dl v-else class="flex flex-col gap-3">
            <div>
              <dt class="text-small text-text-muted">{{ t('task.fields.status_id') }}</dt>
              <dd><StatusChip :status="statusById.get(task.status_id)" /></dd>
            </div>
            <div>
              <dt class="text-small text-text-muted">{{ t('task.fields.assignee_user_id') }}</dt>
              <dd dir="auto">{{ assigneeName ?? t('task.unassigned') }}</dd>
            </div>
            <div>
              <dt class="text-small text-text-muted">{{ t('task.fields.priority') }}</dt>
              <dd>{{ t(`task.priorities.${task.priority}`) }}</dd>
            </div>
            <div>
              <dt class="text-small text-text-muted">{{ t('task.fields.due_date') }}</dt>
              <dd>{{ task.due_date ? format.date(task.due_date) : t('task.no_due_date') }}</dd>
            </div>
          </dl>

          <div class="flex flex-col gap-1.5">
            <h3 class="text-small font-medium">{{ t('task.fields.label_ids') }}</h3>
            <TaskLabels
              :project-id="project.id"
              :labels="projectLabels"
              :selected="task.label_ids"
              :editable="canEdit"
              :can-create="can.manage_labels && canEdit"
              :saving="saving"
              @change="onLabels"
              @created="onLabelCreated"
            />
          </div>

          <p class="text-caption text-text-muted">
            {{
              saveState === 'saving'
                ? t('task.saving')
                : saveState === 'saved'
                  ? t('task.saved')
                  : saveState === 'failed'
                    ? t('task.save_failed')
                    : ''
            }}
          </p>
          <p class="text-caption text-text-subtle">
            {{ t('task.updated_at', { date: format.dateTime(task.updated_at) }) }}
          </p>

          <AppButton v-if="can.delete && canEdit" class="self-start" variant="ghost" @click="removeOpen = true">{{
            t('task.delete')
          }}</AppButton>
        </aside>
      </div>
    </article>

    <ConfirmDialog
      v-model:open="removeOpen"
      :title="t('task.delete_title', { key: task.key })"
      :description="t('task.delete_body')"
      :confirm-label="t('task.delete')"
      :loading="removal.processing.value"
      :error="removal.message.value"
      @confirm="confirmDelete"
    />
  </WorkspaceLayout>
</template>
