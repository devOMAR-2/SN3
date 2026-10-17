<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, useId, watch } from 'vue';
import TextLink from '@/Components/TextLink.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import LoadError from '@/Components/ui/LoadError.vue';
import SelectField from '@/Components/ui/SelectField.vue';
import SkeletonRows from '@/Components/ui/SkeletonRows.vue';
import TextAreaField from '@/Components/ui/TextAreaField.vue';
import TextField from '@/Components/ui/TextField.vue';
import LockBanner from '@/Components/Workspace/LockBanner.vue';
import PageHeader from '@/Components/Workspace/PageHeader.vue';
import WizardSteps from '@/Components/Workspace/WizardSteps.vue';
import WorkspaceLayout from '@/Components/Workspace/WorkspaceLayout.vue';
import { useApiForm } from '@/Composables/useApiForm';
import { useCursorList } from '@/Composables/useCursorList';
import { invalidateNavProjects } from '@/Composables/useNavProjects';
import { showToast } from '@/Composables/useToast';
import { useI18n } from '@/i18n';
import { api } from '@/lib/api';
import { suggestProjectKey } from '@/lib/format';
import { pages } from '@/lib/routes';
import type { Lock, Project, ProjectRole, Team, TeamAbilities, TeamMember, Visibility } from '@/types/domain';

/**
 * Create a project (DESIGN_SYSTEM.md §5.1; API_SPEC.md §4): name, key, description, visibility (team-visible
 * by default) and, optionally, explicit members. POST /api/v1/teams/{team}/projects makes the creator a
 * Project Manager; each chosen member is then added with PUT /api/v1/projects/{project}/members/{user}.
 * Templates arrive with FLEX-004 (the API accepts none yet).
 */
const props = defineProps<{ team: Team; can: TeamAbilities; lock: Lock; onboarding: boolean }>();

const { t } = useI18n();
const page = usePage();
const me = computed(() => page.props.auth.user?.id ?? '');
const form = useApiForm('project', { name: '', key: '', description: '', visibility: 'team' as Visibility });
const keyEdited = ref(false);
const visibilityName = `visibility-${useId()}`;

watch(
  () => form.data.name,
  (name) => {
    if (!keyEdited.value) {
      form.data.key = suggestProjectKey(name);
    }
  },
);

// Optional explicit members: anyone in the team except the creator, who becomes a manager anyway.
const members = useCursorList<TeamMember>(
  () => `/teams/${props.team.id}/members`,
  () => ({}),
  100,
);
const chosen = reactive<Record<string, ProjectRole | ''>>({});
onMounted(() => void members.reload());
const others = computed(() => members.items.value.filter((member) => member.user_id !== me.value));
const memberError = ref<string | null>(null);

async function submit(): Promise<void> {
  memberError.value = null;
  const project = await form.submit<Project>('post', `/teams/${props.team.id}/projects`);

  if (!project) {
    return;
  }

  invalidateNavProjects(props.team.id);
  const additions = Object.entries(chosen).filter(([, role]) => role !== '');
  const results = await Promise.allSettled(
    additions.map(([userId, role]) => api.put(`/projects/${project.id}/members/${userId}`, { role })),
  );
  const failed = results.filter((result) => result.status === 'rejected').length;

  if (failed > 0) {
    // The project exists; finish the membership on its settings page.
    showToast(t('project.create_members_failed', { count: failed }), 'error');
    router.visit(pages.projectSettings(props.team.slug, project.key));

    return;
  }

  showToast(t('project.created'));
  router.visit(pages.project(props.team.slug, project.key));
}
</script>

<template>
  <WorkspaceLayout
    :title="t('project.create')"
    :team="team"
    :team-can="can"
    :breadcrumbs="[
      { label: team.name, href: pages.team(team.slug) },
      { label: t('nav.projects'), href: pages.projects(team.slug) },
      { label: t('project.create') },
    ]"
  >
    <LockBanner :lock="lock" />
    <PageHeader
      :title="onboarding ? t('project.first_title') : t('project.create')"
      :subtitle="t('project.create_intro')"
    />
    <WizardSteps
      v-if="onboarding"
      :steps="[t('team.wizard.team'), t('team.wizard.first_project')]"
      :current="1"
      class="mb-6"
    />

    <form v-if="can.create_project" class="flex max-w-xl flex-col gap-5" novalidate @submit.prevent="submit">
      <AppAlert v-if="form.message.value" variant="error">{{ form.message.value }}</AppAlert>
      <TextField
        v-model="form.data.name"
        :label="t('project.fields.name')"
        maxlength="180"
        required
        dir="auto"
        :error="form.errors.name"
      />
      <TextField
        v-model="form.data.key"
        :label="t('project.fields.key')"
        :hint="t('project.key_hint')"
        maxlength="10"
        required
        dir="ltr"
        autocomplete="off"
        class="uppercase"
        :error="form.errors.key"
        @input="keyEdited = true"
      />
      <TextAreaField
        v-model="form.data.description"
        :label="t('project.fields.description')"
        :maxlength="5000"
        :rows="3"
        :error="form.errors.description"
      />

      <fieldset class="flex flex-col gap-2">
        <legend class="mb-1 text-small font-medium">{{ t('project.fields.visibility') }}</legend>
        <label
          v-for="option in ['team', 'private'] as const"
          :key="option"
          class="flex cursor-pointer items-start gap-3 rounded-control border border-border-control p-3 has-checked:border-primary"
        >
          <input
            v-model="form.data.visibility"
            type="radio"
            :name="visibilityName"
            :value="option"
            class="mt-1 size-4 accent-(--primary)"
          />
          <span>
            <span class="block font-medium">{{ t(`project.visibility.${option}`) }}</span>
            <span class="block text-small text-text-muted">{{ t(`project.visibility_help.${option}`) }}</span>
          </span>
        </label>
        <p v-if="form.errors.visibility" class="text-caption text-danger">{{ form.errors.visibility }}</p>
      </fieldset>

      <fieldset class="flex flex-col gap-2">
        <legend class="mb-1 text-small font-medium">{{ t('project.fields.members') }}</legend>
        <p class="text-small text-text-muted">{{ t('project.members_help') }}</p>
        <SkeletonRows v-if="members.loading.value" :rows="2" />
        <LoadError v-else-if="members.error.value" :error="members.error.value" @retry="members.reload" />
        <p v-else-if="others.length === 0" class="text-small text-text-muted">{{ t('project.no_other_members') }}</p>
        <ul v-else class="flex flex-col divide-y rounded-card border bg-surface">
          <li v-for="member in others" :key="member.user_id" class="flex flex-wrap items-center gap-3 p-3">
            <span class="min-w-0 flex-1 truncate" dir="auto">{{ member.name }}</span>
            <SelectField
              :model-value="chosen[member.user_id] ?? ''"
              :label="t('project.member_role_for', { name: member.name })"
              hide-label
              class="w-44"
              @update:model-value="chosen[member.user_id] = $event as ProjectRole | ''"
            >
              <option value="">{{ t('project.not_a_member') }}</option>
              <option value="contributor">{{ t('project.roles.contributor') }}</option>
              <option value="manager">{{ t('project.roles.manager') }}</option>
            </SelectField>
          </li>
        </ul>
      </fieldset>

      <div class="flex flex-wrap items-center gap-4">
        <AppButton type="submit" :loading="form.processing.value">{{ t('project.create_submit') }}</AppButton>
        <TextLink v-if="onboarding" :href="pages.team(team.slug)">{{ t('ui.skip_for_now') }}</TextLink>
      </div>
    </form>
  </WorkspaceLayout>
</template>
