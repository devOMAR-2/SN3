<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppDialog from '@/Components/ui/AppDialog.vue';
import SelectField from '@/Components/ui/SelectField.vue';
import { useI18n } from '@/i18n';
import type { ProjectStatus, Task } from '@/types/domain';

/**
 * The keyboard and touch alternative to dragging (DESIGN_SYSTEM.md §5.3, §8; DS-ACC-001, DS-ACC-011):
 * focus a card's Move button → choose the status and the place → Move.
 */
export type MovePlace = 'top' | 'bottom' | 'up' | 'down';

const props = defineProps<{
  task: Task | null;
  statuses: ProjectStatus[];
  index: number;
  total: number;
  /** Where focus goes on close; defaults to the button that opened the dialog. */
  returnFocus?: () => HTMLElement | null;
}>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ move: [task: Task, statusId: string, place: MovePlace] }>();

const { t } = useI18n();
const statusId = ref('');
const place = ref<MovePlace>('bottom');

// Every opening starts from the task's own column, also after a cancelled choice for the same task.
watch(
  [() => props.task, open],
  ([task, isOpen]) => {
    if (isOpen || task !== null) {
      statusId.value = task?.status_id ?? '';
      place.value = 'bottom';
    }
  },
  { immediate: true },
);

const sameColumn = computed(() => props.task !== null && statusId.value === props.task.status_id);
const places = computed<MovePlace[]>(() => {
  if (!sameColumn.value) {
    return ['top', 'bottom'];
  }

  return [
    'top',
    ...(props.index > 0 ? (['up'] as const) : []),
    ...(props.index < props.total - 1 ? (['down'] as const) : []),
    'bottom',
  ];
});

watch(places, (available) => {
  if (!available.includes(place.value)) {
    place.value = 'bottom';
  }
});

function submit(): void {
  if (props.task !== null) {
    emit('move', props.task, statusId.value, place.value);
  }

  open.value = false;
}
</script>

<template>
  <AppDialog
    v-model:open="open"
    :return-focus="returnFocus"
    :title="task ? t('board.move.title', { key: task.key }) : ''"
  >
    <form class="flex flex-col gap-4" @submit.prevent="submit">
      <SelectField v-model="statusId" :label="t('board.move.status')">
        <option v-for="status in statuses" :key="status.id" :value="status.id">{{ status.name }}</option>
      </SelectField>
      <SelectField v-model="place" :label="t('board.move.position')">
        <option v-for="option in places" :key="option" :value="option">
          {{
            t(
              option === 'up'
                ? 'board.card.move_up'
                : option === 'down'
                  ? 'board.card.move_down'
                  : `board.move.${option}`,
            )
          }}
        </option>
      </SelectField>
      <div class="flex justify-end gap-2">
        <AppButton variant="secondary" @click="open = false">{{ t('ui.cancel') }}</AppButton>
        <AppButton type="submit">{{ t('board.move.submit') }}</AppButton>
      </div>
    </form>
  </AppDialog>
</template>
