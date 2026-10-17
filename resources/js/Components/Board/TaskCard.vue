<script setup lang="ts">
import { Calendar, MoveHorizontal } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import PriorityBadge from '@/Components/Tasks/PriorityBadge.vue';
import AppBadge from '@/Components/ui/AppBadge.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import TechnicalId from '@/Components/ui/TechnicalId.vue';
import { useFormat } from '@/Composables/useFormat';
import { useI18n } from '@/i18n';
import { pages } from '@/lib/routes';
import type { Label, Task } from '@/types/domain';

/**
 * A board card (DESIGN_SYSTEM.md §5.3): key, title (three lines at most), assignee, priority, due date and up
 * to two labels. Dragging is a convenience; the Move button opens the keyboard and touch alternative.
 */
const props = defineProps<{
  task: Task;
  labelsById: Map<string, Label>;
  assigneeName: string | null;
  canMove: boolean;
}>();
const emit = defineEmits<{ move: [Task]; dragstart: [Task] }>();

const { t } = useI18n();
const format = useFormat();
const labels = computed(() =>
  props.task.label_ids.map((id) => props.labelsById.get(id)).filter((label): label is Label => label !== undefined),
);

function onDragStart(event: DragEvent): void {
  if (!props.canMove || event.dataTransfer === null) {
    event.preventDefault();

    return;
  }

  event.dataTransfer.effectAllowed = 'move';
  event.dataTransfer.setData('text/plain', props.task.id);
  emit('dragstart', props.task);
}
</script>

<template>
  <article
    class="group flex flex-col gap-2 rounded-card border bg-surface p-3 shadow-sm focus-within:ring-2 focus-within:ring-focus"
    :draggable="canMove"
    :data-task-id="task.id"
    @dragstart="onDragStart"
  >
    <div class="flex items-start justify-between gap-2">
      <TechnicalId class="text-caption text-text-muted">{{ task.key }}</TechnicalId>
      <AppButton
        v-if="canMove"
        variant="ghost"
        size="sm"
        icon-only
        :aria-label="t('board.card.move_task', { key: task.key })"
        @click="emit('move', task)"
      >
        <MoveHorizontal class="size-4" aria-hidden="true" />
      </AppButton>
    </div>

    <Link
      :href="pages.task(task.id)"
      class="line-clamp-3 font-medium break-words text-text hover:text-primary hover:underline"
      dir="auto"
    >
      {{ task.title }}
    </Link>

    <div class="flex flex-wrap items-center gap-1.5 text-caption">
      <PriorityBadge :priority="task.priority" />
      <span v-if="task.due_date" class="inline-flex items-center gap-1 text-text-muted">
        <Calendar class="size-3.5" aria-hidden="true" />{{ t('board.card.due', { date: format.date(task.due_date) }) }}
      </span>
      <AppBadge v-for="label in labels.slice(0, 2)" :key="label.id">
        <span dir="auto">{{ label.name }}</span>
      </AppBadge>
      <AppBadge v-if="labels.length > 2">{{ t('board.card.labels_more', { count: labels.length - 2 }) }}</AppBadge>
    </div>

    <p v-if="task.assignee_user_id" class="truncate text-small text-text-muted" dir="auto">
      {{ assigneeName ?? t('board.card.assigned') }}
    </p>
  </article>
</template>
