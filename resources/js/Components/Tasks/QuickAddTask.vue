<script setup lang="ts">
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import TextField from '@/Components/ui/TextField.vue';
import { useApiForm } from '@/Composables/useApiForm';
import { useI18n } from '@/i18n';
import { createRetryKey } from '@/lib/http';
import type { Task } from '@/types/domain';

/**
 * Quick add (DESIGN_SYSTEM.md §5.2): type a title, Enter creates, Escape clears. The Idempotency-Key is kept
 * until the task is created, so retrying after a network failure never creates it twice (API_SPEC §1.4).
 */
const props = withDefaults(defineProps<{ projectId: string; parentTaskId?: string | null; label?: string }>(), {
  parentTaskId: null,
  label: undefined,
});
const emit = defineEmits<{ created: [Task] }>();

const { t } = useI18n();
const form = useApiForm('task', { title: '' });
const createKey = createRetryKey();

async function submit(): Promise<void> {
  const title = form.data.title.trim();

  if (title === '') {
    return;
  }

  const body: Record<string, unknown> = { title };

  if (props.parentTaskId) {
    body.parent_task_id = props.parentTaskId;
  }

  Object.assign(form.data, body);
  const task = await form.submit<Task>('post', `/projects/${props.projectId}/tasks`, {
    // Same title, same key: a retry replays instead of creating a duplicate.
    headers: { 'Idempotency-Key': createKey.for(title) },
  });

  if (task) {
    createKey.settle();
    form.data.title = '';
    delete (form.data as Record<string, unknown>).parent_task_id;
    emit('created', task);
  }
}

function clear(): void {
  form.data.title = '';
}
</script>

<template>
  <form class="flex flex-col gap-2" novalidate @submit.prevent="submit">
    <div class="flex items-end gap-2">
      <div class="min-w-0 flex-1">
        <TextField
          v-model="form.data.title"
          :label="label ?? t('task.create')"
          :placeholder="t('task.quick_add_placeholder')"
          maxlength="200"
          dir="auto"
          autocomplete="off"
          :error="form.errors.title"
          @keydown.escape.prevent="clear"
        />
      </div>
      <AppButton type="submit" :loading="form.processing.value" :disabled="form.data.title.trim() === ''">{{
        t('task.add')
      }}</AppButton>
    </div>
    <AppAlert v-if="form.message.value" variant="error">{{ form.message.value }}</AppAlert>
  </form>
</template>
