<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import TextLink from '@/Components/TextLink.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import TextAreaField from '@/Components/ui/TextAreaField.vue';
import TextField from '@/Components/ui/TextField.vue';
import PageHeader from '@/Components/Workspace/PageHeader.vue';
import WizardSteps from '@/Components/Workspace/WizardSteps.vue';
import WorkspaceLayout from '@/Components/Workspace/WorkspaceLayout.vue';
import { useApiForm } from '@/Composables/useApiForm';
import { showToast } from '@/Composables/useToast';
import { useI18n } from '@/i18n';
import { pages } from '@/lib/routes';
import type { Team } from '@/types/domain';

/**
 * Team creation wizard (DESIGN_SYSTEM.md §3.4: name → optional invitations → first project). Step 1 creates
 * the team (POST /api/v1/teams); the first project is the next page. The invitations step belongs to
 * CORE-002, which plugs its panel in between (see Teams/Members.vue).
 */
defineProps<{ can: { create_team: boolean } }>();

const { t } = useI18n();
const form = useApiForm('team', { name: '', slug: '', description: '' });

// Empty optional fields arrive as null (ConvertEmptyStringsToNull): the server derives the slug.
async function submit(): Promise<void> {
  const team = await form.submit<Team>('post', '/teams');

  if (team) {
    showToast(t('team.created'));
    router.visit(pages.newProject(team.slug, true));
  }
}
</script>

<template>
  <WorkspaceLayout
    :title="t('team.create')"
    :breadcrumbs="[{ label: t('nav.teams'), href: pages.teams() }, { label: t('team.create') }]"
  >
    <PageHeader :title="t('team.create')" :subtitle="t('team.create_intro')" />
    <WizardSteps :steps="[t('team.wizard.team'), t('team.wizard.first_project')]" :current="0" class="mb-6" />

    <AppAlert v-if="!can.create_team" variant="warning">
      {{ t('account.home.unverified') }}
      <TextLink :href="pages.verifyEmail()">{{ t('account.home.verify_link') }}</TextLink>
    </AppAlert>

    <form v-else class="flex max-w-xl flex-col gap-4" novalidate @submit.prevent="submit">
      <AppAlert v-if="form.message.value" variant="error">{{ form.message.value }}</AppAlert>
      <TextField
        v-model="form.data.name"
        :label="t('team.fields.name')"
        name="name"
        maxlength="100"
        required
        dir="auto"
        :error="form.errors.name"
      />
      <TextField
        v-model="form.data.slug"
        :label="t('team.fields.slug')"
        :hint="t('team.slug_hint')"
        name="slug"
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
      <div class="flex flex-wrap gap-2">
        <AppButton type="submit" :loading="form.processing.value">{{ t('team.create_submit') }}</AppButton>
      </div>
    </form>
  </WorkspaceLayout>
</template>
