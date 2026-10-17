<script setup lang="ts">
import { computed, onMounted, ref, useTemplateRef, watch } from 'vue';
import SelectField from '@/Components/ui/SelectField.vue';
import { useCursorList, type CursorList } from '@/Composables/useCursorList';
import { useMediaQuery } from '@/Composables/useMediaQuery';
import { showToast } from '@/Composables/useToast';
import { useI18n } from '@/i18n';
import { api, toApiError, type ApiEnvelope } from '@/lib/api';
import { endOfColumn, moveBody, placeAt } from '@/lib/boardMove';
import { classifyWriteError, ifMatch } from '@/lib/http';
import { apiParams, type TaskFilters } from '@/lib/taskFilters';
import type { Label, ProjectStatus, Task } from '@/types/domain';
import BoardColumn from './BoardColumn.vue';
import MoveTaskDialog, { type MovePlace } from './MoveTaskDialog.vue';

/**
 * The project board (CORE-007, DESIGN_SYSTEM.md §5.3): one column per status, read page by page per column
 * (`GET /projects/{id}/tasks?status_id[]=…&sort=position`), with the filters applied server-side.
 *
 * Moves (`POST /tasks/{id}/move`) are optimistic: the card moves at once, and is put back with an
 * explanation if the server refuses (412 someone changed it, 423 read-only, 404 gone). Every move, by drag or
 * by the Move dialog, is announced in a live region (DESIGN_SYSTEM.md §8). Phones show one column at a time
 * with a column picker (§3.3).
 */
const props = defineProps<{
  projectId: string;
  statuses: ProjectStatus[];
  filters: TaskFilters;
  labelsById: Map<string, Label>;
  assigneeNames: Map<string, string>;
  canWrite: boolean;
}>();
const emit = defineEmits<{ locked: [] }>();

const { t } = useI18n();
const isTabletUp = useMediaQuery('(min-width: 48rem)');
const PAGE = 25;

const lists = new Map<string, CursorList<Task>>(
  props.statuses.map((status) => [
    status.id,
    useCursorList<Task>(
      () => `/projects/${props.projectId}/tasks`,
      () => ({ sort: 'position', 'status_id[]': [status.id], ...apiParams(props.filters, false) }),
      PAGE,
    ),
  ]),
);
const statusById = computed(() => new Map(props.statuses.map((status) => [status.id, status])));
const mobileColumn = ref(props.statuses[0]?.id ?? '');
const visibleStatuses = computed(() =>
  isTabletUp.value ? props.statuses : props.statuses.filter((status) => status.id === mobileColumn.value),
);

const dragging = ref<Task | null>(null);
const announcement = ref('');
const dialogOpen = ref(false);
const dialogTask = ref<Task | null>(null);
const lastMoved = ref<string | null>(null);
const root = useTemplateRef<HTMLElement>('root');

function reloadAll(): void {
  lists.forEach((list) => void list.reload());
}

onMounted(reloadAll);
watch(() => props.filters, reloadAll, { deep: true });

function list(statusId: string): CursorList<Task> | undefined {
  return lists.get(statusId);
}

function indexOf(task: Task): number {
  return list(task.status_id)?.items.value.findIndex((item) => item.id === task.id) ?? -1;
}

/**
 * Moves `task` to `index` of the loaded cards of `statusId`, or to the end of the whole column (`toEnd`, the
 * dialog's "Bottom"). A card sent past the loaded cards of a column with more pages leaves the view: it is
 * listed again when "Load more" reaches it, never twice.
 */
