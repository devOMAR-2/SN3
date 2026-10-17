<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import InvitationsPanel from '@/Components/Teams/InvitationsPanel.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppBadge from '@/Components/ui/AppBadge.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import LoadError from '@/Components/ui/LoadError.vue';
import SelectField from '@/Components/ui/SelectField.vue';
import SkeletonRows from '@/Components/ui/SkeletonRows.vue';
import LockBanner from '@/Components/Workspace/LockBanner.vue';
import PageHeader from '@/Components/Workspace/PageHeader.vue';
import WorkspaceLayout from '@/Components/Workspace/WorkspaceLayout.vue';
import { useAction } from '@/Composables/useAction';
import { useCursorList } from '@/Composables/useCursorList';
import { useFormat } from '@/Composables/useFormat';
import { showToast } from '@/Composables/useToast';
import { useI18n } from '@/i18n';
import { api, type ApiEnvelope } from '@/lib/api';
import { pages } from '@/lib/routes';
import type { CursorPage, Lock, Team, TeamAbilities, TeamMember, TeamRole } from '@/types/domain';

/**
 * `/t/{team_slug}/members` (CORE-006): the member list (GET /api/v1/teams/{team}/members), role changes,
 * removal, leaving and ownership transfer. Which controls appear comes from the server's `can` flags
 * (App\Http\Pages\Abilities::team); the per-row rule is the policy's own: the Owner's row never changes
 * except through a transfer, and "remove" applies to other people (yourself: Leave). Every request is
 * authorised again by the API.
 */
const props = defineProps<{ team: Team; can: TeamAbilities; lock: Lock }>();

const { t } = useI18n();
const format = useFormat();
const page = usePage();
const me = computed(() => page.props.auth.user?.id ?? '');
const list = useCursorList<TeamMember>(
  () => `/teams/${props.team.id}/members`,
  () => ({}),
  50,
);
const assignableRoles: TeamRole[] = ['admin', 'member', 'viewer'];

onMounted(() => void list.reload());

const roleAction = useAction();
const pendingRole = ref<string | null>(null);

function isOwnerRow(member: TeamMember): boolean {
  return member.user_id === props.team.owner_user_id;
}

async function changeRole(member: TeamMember, role: string): Promise<void> {
  if (role === member.role) {
    return;
  }

  pendingRole.value = member.user_id;
  const updated = await roleAction.run(
    async () =>
      (await api.patch<ApiEnvelope<TeamMember>>(`/teams/${props.team.id}/members/${member.user_id}`, { role })).data
        .data,
  );
  pendingRole.value = null;

  // On failure the select snaps back to the stored role (the list item is unchanged).
  list.replace((item) => item.user_id === member.user_id, updated ?? { ...member });

  if (updated) {
    showToast(t('team.members.role_changed', { name: member.name }));
  } else if (roleAction.error.value?.status === 423) {
    // Archived meanwhile: reload the server's abilities, which hide every control.
    router.reload();
  }
}

// Removing someone else, or leaving.
const removal = useAction();
const removing = ref<TeamMember | null>(null);
const removeOpen = ref(false);
const leaving = computed(() => removing.value?.user_id === me.value);

function askRemove(member: TeamMember): void {
  removal.clear();
  removing.value = member;
  removeOpen.value = true;
}

async function confirmRemove(): Promise<void> {
  const member = removing.value;

  if (!member) {
    return;
  }

  const done = await removal.run(() => api.delete(`/teams/${props.team.id}/members/${member.user_id}`));

  if (done === null) {
    return;
  }

  removeOpen.value = false;

  if (member.user_id === me.value) {
    showToast(t('team.members.left', { team: props.team.name }));
    router.visit(pages.home());

    return;
  }

  list.replace((item) => item.user_id === member.user_id, null);
  showToast(t('team.members.removed', { name: member.name }));
}

// Ownership transfer. The candidates are every member of the team, not only the pages loaded in the list:
// they are read page by page (100 at a time) when the dialog opens.
const transfer = useAction();
const transferOpen = ref(false);
const newOwner = ref('');
const allMembers = ref<TeamMember[]>([]);
const loadingCandidates = ref(false);
const candidates = computed(() => allMembers.value.filter((member) => member.user_id !== me.value));
const newOwnerName = computed(() => candidates.value.find((member) => member.user_id === newOwner.value)?.name ?? '');
// The button needs someone else in the team; the loaded list is enough to know that.
const hasOtherMembers = computed(
  () => list.items.value.some((member) => member.user_id !== me.value) || list.hasMore.value,
);

async function loadAllMembers(): Promise<void> {
  loadingCandidates.value = true;
  const collected: TeamMember[] = [];
  let cursor: string | null = null;

  const done = await transfer.run(async () => {
    do {
      const response: { data: CursorPage<TeamMember> } = await api.get<CursorPage<TeamMember>>(
        `/teams/${props.team.id}/members`,
        {
          params: { limit: 100, ...(cursor ? { cursor } : {}) },
        },
      );
      collected.push(...response.data.data);
      cursor = response.data.meta.has_more ? response.data.meta.next_cursor : null;
    } while (cursor !== null);

    return true;
  });

  if (done) {
    allMembers.value = collected;
  }

  loadingCandidates.value = false;
}

function askTransfer(): void {
  transfer.clear();
  newOwner.value = '';
  allMembers.value = [];
  transferOpen.value = true;
  void loadAllMembers();
}

