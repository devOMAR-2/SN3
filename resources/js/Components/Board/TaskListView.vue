<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { onMounted, watch } from 'vue';
import PriorityBadge from '@/Components/Tasks/PriorityBadge.vue';
import StatusChip from '@/Components/Tasks/StatusChip.vue';
import AppBadge from '@/Components/ui/AppBadge.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import LoadError from '@/Components/ui/LoadError.vue';
import SelectField from '@/Components/ui/SelectField.vue';
import SkeletonRows from '@/Components/ui/SkeletonRows.vue';
import TechnicalId from '@/Components/ui/TechnicalId.vue';
import { useCursorList } from '@/Composables/useCursorList';
import { useFormat } from '@/Composables/useFormat';
import { useI18n } from '@/i18n';
import { pages } from '@/lib/routes';
import { activeFilterCount, apiParams, listSorts, type ListSort, type TaskFilters } from '@/lib/taskFilters';
import type { Label, ProjectStatus, Task } from '@/types/domain';

/**
 * The List view (CORE-007, DESIGN_SYSTEM.md §5.3): the project's tasks in a table, filtered and sorted on the
 * server (allowlisted sorts, API_SPEC.md §1.4) and read 50 at a time with the cursor.
 */
const props = defineProps<{
  projectId: string;
  projectName: string;
  statuses: ProjectStatus[];
  filters: TaskFilters;
  labelsById: Map<string, Label>;
  assigneeNames: Map<string, string>;
  canWrite: boolean;
}>();
const sort = defineModel<ListSort>('sort', { required: true });

const { t } = useI18n();
const format = useFormat();
const statusById = new Map(props.statuses.map((status) => [status.id, status]));

const tasks = useCursorList<Task>(
  () => `/projects/${props.projectId}/tasks`,
  () => ({ sort: sort.value, ...apiParams(props.filters, true) }),
  50,
);

onMounted(() => void tasks.reload());
watch([() => props.filters, sort], () => void tasks.reload(), { deep: true });

function labelsOf(task: Task): Label[] {
  return task.label_ids.map((id) => props.labelsById.get(id)).filter((label): label is Label => label !== undefined);
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <SelectField v-model="sort" class="w-56 self-end" :label="t('board.list.sort')">
      <option v-for="option in listSorts" :key="option" :value="option">{{ t(`board.list.sorts.${option}`) }}</option>
    </SelectField>

    <SkeletonRows v-if="tasks.loading.value" />
    <LoadError v-else-if="tasks.error.value" :error="tasks.error.value" @retry="tasks.reload" />
    <EmptyState
      v-else-if="tasks.items.value.length === 0"
      :title="activeFilterCount(filters, true) > 0 ? t('board.filters.no_match') : t('empty.tasks')"
      :description="canWrite && activeFilterCount(filters, true) === 0 ? t('empty.tasks_writer') : undefined"
    />
    <div v-else class="overflow-x-auto rounded-card border bg-surface">
      <table class="w-full min-w-176 text-start text-body">
        <caption class="sr-only">
          {{
            t('board.list.caption', { project: projectName })
          }}
        </caption>
        <thead class="border-b bg-surface-muted text-small text-text-muted">
          <tr>
            <th scope="col" class="p-3 text-start font-medium">{{ t('board.list.columns.key') }}</th>
            <th scope="col" class="p-3 text-start font-medium">{{ t('board.list.columns.title') }}</th>
            <th scope="col" class="p-3 text-start font-medium">{{ t('board.list.columns.status') }}</th>
            <th scope="col" class="p-3 text-start font-medium">{{ t('board.list.columns.assignee') }}</th>
            <th scope="col" class="p-3 text-start font-medium">{{ t('board.list.columns.priority') }}</th>
            <th scope="col" class="p-3 text-start font-medium">{{ t('board.list.columns.due_date') }}</th>
            <th scope="col" class="p-3 text-start font-medium">{{ t('board.list.columns.labels') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="task in tasks.items.value" :key="task.id" class="hover:bg-surface-hover">
            <td class="p-3 whitespace-nowrap">
              <TechnicalId class="text-small text-text-muted">{{ task.key }}</TechnicalId>
            </td>
            <td class="p-3">
              <Link
                :href="pages.task(task.id)"
                class="font-medium break-words hover:text-primary hover:underline"
                dir="auto"
              >
                {{ task.title }}
              </Link>
            </td>
            <td class="p-3"><StatusChip :status="statusById.get(task.status_id)" /></td>
            <td class="p-3 text-small" dir="auto">
              {{
                task.assignee_user_id
                  ? (assigneeNames.get(task.assignee_user_id) ?? t('board.card.assigned'))
                  : t('task.unassigned')
              }}
            </td>
            <td class="p-3"><PriorityBadge :priority="task.priority" show-none /></td>
            <td class="p-3 text-small whitespace-nowrap">{{ task.due_date ? format.date(task.due_date) : '—' }}</td>
            <td class="p-3">
              <span class="flex flex-wrap gap-1">
                <AppBadge v-for="label in labelsOf(task).slice(0, 2)" :key="label.id"
                  ><span dir="auto">{{ label.name }}</span></AppBadge
                >
                <AppBadge v-if="labelsOf(task).length > 2">{{
                  t('board.card.labels_more', { count: labelsOf(task).length - 2 })
                }}</AppBadge>
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <AppButton
      v-if="tasks.hasMore.value"
      class="self-start"
      variant="secondary"
      :loading="tasks.loadingMore.value"
      @click="tasks.loadMore"
    >
      {{ t('ui.load_more') }}
    </AppButton>
  </div>
</template>
