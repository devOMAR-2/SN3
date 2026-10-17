<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppBadge from '@/Components/ui/AppBadge.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import LoadError from '@/Components/ui/LoadError.vue';
import SelectField from '@/Components/ui/SelectField.vue';
import SkeletonRows from '@/Components/ui/SkeletonRows.vue';
import { useAction } from '@/Composables/useAction';
import { useCursorList } from '@/Composables/useCursorList';
import { showToast } from '@/Composables/useToast';
import { useI18n } from '@/i18n';
import { api, type ApiEnvelope } from '@/lib/api';
import type { ProjectMember, ProjectRole, TeamMember } from '@/types/domain';

/**
 * Explicit project members (API_SPEC.md §4, ADR-020 §8): list for everyone who sees the project; add a team
 * member, change a role or remove for the Owner and Project Managers (`editable`, from the server).
 */
const props = defineProps<{ projectId: string; teamId: string; editable: boolean; isPrivate: boolean }>();
const emit = defineEmits<{ changed: [] }>();

const { t } = useI18n();
const members = useCursorList<ProjectMember>(
  () => `/projects/${props.projectId}/members`,
  () => ({}),
  100,
);
const team = useCursorList<TeamMember>(
  () => `/teams/${props.teamId}/members`,
  () => ({}),
  100,
);
const action = useAction();

onMounted(() => {
  void members.reload();

  if (props.editable) {
    void team.reload();
  }
});

const memberIds = computed(() => new Set(members.items.value.map((member) => member.user_id)));
const addable = computed(() => team.items.value.filter((member) => !memberIds.value.has(member.user_id)));
const newMember = ref('');
const newRole = ref<ProjectRole>('contributor');

async function put(userId: string, role: ProjectRole): Promise<boolean> {
  const saved = await action.run(
    async () =>
      (await api.put<ApiEnvelope<ProjectMember>>(`/projects/${props.projectId}/members/${userId}`, { role })).data.data,
  );

  if (saved) {
    await members.reload();
    emit('changed');
  }

  return saved !== null;
}

async function add(): Promise<void> {
  if (newMember.value === '') {
    return;
  }

  const name = addable.value.find((member) => member.user_id === newMember.value)?.name ?? '';

  if (await put(newMember.value, newRole.value)) {
    newMember.value = '';
    showToast(t('project.members.added', { name }));
  }
}

const removing = ref<ProjectMember | null>(null);
const removeOpen = ref(false);
const removal = useAction();

function askRemove(member: ProjectMember): void {
  removal.clear();
  removing.value = member;
  removeOpen.value = true;
}

async function confirmRemove(): Promise<void> {
  const member = removing.value;

  if (!member) {
    return;
  }

  const done = await removal.run(() => api.delete(`/projects/${props.projectId}/members/${member.user_id}`));

  if (done !== null) {
    removeOpen.value = false;
    members.replace((item) => item.user_id === member.user_id, null);
    showToast(t('project.members.removed', { name: member.name }));
    emit('changed');
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <p class="text-small text-text-muted">
      {{ isPrivate ? t('project.members.private_help') : t('project.members.team_help') }}
    </p>
    <AppAlert v-if="action.message.value" variant="error">{{ action.message.value }}</AppAlert>

    <SkeletonRows v-if="members.loading.value" :rows="2" />
    <LoadError v-else-if="members.error.value" :error="members.error.value" @retry="members.reload" />
    <EmptyState v-else-if="members.items.value.length === 0" :title="t('project.members.none')" />
    <ul v-else class="flex flex-col divide-y rounded-card border bg-surface">
      <li v-for="member in members.items.value" :key="member.user_id" class="flex flex-wrap items-center gap-3 p-3">
        <span class="min-w-0 flex-1">
          <span class="block truncate font-medium" dir="auto">{{ member.name }}</span>
          <span v-if="member.email" class="block truncate text-small text-text-muted" dir="ltr">{{
            member.email
          }}</span>
        </span>
        <SelectField
          v-if="editable"
          :model-value="member.role"
          :label="t('project.member_role_for', { name: member.name })"
          hide-label
          class="w-40"
          @update:model-value="put(member.user_id, $event as ProjectRole)"
        >
          <option value="contributor">{{ t('project.roles.contributor') }}</option>
          <option value="manager">{{ t('project.roles.manager') }}</option>
        </SelectField>
        <AppBadge v-else>{{ t(`project.roles.${member.role}`) }}</AppBadge>
        <AppButton
          v-if="editable"
          variant="ghost"
          size="sm"
          :aria-label="t('team.members.remove_named', { name: member.name })"
          @click="askRemove(member)"
        >
          {{ t('team.members.remove') }}
        </AppButton>
      </li>
    </ul>

    <form v-if="editable" class="flex flex-wrap items-end gap-2" novalidate @submit.prevent="add">
      <SelectField v-model="newMember" :label="t('project.members.add_person')" class="min-w-0 flex-1 basis-48">
        <option value="" disabled>{{ t('ui.choose') }}</option>
        <option v-for="member in addable" :key="member.user_id" :value="member.user_id">{{ member.name }}</option>
      </SelectField>
      <SelectField v-model="newRole" :label="t('project.fields.role')" class="w-40">
        <option value="contributor">{{ t('project.roles.contributor') }}</option>
        <option value="manager">{{ t('project.roles.manager') }}</option>
      </SelectField>
      <AppButton type="submit" variant="secondary" :disabled="newMember === ''" :loading="action.processing.value">
        {{ t('project.members.add') }}
      </AppButton>
    </form>

    <ConfirmDialog
      v-model:open="removeOpen"
      :title="t('team.members.remove_title', { name: removing?.name ?? '' })"
      :description="isPrivate ? t('project.members.remove_private_body') : t('project.members.remove_body')"
      :confirm-label="t('team.members.remove')"
      :loading="removal.processing.value"
      :error="removal.message.value"
      @confirm="confirmRemove"
    />
  </div>
</template>
