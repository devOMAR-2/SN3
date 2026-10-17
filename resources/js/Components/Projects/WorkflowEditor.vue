<script setup lang="ts">
import { ArrowDown, ArrowUp, Pencil, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import StatusChip from '@/Components/Tasks/StatusChip.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppBadge from '@/Components/ui/AppBadge.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppDialog from '@/Components/ui/AppDialog.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import SelectField from '@/Components/ui/SelectField.vue';
import TextField from '@/Components/ui/TextField.vue';
import { useAction } from '@/Composables/useAction';
import { useApiForm } from '@/Composables/useApiForm';
import { showToast } from '@/Composables/useToast';
import { useI18n } from '@/i18n';
import { api } from '@/lib/api';
import { ifMatch } from '@/lib/http';
import { statusCategories, type CursorPage, type ProjectStatus, type StatusCategory } from '@/types/domain';

/**
 * Workflow statuses (API_SPEC.md §5, ADR-021): reorder with Move up / Move down (keyboard, no drag needed;
 * the new position is announced), rename and recategorise, add, and delete with a replacement status for
 * its tasks. Every write sends `If-Match` (the status version, or the workflow version for the order) and
 * the list is read again afterwards, so a stale view is never written back. FLEX-002 owns the full
 * settings UI (colours, fields, labels).
 */
const props = defineProps<{
  projectId: string;
  initialStatuses: ProjectStatus[];
  initialWorkflowVersion: number;
  editable: boolean;
}>();

const { t } = useI18n();
const statuses = ref<ProjectStatus[]>([...props.initialStatuses]);
const workflowVersion = ref(props.initialWorkflowVersion);
const announcement = ref('');
const conflict = ref(false);
const action = useAction();

async function refresh(): Promise<void> {
  const response = await api.get<CursorPage<ProjectStatus> & { meta: { workflow_version: number } }>(
    `/projects/${props.projectId}/statuses`,
    { params: { limit: 100 } },
  );
  statuses.value = response.data.data;
  workflowVersion.value = response.data.meta.workflow_version;
}

async function afterFailure(): Promise<void> {
  if (action.error.value?.status === 412 || action.error.value?.status === 409) {
    conflict.value = true;
    await refresh().catch(() => undefined);
  }
}

async function move(index: number, offset: -1 | 1): Promise<void> {
  const target = index + offset;
  const order = statuses.value.map((status) => status.id);

  if (target < 0 || target >= order.length) {
    return;
  }

  [order[index], order[target]] = [order[target]!, order[index]!];
  conflict.value = false;
  const moved = statuses.value[index]!;
  const done = await action.run(() =>
    api.put(
      `/projects/${props.projectId}/statuses/order`,
      { status_ids: order },
      { headers: ifMatch(workflowVersion.value) },
    ),
  );

  if (done === null) {
    await afterFailure();

    return;
  }

  await refresh();
  announcement.value = t('workflow.moved', { name: moved.name, position: target + 1, total: order.length });
}

// Add.
const addForm = useApiForm('workflow', { name: '', category: 'todo' as StatusCategory });

async function add(): Promise<void> {
  const created = await addForm.submit<ProjectStatus>('post', `/projects/${props.projectId}/statuses`);

  if (created) {
    addForm.reset();
    await refresh();
    showToast(t('workflow.added', { name: created.name }));
  }
}

// Rename / recategorise.
const editing = ref<ProjectStatus | null>(null);
const editOpen = ref(false);
const editForm = useApiForm('workflow', { name: '', category: 'todo' as StatusCategory });

function startEdit(status: ProjectStatus): void {
  editing.value = status;
  editForm.data.name = status.name;
  editForm.data.category = status.category;
  editForm.message.value = null;
  editOpen.value = true;
}

async function saveEdit(): Promise<void> {
  const status = editing.value;

  if (!status) {
    return;
  }

  const updated = await editForm.submit<ProjectStatus>('patch', `/projects/${props.projectId}/statuses/${status.id}`, {
    headers: ifMatch(status.version),
  });

  if (updated) {
    editOpen.value = false;
    await refresh();
    showToast(t('ui.saved'));
  } else if (editForm.error.value?.status === 412) {
    conflict.value = true;
    editOpen.value = false;
    await refresh().catch(() => undefined);
  }
}

// Delete with replacement.
const removing = ref<ProjectStatus | null>(null);
const removeOpen = ref(false);
const replacement = ref('');
const remove = useAction();
const replacements = computed(() => statuses.value.filter((status) => status.id !== removing.value?.id));

function askRemove(status: ProjectStatus): void {
  removing.value = status;
  replacement.value = '';
  remove.clear();
  removeOpen.value = true;
}

async function confirmRemove(): Promise<void> {
  const status = removing.value;

  if (!status) {
    return;
  }

  const done = await remove.run(() =>
    api.delete(`/projects/${props.projectId}/statuses/${status.id}`, {
      headers: ifMatch(status.version),
      data: replacement.value ? { replacement_status_id: replacement.value } : {},
    }),
  );

  if (done !== null) {
    removeOpen.value = false;
    await refresh();
    showToast(t('workflow.deleted', { name: status.name }));
  } else if (remove.error.value?.status === 412) {
    removeOpen.value = false;
    conflict.value = true;
    await refresh().catch(() => undefined);
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <AppAlert v-if="conflict" variant="warning">{{ t('workflow.conflict') }}</AppAlert>
    <AppAlert v-if="action.message.value && !conflict" variant="error">{{ action.message.value }}</AppAlert>
    <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>

    <ol class="flex flex-col divide-y rounded-card border bg-surface" :aria-label="t('workflow.title')">
      <li v-for="(status, index) in statuses" :key="status.id" class="flex flex-wrap items-center gap-2 p-3">
        <span class="w-6 text-small text-text-muted" dir="ltr">{{ index + 1 }}</span>
        <span class="min-w-0 flex-1"><StatusChip :status="status" /></span>
        <span class="text-caption text-text-muted">{{ t(`workflow.categories.${status.category}`) }}</span>
        <AppBadge v-if="status.is_default" variant="info">{{ t('workflow.default') }}</AppBadge>
        <template v-if="editable">
          <AppButton
            variant="ghost"
            size="sm"
            icon-only
            :disabled="index === 0 || action.processing.value"
            :aria-label="t('workflow.move_up', { name: status.name })"
            @click="move(index, -1)"
          >
            <ArrowUp class="size-4" aria-hidden="true" />
          </AppButton>
          <AppButton
            variant="ghost"
            size="sm"
            icon-only
            :disabled="index === statuses.length - 1 || action.processing.value"
            :aria-label="t('workflow.move_down', { name: status.name })"
            @click="move(index, 1)"
          >
            <ArrowDown class="size-4" aria-hidden="true" />
          </AppButton>
          <AppButton
            variant="ghost"
            size="sm"
            icon-only
            :aria-label="t('workflow.edit', { name: status.name })"
            @click="startEdit(status)"
          >
            <Pencil class="size-4" aria-hidden="true" />
          </AppButton>
          <AppButton
            variant="ghost"
            size="sm"
            icon-only
            :aria-label="t('workflow.delete', { name: status.name })"
            @click="askRemove(status)"
          >
            <Trash2 class="size-4" aria-hidden="true" />
          </AppButton>
        </template>
      </li>
    </ol>

    <form v-if="editable" class="flex flex-wrap items-end gap-2" novalidate @submit.prevent="add">
      <div class="min-w-0 flex-1 basis-48">
        <TextField
          v-model="addForm.data.name"
          :label="t('workflow.new_name')"
          maxlength="50"
          dir="auto"
          :error="addForm.errors.name"
        />
      </div>
      <SelectField v-model="addForm.data.category" :label="t('workflow.fields.category')" class="w-44">
        <option v-for="category in statusCategories" :key="category" :value="category">
          {{ t(`workflow.categories.${category}`) }}
        </option>
      </SelectField>
      <AppButton type="submit" variant="secondary" :loading="addForm.processing.value">{{
        t('workflow.add')
      }}</AppButton>
      <AppAlert v-if="addForm.message.value" variant="error" class="basis-full">{{ addForm.message.value }}</AppAlert>
    </form>

    <AppDialog v-model:open="editOpen" :title="t('workflow.edit', { name: editing?.name ?? '' })">
      <form id="status-edit" class="flex flex-col gap-4" novalidate @submit.prevent="saveEdit">
        <AppAlert v-if="editForm.message.value" variant="error">{{ editForm.message.value }}</AppAlert>
        <TextField
          v-model="editForm.data.name"
          :label="t('workflow.fields.name')"
          maxlength="50"
          dir="auto"
          :error="editForm.errors.name"
        />
        <SelectField
          v-model="editForm.data.category"
          :label="t('workflow.fields.category')"
          :error="editForm.errors.category"
        >
          <option v-for="category in statusCategories" :key="category" :value="category">
            {{ t(`workflow.categories.${category}`) }}
          </option>
        </SelectField>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="editOpen = false">{{ t('ui.cancel') }}</AppButton>
        <AppButton type="submit" form="status-edit" :loading="editForm.processing.value">{{ t('ui.save') }}</AppButton>
      </template>
    </AppDialog>

    <ConfirmDialog
      v-model:open="removeOpen"
      :title="t('workflow.delete', { name: removing?.name ?? '' })"
      :description="t('workflow.delete_body')"
      :confirm-label="t('workflow.delete_submit')"
      :loading="remove.processing.value"
      :error="remove.message.value"
      @confirm="confirmRemove"
    >
      <SelectField v-model="replacement" :label="t('workflow.replacement')" :hint="t('workflow.replacement_hint')">
        <option value="">{{ t('workflow.no_replacement') }}</option>
        <option v-for="status in replacements" :key="status.id" :value="status.id">{{ status.name }}</option>
      </SelectField>
    </ConfirmDialog>
  </div>
</template>