async function confirmTransfer(): Promise<void> {
  if (newOwner.value === '') {
    return;
  }

  const done = await transfer.run(() =>
    api.post(`/teams/${props.team.id}/ownership-transfer`, { new_owner_user_id: newOwner.value }),
  );

  if (done !== null) {
    transferOpen.value = false;
    showToast(t('team.members.transferred', { name: newOwnerName.value }));
    // The abilities on this page change with the role: reload them from the server.
    router.reload({ onSuccess: () => void list.reload() });
  }
}
</script>

<template>
  <WorkspaceLayout
    :title="t('nav.members')"
    :team="team"
    :team-can="can"
    :breadcrumbs="[{ label: team.name, href: pages.team(team.slug) }, { label: t('nav.members') }]"
  >
    <LockBanner :lock="lock" />
    <PageHeader :title="t('nav.members')">
      <template #actions>
        <AppButton v-if="can.transfer_ownership && hasOtherMembers" variant="secondary" @click="askTransfer">
          {{ t('team.members.transfer') }}
        </AppButton>
      </template>
    </PageHeader>

    <!-- Invitations (CORE-002): shown to those the server lets invite (`can.invite`, Owner and Admins). -->
    <div v-if="can.invite" data-extension-point="team-invitations" class="mb-8">
      <InvitationsPanel :team-id="team.id" :can-invite-admin="can.invite_admin" />
    </div>

    <AppAlert v-if="roleAction.message.value" variant="error" class="mb-3">{{ roleAction.message.value }}</AppAlert>

    <SkeletonRows v-if="list.loading.value" />
    <LoadError v-else-if="list.error.value" :error="list.error.value" @retry="list.reload" />
    <EmptyState v-else-if="list.items.value.length === 0" :title="t('empty.members')" />
    <ul v-else class="flex flex-col divide-y rounded-card border bg-surface" :aria-label="t('nav.members')">
      <li v-for="member in list.items.value" :key="member.user_id" class="flex flex-wrap items-center gap-3 p-4">
        <div class="min-w-0 flex-1">
          <p class="truncate font-medium" dir="auto">
            {{ member.name }}
            <span v-if="member.user_id === me" class="text-small font-normal text-text-muted">{{
              t('team.members.you')
            }}</span>
          </p>
          <p v-if="member.email" class="truncate text-small text-text-muted" dir="ltr">{{ member.email }}</p>
          <p class="text-caption text-text-subtle">
            {{ t('team.members.joined', { date: format.dateTime(member.joined_at) }) }}
          </p>
        </div>

        <SelectField
          v-if="can.change_member_roles && !isOwnerRow(member)"
          :model-value="member.role"
          :label="t('team.members.role_for', { name: member.name })"
          hide-label
          class="w-36"
          :disabled="pendingRole === member.user_id"
          @update:model-value="changeRole(member, $event)"
        >
          <option v-for="role in assignableRoles" :key="role" :value="role">{{ t(`team.roles.${role}`) }}</option>
        </SelectField>
        <AppBadge v-else :variant="isOwnerRow(member) ? 'info' : 'neutral'">{{
          t(`team.roles.${member.role}`)
        }}</AppBadge>

        <AppButton
          v-if="can.remove_other_members && !isOwnerRow(member) && member.user_id !== me"
          variant="ghost"
          size="sm"
          :aria-label="t('team.members.remove_named', { name: member.name })"
          @click="askRemove(member)"
          >{{ t('team.members.remove') }}</AppButton
        >
        <AppButton v-if="member.user_id === me && can.leave" variant="ghost" size="sm" @click="askRemove(member)">
          {{ t('team.members.leave') }}
        </AppButton>
      </li>
    </ul>
    <AppButton
      v-if="list.hasMore.value"
      class="mt-3"
      variant="secondary"
      :loading="list.loadingMore.value"
      @click="list.loadMore"
    >
      {{ t('ui.load_more') }}
    </AppButton>

    <ConfirmDialog
      v-model:open="removeOpen"
      :title="
        leaving
          ? t('team.members.leave_title', { team: team.name })
          : t('team.members.remove_title', { name: removing?.name ?? '' })
      "
      :description="leaving ? t('team.members.leave_body') : t('team.members.remove_body')"
      :confirm-label="leaving ? t('team.members.leave') : t('team.members.remove')"
      :loading="removal.processing.value"
      :error="removal.message.value"
      @confirm="confirmRemove"
    />

    <ConfirmDialog
      v-model:open="transferOpen"
      :title="t('team.members.transfer')"
      :description="t('team.members.transfer_body')"
      :confirm-label="t('team.members.transfer_submit')"
      :confirm-disabled="newOwner === ''"
      variant="primary"
      :loading="transfer.processing.value"
      :error="transfer.message.value"
      @confirm="confirmTransfer"
    >
      <SelectField v-model="newOwner" :label="t('team.members.new_owner')">
        <option value="" disabled>{{ t('ui.choose') }}</option>
        <option v-for="member in candidates" :key="member.user_id" :value="member.user_id">{{ member.name }}</option>
      </SelectField>
      <p v-if="loadingCandidates" class="text-caption text-text-muted" role="status">{{ t('common.loading') }}</p>
    </ConfirmDialog>
  </WorkspaceLayout>
</template>
