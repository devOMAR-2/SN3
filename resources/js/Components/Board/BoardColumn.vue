<script setup lang="ts">
import { computed, ref, useTemplateRef } from 'vue';
import QuickAddTask from '@/Components/Tasks/QuickAddTask.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import LoadError from '@/Components/ui/LoadError.vue';
import SkeletonRows from '@/Components/ui/SkeletonRows.vue';
import type { CursorList } from '@/Composables/useCursorList';
import { useI18n } from '@/i18n';
import { dropIndex } from '@/lib/boardMove';
import type { Label, ProjectStatus, Task } from '@/types/domain';
import TaskCard from './TaskCard.vue';

/**
 * One board column (DESIGN_SYSTEM.md §5.3): one status, its task count, cards in board order, quick add and
 * "Load more" (the column is read page by page, so a large board never loads every task at once,
 * DS-ACC-007). It is also a drop target for dragged cards.
 */
const props = defineProps<{
  projectId: string;
  status: ProjectStatus;
  list: CursorList<Task>;
  labelsById: Map<string, Label>;
  assigneeNames: Map<string, string>;
  canWrite: boolean;
  draggingId: string | null;
}>();
const emit = defineEmits<{
  move: [Task];
  dragstart: [Task];
  drop: [statusId: string, index: number];
  created: [Task];
}>();

const { t } = useI18n();
const cards = useTemplateRef<HTMLUListElement>('cards');
const over = ref(false);
const headingId = `column-${props.status.id}`;

const count = computed(() =>
  t(props.list.hasMore.value ? 'board.column.count_more' : 'board.column.count', {
    count: props.list.items.value.length,
  }),
);

function indexAt(event: DragEvent): number {
  const elements = Array.from(cards.value?.querySelectorAll<HTMLElement>('[data-task-id]') ?? []).filter(
    (element) => element.dataset.taskId !== props.draggingId,
  );

  return dropIndex(
    elements.map((element) => {
      const box = element.getBoundingClientRect();

      return box.top + box.height / 2;
    }),
    event.clientY,
  );
}

function onDragOver(event: DragEvent): void {
  if (!props.canWrite || props.draggingId === null) {
    return;
  }

  event.preventDefault();
  over.value = true;
}

function onDrop(event: DragEvent): void {
  over.value = false;

  if (!props.canWrite || props.draggingId === null) {
    return;
  }

  event.preventDefault();
  emit('drop', props.status.id, indexAt(event));
}
</script>

<template>
  <section
    class="flex max-h-[calc(100dvh-14rem)] w-72 min-w-66 shrink-0 flex-col rounded-card border bg-surface-muted"
    :class="over ? 'ring-2 ring-primary' : ''"
    :aria-labelledby="headingId"
    @dragover="onDragOver"
    @dragleave="over = false"
    @drop="onDrop"
  >
    <header class="sticky top-0 flex items-baseline justify-between gap-2 rounded-t-card bg-surface-muted p-3">
      <h3 :id="headingId" class="truncate font-semibold" dir="auto">{{ status.name }}</h3>
      <span class="shrink-0 text-small text-text-muted">{{ count }}</span>
    </header>

    <div class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto px-3 pb-3">
      <SkeletonRows v-if="list.loading.value" :rows="3" />
      <LoadError v-else-if="list.error.value" :error="list.error.value" @retry="list.reload" />
      <template v-else>
        <p v-if="list.items.value.length === 0" class="py-2 text-small text-text-muted">
          {{ t('board.column.empty') }}
        </p>
        <ul ref="cards" class="flex flex-col gap-2" :aria-labelledby="headingId">
          <li v-for="task in list.items.value" :key="task.id" :data-task-id="task.id">
            <TaskCard
              :task="task"
              :labels-by-id="labelsById"
              :assignee-name="task.assignee_user_id ? (assigneeNames.get(task.assignee_user_id) ?? null) : null"
              :can-move="canWrite"
              @move="emit('move', $event)"
              @dragstart="emit('dragstart', $event)"
            />
          </li>
        </ul>
        <AppButton
          v-if="list.hasMore.value"
          variant="ghost"
          size="sm"
          :loading="list.loadingMore.value"
          @click="list.loadMore"
        >
          {{ t('ui.load_more') }}
        </AppButton>
      </template>

      <QuickAddTask
        v-if="canWrite"
        :project-id="projectId"
        :status-id="status.id"
        :label="t('board.column.add', { status: status.name })"
        @created="emit('created', $event)"
      />
    </div>
  </section>
</template>