async function moveTo(task: Task, statusId: string, index: number, toEnd = false): Promise<void> {
  const from = list(task.status_id);
  const to = list(statusId);

  if (from === undefined || to === undefined) {
    return;
  }

  const before = { from: from.items.value, to: to.items.value };
  const target = to.items.value.filter((item) => item.id !== task.id);
  const hasMore = to.hasMore.value;
  const body = toEnd
    ? endOfColumn(statusId)
    : moveBody(
        statusId,
        target.map((item) => item.id),
        index,
        hasMore,
      );
  const hidden = toEnd && hasMore;
  const at = toEnd ? target.length : Math.min(index, target.length);

  // Optimistic: the card moves now; the server's answer replaces or reverts it.
  from.items.value = from.items.value.filter((item) => item.id !== task.id);
  to.items.value = hidden ? target : placeAt(target, { ...task, status_id: statusId }, at);
  lastMoved.value = task.id;
  announcement.value = t('board.move.moving', { key: task.key });

  try {
    const response = await api.post<ApiEnvelope<Task>>(`/tasks/${task.id}/move`, body, {
      headers: ifMatch(task.version),
    });
    to.replace((item) => item.id === task.id, response.data.data);
    const status = statusById.value.get(statusId)?.name ?? '';
    announcement.value = hidden
      ? t('board.move.moved_end', { key: task.key, status })
      : hasMore
        ? t('board.move.moved_at', { key: task.key, status, position: at + 1 })
        : t('board.move.moved', { key: task.key, status, position: at + 1, total: to.items.value.length });
  } catch (thrown) {
    from.items.value = before.from;
    to.items.value = before.to;
    const outcome = classifyWriteError(toApiError(thrown));
    const message =
      outcome === 'conflict'
        ? t('board.move.conflict', { key: task.key })
        : outcome === 'locked'
          ? t('board.move.locked')
          : outcome === 'gone'
            ? t('board.move.gone', { key: task.key })
            : t('board.move.failed', { key: task.key });

    announcement.value = message;
    showToast(message, 'error');

    // The board was out of date (a changed or vanished task, or an anchor that is gone): refresh it.
    if (outcome === 'conflict' || outcome === 'gone' || outcome === 'invalid') {
      void from.reload();

      if (to !== from) {
        void to.reload();
      }
    }

    if (outcome === 'locked') {
      emit('locked');
    }
  }
}

function onDrop(statusId: string, index: number): void {
  const task = dragging.value;
  dragging.value = null;

  if (task !== null) {
    void moveTo(task, statusId, index);
  }
}

function openMove(task: Task): void {
  dialogTask.value = task;
  dialogOpen.value = true;
}

function onDialogMove(task: Task, statusId: string, place: MovePlace): void {
  const current = indexOf(task);
  const index = place === 'top' ? 0 : place === 'up' ? current - 1 : current + 1;

  void moveTo(task, statusId, Math.max(0, index), place === 'bottom');
}

/**
 * Where focus goes when the Move dialog closes (DESIGN_SYSTEM.md §8): the moved card's Move button, which
 * may now be in another column; if the card is not on screen (another column on a phone, or past the
 * loaded cards), the board itself, so focus is never lost to the page.
 */
function focusAfterMove(): HTMLElement | null {
  const id = lastMoved.value;
  const card = id === null ? null : root.value?.querySelector<HTMLElement>(`[data-task-id="${CSS.escape(id)}"] button`);

  return card ?? root.value;
}

function onCreated(task: Task): void {
  showToast(t('task.created', { key: task.key }));
  void list(task.status_id)?.reload();
}

const dialogIndex = computed(() => (dialogTask.value === null ? 0 : indexOf(dialogTask.value)));
const dialogTotal = computed(() =>
  dialogTask.value === null ? 0 : (list(dialogTask.value.status_id)?.items.value.length ?? 0),
);
</script>

<template>
  <div ref="root" class="flex flex-col gap-3 focus:outline-none" tabindex="-1">
    <p class="sr-only" aria-live="polite">{{ announcement }}</p>
    <p v-if="canWrite" class="text-small text-text-muted">{{ t('board.move.drag_hint') }}</p>

    <SelectField v-if="!isTabletUp" v-model="mobileColumn" :label="t('board.column.choose')">
      <option v-for="status in statuses" :key="status.id" :value="status.id">{{ status.name }}</option>
    </SelectField>

    <div class="flex gap-3 overflow-x-auto pb-2" @dragend="dragging = null">
      <BoardColumn
        v-for="status in visibleStatuses"
        :key="status.id"
        :project-id="projectId"
        :status="status"
        :list="lists.get(status.id)!"
        :labels-by-id="labelsById"
        :assignee-names="assigneeNames"
        :can-write="canWrite"
        :dragging-id="dragging?.id ?? null"
        @move="openMove"
        @dragstart="dragging = $event"
        @drop="onDrop"
        @created="onCreated"
      />
    </div>

    <MoveTaskDialog
      v-model:open="dialogOpen"
      :task="dialogTask"
      :statuses="statuses"
      :index="dialogIndex"
      :total="dialogTotal"
      :return-focus="focusAfterMove"
      @move="onDialogMove"
    />
  </div>
</template>
