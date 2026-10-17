<script setup lang="ts">
import { Tag } from '@lucide/vue';
import { computed, ref, useId } from 'vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppBadge from '@/Components/ui/AppBadge.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import TextField from '@/Components/ui/TextField.vue';
import { useApiForm } from '@/Composables/useApiForm';
import { useI18n } from '@/i18n';
import type { Label } from '@/types/domain';

/**
 * The task's labels (API_SPEC.md §5, §6.1): chips, and for writers a checklist of the project's labels that
 * replaces `label_ids` on change. A project manager can also create a label here, so the picker is never a
 * dead end. Renaming, colours and deleting labels are FLEX-002's settings UI.
 */
const props = defineProps<{
  projectId: string;
  labels: Label[];
  selected: string[];
  editable: boolean;
  canCreate: boolean;
  saving: boolean;
}>();
const emit = defineEmits<{ change: [string[]]; created: [Label] }>();

const { t } = useI18n();
const open = ref(false);
const panelId = `labels-${useId()}`;
const byId = computed(() => new Map(props.labels.map((label) => [label.id, label])));
const chips = computed(() =>
  props.selected.map((id) => byId.value.get(id)).filter((label): label is Label => label !== undefined),
);

function toggle(id: string, checked: boolean): void {
  const next = checked ? [...props.selected, id] : props.selected.filter((existing) => existing !== id);
  emit('change', [...new Set(next)].sort());
}

const form = useApiForm('label', { name: '' });

async function create(): Promise<void> {
  if (form.data.name.trim() === '') {
    return;
  }

  const label = await form.submit<Label>('post', `/projects/${props.projectId}/labels`);

  if (label) {
    form.reset();
    emit('created', label);
    toggle(label.id, true);
  }
}
</script>

<template>
  <div class="flex flex-col gap-2">
    <div class="flex flex-wrap items-center gap-1.5">
      <AppBadge v-for="label in chips" :key="label.id">
        <Tag class="size-3" aria-hidden="true" /><span dir="auto">{{ label.name }}</span>
      </AppBadge>
      <span v-if="chips.length === 0" class="text-small text-text-muted">{{ t('task.no_labels') }}</span>
    </div>
    <AppButton
      v-if="editable"
      class="self-start"
      variant="ghost"
      size="sm"
      :aria-expanded="open ? 'true' : 'false'"
      :aria-controls="panelId"
      @click="open = !open"
      >{{ open ? t('task.labels_done') : t('task.labels_edit') }}</AppButton
    >
    <div v-if="editable && open" :id="panelId" class="flex flex-col gap-2 rounded-card border bg-surface p-3">
      <fieldset :disabled="saving">
        <legend class="sr-only">{{ t('task.fields.labels') }}</legend>
        <p v-if="labels.length === 0" class="text-small text-text-muted">{{ t('task.no_project_labels') }}</p>
        <label v-for="label in labels" :key="label.id" class="flex min-h-9 cursor-pointer items-center gap-2">
          <input
            type="checkbox"
            class="size-4 accent-(--primary)"
            :checked="selected.includes(label.id)"
            @change="toggle(label.id, ($event.target as HTMLInputElement).checked)"
          />
          <span dir="auto">{{ label.name }}</span>
        </label>
      </fieldset>
      <form v-if="canCreate" class="flex items-end gap-2 border-t pt-2" novalidate @submit.prevent="create">
        <div class="min-w-0 flex-1">
          <TextField
            v-model="form.data.name"
            :label="t('task.new_label')"
            maxlength="50"
            dir="auto"
            autocomplete="off"
            :error="form.errors.name"
          />
        </div>
        <AppButton type="submit" size="sm" variant="secondary" :loading="form.processing.value">{{
          t('task.create_label')
        }}</AppButton>
      </form>
      <AppAlert v-if="form.message.value" variant="error">{{ form.message.value }}</AppAlert>
    </div>
  </div>
</template>
