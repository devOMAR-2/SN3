<script setup lang="ts">
import { ArrowDown, ArrowUp, Pencil, Trash2 } from '@lucide/vue';
import { computed, ref, useId } from 'vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import TextField from '@/Components/ui/TextField.vue';
import { useAction } from '@/Composables/useAction';
import { useApiForm } from '@/Composables/useApiForm';
import { useI18n } from '@/i18n';
import { api, type ApiEnvelope } from '@/lib/api';
import { classifyWriteError } from '@/lib/http';
import type { ChecklistItem } from '@/types/domain';

/**
 * Task checklist (API_SPEC.md §6.2, ADR-023 §7): tick, add, rename, move up / down and delete. Items are not
 * versioned, so writes need no If-Match; a 423 tells the page the project became read-only.
 */
const props = defineProps<{ taskId: string; initialItems: ChecklistItem[]; editable: boolean }>();
const emit = defineEmits<{ locked: [] }>();

const { t } = useI18n();
const items = ref<ChecklistItem[]>([...props.initialItems]);
const done = computed(() => items.value.filter((item) => item.checked).length);
const action = useAction();
const announcement = ref('');
const progressId = `checklist-progress-${useId()}`;

async function reload(): Promise<void> {
  const response = await api.get<{ data: ChecklistItem[] }>(`/tasks/${props.taskId}/checklist`, {
    params: { limit: 100 },
  });
  items.value = response.data.data;
}

async function patch(
  item: ChecklistItem,
  changes: Partial<Pick<ChecklistItem, 'text' | 'checked' | 'position'>>,
): Promise<boolean> {
  const updated = await action.run(
    async () =>
      (await api.patch<ApiEnvelope<ChecklistItem>>(`/tasks/${props.taskId}/checklist/${item.id}`, changes)).data.data,
  );

  if (updated === null) {
    if (action.error.value && classifyWriteError(action.error.value) === 'locked') {
      emit('locked');
    }

    await reload().catch(() => undefined);

    return false;
  }

  if (changes.position !== undefined) {
    await reload();
  } else {
    items.value = items.value.map((existing) => (existing.id === item.id ? updated : existing));
  }

  return true;
}

async function move(index: number, offset: -1 | 1): Promise<void> {
  const item = items.value[index];
  const target = index + offset;

  if (!item || target < 0 || target >= items.value.length) {
    return;
  }

  if (await patch(item, { position: target })) {
    announcement.value = t('checklist.moved', { text: item.text, position: target + 1, total: items.value.length });
  }
}

const addForm = useApiForm('checklist', { text: '' });

async function add(): Promise<void> {
  if (addForm.data.text.trim() === '') {
    return;
  }

  const created = await addForm.submit<ChecklistItem>('post', `/tasks/${props.taskId}/checklist`);

  if (created) {
    items.value = [...items.value, created];
    addForm.reset();
  } else if (addForm.error.value?.status === 423) {
    emit('locked');
  }
}

const editingId = ref<string | null>(null);
const editText = ref('');

function startEdit(item: ChecklistItem): void {
  editingId.value = item.id;
  editText.value = item.text;
}

async function saveEdit(item: ChecklistItem): Promise<void> {
  if (await patch(item, { text: editText.value })) {
    editingId.value = null;
  }
}

async function remove(item: ChecklistItem): Promise<void> {
  const removed = await action.run(() => api.delete(`/tasks/${props.taskId}/checklist/${item.id}`));

  if (removed !== null) {
    items.value = items.value.filter((existing) => existing.id !== item.id);
  } else if (action.error.value?.status === 423) {
    emit('locked');
  }
}
</script>

<template>
  <section aria-labelledby="task-checklist" class="flex flex-col gap-3">
    <div class="flex items-baseline justify-between gap-2">
      <h2 id="task-checklist" class="text-heading font-semibold">{{ t('checklist.title') }}</h2>
      <span v-if="items.length > 0" :id="progressId" class="text-small text-text-muted">{{
        t('checklist.progress', { done, total: items.length })
      }}</span>
    </div>
    <AppAlert v-if="action.message.value" variant="error">{{ action.message.value }}</AppAlert>
    <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>

    <p v-if="items.length === 0" class="text-small text-text-muted">{{ t('checklist.empty') }}</p>
    <ul v-else class="flex flex-col divide-y rounded-card border bg-surface" :aria-describedby="progressId">
      <li v-for="(item, index) in items" :key="item.id" class="flex flex-wrap items-center gap-2 p-2">
        <template v-if="editingId === item.id">
          <form
            class="flex min-w-0 flex-1 flex-wrap items-end gap-2"
            novalidate
            @submit.prevent="saveEdit(item)"
            @keydown.escape="editingId = null"
          >
            <div class="min-w-0 flex-1 basis-48">
              <TextField v-model="editText" :label="t('checklist.edit_label')" maxlength="300" dir="auto" />
            </div>
            <AppButton type="submit" size="sm" :loading="action.processing.value">{{ t('ui.save') }}</AppButton>
            <AppButton size="sm" variant="ghost" @click="editingId = null">{{ t('ui.cancel') }}</AppButton>
          </form>
        </template>
        <template v-else>
          <label class="flex min-h-9 min-w-0 flex-1 cursor-pointer items-center gap-3 px-1">
            <input
              type="checkbox"
              class="size-4 shrink-0 accent-(--primary)"
              :checked="item.checked"
              :disabled="!editable"
              @change="patch(item, { checked: ($event.target as HTMLInputElement).checked })"
            />
            <span class="min-w-0 break-words" :class="item.checked && 'text-text-muted line-through'" dir="auto">{{
              item.text
            }}</span>
            <span v-if="item.checked" class="sr-only">{{ t('checklist.done') }}</span>
          </label>
          <template v-if="editable">
            <AppButton
              variant="ghost"
              size="sm"
              icon-only
              :disabled="index === 0"
              :aria-label="t('checklist.move_up', { text: item.text })"
              @click="move(index, -1)"
            >
              <ArrowUp class="size-4" aria-hidden="true" />
            </AppButton>
            <AppButton
              variant="ghost"
              size="sm"
              icon-only
              :disabled="index === items.length - 1"
              :aria-label="t('checklist.move_down', { text: item.text })"
              @click="move(index, 1)"
            >
              <ArrowDown class="size-4" aria-hidden="true" />
            </AppButton>
            <AppButton
              variant="ghost"
              size="sm"
              icon-only
              :aria-label="t('checklist.edit', { text: item.text })"
              @click="startEdit(item)"
            >
              <Pencil class="size-4" aria-hidden="true" />
            </AppButton>
            <AppButton
              variant="ghost"
              size="sm"
              icon-only
              :aria-label="t('checklist.delete', { text: item.text })"
              @click="remove(item)"
            >
              <Trash2 class="size-4" aria-hidden="true" />
            </AppButton>
          </template>
        </template>
      </li>
    </ul>

    <form v-if="editable" class="flex items-end gap-2" novalidate @submit.prevent="add">
      <div class="min-w-0 flex-1">
        <TextField
          v-model="addForm.data.text"
          :label="t('checklist.add_label')"
          maxlength="300"
          dir="auto"
          autocomplete="off"
          :error="addForm.errors.text"
        />
      </div>
      <AppButton type="submit" variant="secondary" :loading="addForm.processing.value">{{
        t('checklist.add')
      }}</AppButton>
    </form>
    <AppAlert v-if="addForm.message.value" variant="error">{{ addForm.message.value }}</AppAlert>
  </section>
</template>
