<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import TextAreaField from '@/Components/ui/TextAreaField.vue';
import TextField from '@/Components/ui/TextField.vue';
import LockBanner from '@/Components/Workspace/LockBanner.vue';
import PageHeader from '@/Components/Workspace/PageHeader.vue';
import TeamExportPanel from '@/Components/Workspace/TeamExportPanel.vue';
import WorkspaceLayout from '@/Components/Workspace/WorkspaceLayout.vue';
import { useAction } from '@/Composables/useAction';
import { useApiForm } from '@/Composables/useApiForm';
import { showToast } from '@/Composables/useToast';
import { useI18n } from '@/i18n';
import { api, type ApiError } from '@/lib/api';
import { pages } from '@/lib/routes';
import type { Lock, Team, TeamAbilities } from '@/types/domain';

/**
 * `/t/{team_slug}/settings` (CORE-006): general settings (Owner, Admin) and the danger zone (Owner):
 * archive / unarchive, export, and delete to the 30-day trash with an "Export first" step
 * (DECIDED-011, ADR-022, ADR-024).
 */
const props = defineProps<{ team: Team; can: TeamAbilities; lock: Lock }>();
const { t } = useI18n();

// A write answered 423 (the team was archived meanwhile): show the read-only banner at once, and reload
// the server's abilities so the editors disappear.
const lockedNow = ref(false);

function lockedBy(error: ApiError | null): boolean {
  if (error?.status !== 423) {
    return false;
  }

  lockedNow.value = true;
  archiveOpen.value = false;
  deleteOpen.value = false;
  router.reload();

  return true;
}

const form = useApiForm('team', {
  name: props.team.name,
  slug: props.team.slug,
  description: props.team.description ?? '',
});
const saved = ref(false);

async function save(): Promise<void> {
  saved.value = false;
  const updated = await form.submit<Team>('patch', `/teams/${props.team.id}`);

  if (!updated) {
    lockedBy(form.error.value);

    return;
  }

  showToast(t('ui.saved'));

  if (updated.slug !== props.team.slug) {
    router.visit(pages.teamSettings(updated.slug), { replace: true });
  } else {
    saved.value = true;
    router.reload();
  }
}

// Archive and unarchive.
const archive = useAction();
const archiveOpen = ref(false);

async function setArchived(archived: boolean): Promise<void> {
  const done = await archive.run(() => api.post(`/teams/${props.team.id}/${archived ? 'archive' : 'restore'}`));

  if (done !== null) {
    archiveOpen.value = false;
    showToast(t(archived ? 'team.danger.archived' : 'team.danger.unarchived'));
    router.reload();
  } else {
    lockedBy(archive.error.value);
  }
}

// Delete to the trash, after the "Export first" step.
const remove = useAction();
const deleteOpen = ref(false);
const exportStepDone = ref(false);
const step = computed(() => (exportStepDone.value ? 2 : 1));

function askDelete(): void {
  remove.clear();
  exportStepDone.value = false;
  deleteOpen.value = true;
}

async function confirmDelete(): Promise<void> {
  const done = await remove.run(() => api.delete(`/teams/${props.team.id}`));

  if (done !== null) {
    deleteOpen.value = false;
    showToast(t('team.danger.deleted'));
    router.visit(pages.teams());
  } else {
    lockedBy(remove.error.value);
  }
}
</script>

