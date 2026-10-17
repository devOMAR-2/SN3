<script setup lang="ts">
import AccountLogoutButton from '@/Components/AccountLogoutButton.vue';
import InvitationsPanel from '@/Components/Teams/InvitationsPanel.vue';
import { useI18n } from '@/i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

/** `/teams/{team}/invitations` (CORE-002): the team's invitations, for its Owner and Admins. */
defineProps<{
  team: { id: string; name: string };
  can: { invite_admin: boolean };
}>();

const { t } = useI18n();
</script>

<template>
  <AppLayout :title="`${t('invitations.panel.title')} · ${team.name}`">
    <template #user-menu>
      <AccountLogoutButton variant="ghost" size="sm" />
    </template>

    <h1 class="mb-6 text-title font-semibold">{{ team.name }}</h1>
    <InvitationsPanel :team-id="team.id" :can-invite-admin="can.invite_admin" />
  </AppLayout>
</template>
