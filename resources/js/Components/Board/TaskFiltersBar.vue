<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import AppButton from '@/Components/ui/AppButton.vue';
import SelectField from '@/Components/ui/SelectField.vue';
import TextField from '@/Components/ui/TextField.vue';
import { useI18n } from '@/i18n';
import { activeFilterCount, emptyFilters, priorities, type TaskFilters } from '@/lib/taskFilters';
import type { Assignee, Label, ProjectStatus } from '@/types/domain';

/**
 * Filters shared by the Board and the List (DESIGN_SYSTEM.md §5.3): text, assignee, priority, labels and
 * due dates, plus status in the List. The search box is debounced; everything else applies at once.
 */
const props = defineProps<{
  labels: Label[];
  assignees: Assignee[];
  assigneesTruncated: boolean;
  statuses: ProjectStatus[];
  showStatus: boolean;
}>();
const filters = defineModel<TaskFilters>({ required: true });

const { t } = useI18n();
const ids = { priority: useId(), labels: useId(), status: useId() };
const search = ref(filters.value.q);
const count = computed(() => activeFilterCount(filters.value, props.showStatus));
let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
  clearTimeout(timer);
  timer = setTimeout(() => {
    filters.value = { ...filters.value, q: value };
  }, 300);
});
watch(
  () => filters.value.q,
  (value) => {
    if (value !== search.value) {
      search.value = value;
    }
  },
);

function toggle<K extends 'priority' | 'label' | 'status'>(key: K, value: TaskFilters[K][number]): void {
  const current = filters.value[key] as string[];
  const next = current.includes(value) ? current.filter((item) => item !== value) : [...current, value];
  filters.value = { ...filters.value, [key]: next };
}

function set<K extends keyof TaskFilters>(key: K, value: TaskFilters[K]): void {
  filters.value = { ...filters.value, [key]: value };
}

function clear(): void {
  search.value = '';
  filters.value = emptyFilters();
}

const chip =
  'inline-flex cursor-pointer items-center gap-1.5 rounded-pill border px-2.5 py-1 text-small has-checked:border-primary has-checked:bg-primary/10 has-focus-visible:ring-2 has-focus-visible:ring-focus';
</script>

<template>
  <section class="flex flex-col gap-3 rounded-card border bg-surface p-3" :aria-label="t('board.filters.title')">
    <div class="flex flex-wrap items-end gap-3">
      <TextField
        v-model="search"
        class="min-w-48 flex-1"
        type="search"
        :label="t('board.filters.search')"
        :placeholder="t('board.filters.search_placeholder')"
        maxlength="200"
        dir="auto"
        autocomplete="off"
      />
      <SelectField
        :model-value="filters.assignee"
        class="w-48"
        :label="t('board.filters.assignee')"
        :hint="assigneesTruncated ? t('board.filters.assignees_truncated', { count: assignees.length }) : undefined"
        @update:model-value="set('assignee', $event)"
      >
        <option value="">{{ t('board.filters.anyone') }}</option>
        <option v-for="person in assignees" :key="person.user_id" :value="person.user_id">{{ person.name }}</option>
      </SelectField>
      <TextField
        :model-value="filters.dueAfter"
        class="w-40"
        type="date"
        :label="t('board.filters.due_after')"
        @update:model-value="set('dueAfter', $event)"
      />
      <TextField
        :model-value="filters.dueBefore"
        class="w-40"
        type="date"
        :label="t('board.filters.due_before')"
        @update:model-value="set('dueBefore', $event)"
      />
      <AppButton v-if="count > 0" variant="ghost" @click="clear">
        {{ t('board.filters.clear') }} ({{ t('board.filters.active', { count }) }})
      </AppButton>
    </div>

    <fieldset class="flex flex-wrap items-center gap-2">
      <legend :id="ids.priority" class="me-2 text-small font-medium">{{ t('board.filters.priority') }}</legend>
      <label v-for="priority in priorities" :key="priority" :class="chip">
        <input
          type="checkbox"
          class="sr-only"
          :checked="filters.priority.includes(priority)"
          @change="toggle('priority', priority)"
        />
        {{ t(`task.priorities.${priority}`) }}
      </label>
    </fieldset>

    <fieldset v-if="labels.length > 0" class="flex flex-wrap items-center gap-2">
      <legend :id="ids.labels" class="me-2 text-small font-medium">{{ t('board.filters.labels') }}</legend>
      <label v-for="label in labels" :key="label.id" :class="chip">
        <input
          type="checkbox"
          class="sr-only"
          :checked="filters.label.includes(label.id)"
          @change="toggle('label', label.id)"
        />
        <span dir="auto">{{ label.name }}</span>
      </label>
    </fieldset>

    <fieldset v-if="showStatus" class="flex flex-wrap items-center gap-2">
      <legend :id="ids.status" class="me-2 text-small font-medium">{{ t('board.filters.status') }}</legend>
      <label v-for="status in statuses" :key="status.id" :class="chip">
        <input
          type="checkbox"
          class="sr-only"
          :checked="filters.status.includes(status.id)"
          @change="toggle('status', status.id)"
        />
        <span dir="auto">{{ status.name }}</span>
      </label>
    </fieldset>
  </section>
</template>