<template>
  <WorkspaceLayout
    :title="t('nav.settings')"
    :team="team"
    :team-can="can"
    :breadcrumbs="[{ label: team.name, href: pages.team(team.slug) }, { label: t('nav.settings') }]"
  >
    <LockBanner :lock="lock ?? (lockedNow ? 'read_only' : null)" />
    <PageHeader :title="t('team.settings.title')" />

    <section aria-labelledby="team-general" class="mb-10 flex flex-col gap-4">
      <h2 id="team-general" class="text-heading font-semibold">{{ t('team.settings.general') }}</h2>
      <form v-if="can.update" class="flex max-w-xl flex-col gap-4" novalidate @submit.prevent="save">
        <AppAlert v-if="saved" variant="success">{{ t('ui.saved') }}</AppAlert>
        <AppAlert v-if="form.message.value" variant="error">{{ form.message.value }}</AppAlert>
        <TextField
          v-model="form.data.name"
          :label="t('team.fields.name')"
          maxlength="100"
          required
          dir="auto"
          :error="form.errors.name"
        />
        <TextField
          v-model="form.data.slug"
          :label="t('team.fields.slug')"
          :hint="t('team.slug_edit_hint')"
          maxlength="40"
          dir="ltr"
          autocomplete="off"
          :error="form.errors.slug"
        />
        <TextAreaField
          v-model="form.data.description"
          :label="t('team.fields.description')"
          :maxlength="1000"
          :rows="3"
          :error="form.errors.description"
        />
        <AppButton type="submit" class="self-start" :loading="form.processing.value">{{ t('ui.save') }}</AppButton>
      </form>
      <p v-else class="text-small text-text-muted">{{ t('team.settings.read_only') }}</p>
    </section>

    <section v-if="can.export" aria-labelledby="team-export" class="mb-10 flex max-w-xl flex-col gap-3">
      <h2 id="team-export" class="text-heading font-semibold">{{ t('team.export.title') }}</h2>
      <TeamExportPanel :team-id="team.id" />
    </section>

    <section
      v-if="can.archive || can.unarchive || can.delete"
      aria-labelledby="team-danger"
      class="flex max-w-xl flex-col gap-4 rounded-card border border-danger/40 p-4"
    >
      <h2 id="team-danger" class="text-heading font-semibold text-danger">{{ t('ui.danger_zone') }}</h2>
      <AppAlert v-if="archive.message.value && !archiveOpen" variant="error">{{ archive.message.value }}</AppAlert>

      <div v-if="can.archive" class="flex flex-col gap-2">
        <p class="text-small text-text-muted">{{ t('team.danger.archive_body') }}</p>
        <AppButton class="self-start" variant="secondary" @click="archiveOpen = true">{{
          t('team.danger.archive')
        }}</AppButton>
      </div>
      <div v-if="can.unarchive" class="flex flex-col gap-2">
        <p class="text-small text-text-muted">{{ t('team.danger.unarchive_body') }}</p>
        <AppButton
          class="self-start"
          variant="secondary"
          :loading="archive.processing.value"
          @click="setArchived(false)"
        >
          {{ t('team.danger.unarchive') }}
        </AppButton>
      </div>
      <div v-if="can.delete" class="flex flex-col gap-2">
        <p class="text-small text-text-muted">{{ t('team.danger.delete_body') }}</p>
        <AppButton class="self-start" variant="danger" @click="askDelete">{{ t('team.danger.delete') }}</AppButton>
      </div>
    </section>

    <ConfirmDialog
      v-model:open="archiveOpen"
      :title="t('team.danger.archive_title', { team: team.name })"
      :description="t('team.danger.archive_body')"
      :confirm-label="t('team.danger.archive')"
      variant="primary"
      :loading="archive.processing.value"
      :error="archive.message.value"
      @confirm="setArchived(true)"
    />

    <ConfirmDialog
      v-model:open="deleteOpen"
      :title="t('team.danger.delete_title', { team: team.name })"
      :description="t('team.danger.delete_dialog_body')"
      :confirm-label="t('team.danger.delete')"
      :confirm-text="step === 2 ? team.name : undefined"
      :confirm-disabled="step === 1"
      :loading="remove.processing.value"
      :error="remove.message.value"
      @confirm="confirmDelete"
    >
      <div v-if="step === 1" class="flex flex-col gap-3">
        <h3 class="font-semibold">{{ t('team.danger.export_first') }}</h3>
        <TeamExportPanel :team-id="team.id" />
        <AppButton class="self-start" variant="ghost" @click="exportStepDone = true">{{
          t('team.danger.continue_to_delete')
        }}</AppButton>
      </div>
      <p v-else class="text-small">{{ t('team.danger.trash_notice') }}</p>
    </ConfirmDialog>
  </WorkspaceLayout>
</template>
