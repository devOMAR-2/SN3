<script setup lang="ts">
import { computed, onMounted, ref, useId, watch } from 'vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import TextField from '@/Components/ui/TextField.vue';
import { useApiForm } from '@/Composables/useApiForm';
import { intlLocales, useI18n } from '@/i18n';
import { api, toApiError } from '@/lib/api';
import { randomUuid } from '@/lib/uuid';

/**
 * Members/Invites panel (CORE-002, API_SPEC.md §3.1, DESIGN_SYSTEM.md §6 "Invite UI"): create an email
 * invitation or a share link, list what was sent, revoke. Rendered for the team's Owner and Admins only;
 * the server refuses everyone else whatever this component shows.
 *
 * Self-contained so the team Members page (CORE-006) can mount it: `<InvitationsPanel :team-id :can-invite-admin />`.
 */
type Role = 'admin' | 'member' | 'viewer';
type InvitationType = 'email' | 'link';

interface Invitation {
  id: string;
  type: InvitationType;
  email: string | null;
  role: Role;
  status: 'pending' | 'accepted' | 'revoked' | 'expired';
  use_count: number;
  max_uses: number;
  invited_by: { user_id: string; name: string };
  expires_at: string;
  created_at: string | null;
}

interface CreatedInvitation extends Invitation {
  token: string | null;
  url: string | null;
}

interface Page {
  data: Invitation[];
  meta: { next_cursor: string | null; has_more: boolean };
}

const props = defineProps<{
  teamId: string;
  /** Only the Owner may invite an Admin, and only by email (API_SPEC.md §3.1). */
  canInviteAdmin: boolean;
}>();

const { t, locale } = useI18n();
const ids = { type: useId(), role: useId(), link: useId() };

const form = useApiForm('invitations', { type: 'email' as InvitationType, email: '', role: 'member' as Role });
// One key per logical create: a retry after a network failure replays instead of creating twice.
let idempotencyKey = randomUuid();
const created = ref<CreatedInvitation | null>(null);
const copied = ref(false);

const roles = computed<Role[]>(() =>
  form.data.type === 'email' && props.canInviteAdmin ? ['admin', 'member', 'viewer'] : ['member', 'viewer'],
);

watch(roles, (available) => {
  if (!available.includes(form.data.role)) {
    form.data.role = 'member';
  }
});

const invitations = ref<Invitation[]>([]);
const nextCursor = ref<string | null>(null);
const listError = ref<string | null>(null);
const loadingList = ref(false);
const confirming = ref<string | null>(null);
const revoking = ref<string | null>(null);
const notice = ref<string | null>(null);

const dateFormat = computed(() => new Intl.DateTimeFormat(intlLocales[locale.value], { dateStyle: 'medium' }));

async function loadPage(cursor: string | null = null): Promise<void> {
  loadingList.value = true;
  listError.value = null;

  try {
    const response = await api.get<Page>(`/teams/${props.teamId}/invitations`, {
      params: { limit: 20, ...(cursor === null ? {} : { cursor }) },
    });
    invitations.value = cursor === null ? response.data.data : [...invitations.value, ...response.data.data];
    nextCursor.value = response.data.meta.has_more ? response.data.meta.next_cursor : null;
  } catch (thrown) {
    listError.value = toApiError(thrown).message;
  } finally {
    loadingList.value = false;
  }
}

async function submit(): Promise<void> {
  created.value = null;
  copied.value = false;
  notice.value = null;

  const body =
    form.data.type === 'email'
      ? { type: 'email', email: form.data.email, role: form.data.role }
      : { type: 'link', role: form.data.role };
  const result = await form.submit<CreatedInvitation>('post', `/teams/${props.teamId}/invitations`, {
    body,
    headers: { 'Idempotency-Key': idempotencyKey },
  });

  if (result === null) {
    return;
  }

  idempotencyKey = randomUuid();
  created.value = result;
  form.reset('email');
  await loadPage();
}

async function copyLink(url: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(url);
    copied.value = true;
  } catch {
    // Clipboard blocked: the link stays selectable in the field.
    copied.value = false;
  }
}

async function revoke(invitation: Invitation): Promise<void> {
  revoking.value = invitation.id;
  notice.value = null;

  try {
    await api.delete(`/teams/${props.teamId}/invitations/${invitation.id}`);
    notice.value = t('invitations.panel.revoked');
    confirming.value = null;
    await loadPage();
  } catch (thrown) {
    listError.value = toApiError(thrown).message;
  } finally {
    revoking.value = null;
  }
}

onMounted(() => loadPage());

const statusClasses: Record<Invitation['status'], string> = {
  pending: 'bg-info-subtle text-info',
  accepted: 'bg-success-subtle text-success',
  revoked: 'bg-surface-muted text-text-muted',
  expired: 'bg-surface-muted text-text-muted',
};

const selectClass =
  'h-9 w-full rounded-control border border-border-control bg-surface px-3 text-body text-text pointer-coarse:h-11';
</script>

<template>
  <section class="flex flex-col gap-6" :aria-labelledby="`${ids.type}-title`">
    <div>
      <h2 :id="`${ids.type}-title`" class="text-heading font-semibold">{{ t('invitations.panel.title') }}</h2>
      <p class="mt-1 text-small text-text-muted">{{ t('invitations.panel.intro') }}</p>
    </div>

    <form class="flex max-w-md flex-col gap-4" novalidate @submit.prevent="submit">
      <AppAlert v-if="form.message.value" variant="error">{{ form.message.value }}</AppAlert>

      <fieldset class="flex flex-col gap-2">
        <legend class="mb-1 text-small font-medium text-text">{{ t('invitations.panel.type_label') }}</legend>
        <label v-for="type in ['email', 'link'] as const" :key="type" class="flex items-start gap-2">
          <input v-model="form.data.type" type="radio" :name="ids.type" :value="type" class="mt-1" />
          <span>
            <span class="block text-body">{{ t(`invitations.panel.type_${type}`) }}</span>
            <span class="block text-small text-text-muted">{{ t(`invitations.panel.type_${type}_hint`) }}</span>
          </span>
        </label>
      </fieldset>

      <TextField
        v-if="form.data.type === 'email'"
        v-model="form.data.email"
        :label="t('invitations.panel.email')"
        type="email"
        name="email"
        autocomplete="off"
        maxlength="255"
        required
        :error="form.errors.email"
      />

      <div class="flex flex-col gap-1.5">
        <label :for="ids.role" class="text-small font-medium text-text">{{ t('invitations.panel.role') }}</label>
        <select :id="ids.role" v-model="form.data.role" name="role" :class="selectClass">
          <option v-for="role in roles" :key="role" :value="role">{{ t(`invitations.roles.${role}`) }}</option>
        </select>
        <p v-if="canInviteAdmin" class="text-small text-text-muted">{{ t('invitations.panel.admin_hint') }}</p>
        <p v-if="form.errors.role" class="text-small text-danger" role="alert">{{ form.errors.role }}</p>
      </div>

      <AppButton type="submit" :loading="form.processing.value" class="self-start">
        {{ t(form.data.type === 'email' ? 'invitations.panel.submit_email' : 'invitations.panel.submit_link') }}
      </AppButton>
    </form>

    <div v-if="created !== null" class="flex max-w-xl flex-col gap-3" aria-live="polite">
      <AppAlert variant="success">
        {{
          created.type === 'email'
            ? t('invitations.panel.created_email', { email: created.email ?? '' })
            : t('invitations.panel.created_link')
        }}
      </AppAlert>
      <template v-if="created.url !== null">
        <AppAlert variant="warning">{{ t('invitations.panel.copy_warning') }}</AppAlert>
        <label :for="ids.link" class="text-small font-medium text-text">{{ t('invitations.panel.link_label') }}</label>
        <div class="flex flex-wrap gap-2">
          <input
            :id="ids.link"
            :value="created.url"
            readonly
            dir="ltr"
            class="h-9 min-w-0 flex-1 rounded-control border border-border-control bg-surface-muted px-3 text-small text-text"
            @focus="($event.target as HTMLInputElement).select()"
          />
          <AppButton variant="secondary" @click="copyLink(created.url)">{{ t('invitations.panel.copy') }}</AppButton>
        </div>
        <p v-if="copied" class="text-small text-success" role="status">{{ t('invitations.panel.copied') }}</p>
      </template>
    </div>

    <div class="flex flex-col gap-3">
      <h3 class="text-body font-semibold">{{ t('invitations.panel.list_title') }}</h3>
      <AppAlert v-if="notice" variant="success">{{ notice }}</AppAlert>
      <AppAlert v-if="listError" variant="error">{{ listError }}</AppAlert>
      <p v-if="!loadingList && invitations.length === 0 && listError === null" class="text-small text-text-muted">
        {{ t('invitations.panel.empty') }}
      </p>

      <ul class="flex flex-col divide-y divide-border rounded-card border border-border">
        <li
          v-for="invitation in invitations"
          :key="invitation.id"
          class="flex flex-wrap items-center gap-x-4 gap-y-2 p-3"
        >
          <div class="min-w-0 flex-1">
            <p class="truncate text-body">
              <span v-if="invitation.email !== null" dir="ltr" class="[unicode-bidi:isolate]">{{
                invitation.email
              }}</span>
              <span v-else>{{ t('invitations.panel.share_link') }}</span>
              <span class="text-text-muted"> · {{ t(`invitations.roles.${invitation.role}`) }}</span>
            </p>
            <p class="text-small text-text-muted">
              {{ t('invitations.panel.uses', { count: invitation.use_count, max: invitation.max_uses }) }} ·
              {{ t('invitations.panel.expires', { date: dateFormat.format(new Date(invitation.expires_at)) }) }} ·
              {{ t('invitations.panel.invited_by', { name: invitation.invited_by.name }) }}
            </p>
          </div>

          <span class="rounded-pill px-2 py-0.5 text-caption font-medium" :class="statusClasses[invitation.status]">
            {{ t(`invitations.status.${invitation.status}`) }}
          </span>

          <template v-if="invitation.status === 'pending'">
            <div v-if="confirming === invitation.id" class="flex flex-wrap items-center gap-2" role="group">
              <span class="text-small">{{ t('invitations.panel.revoke_confirm') }}</span>
              <AppButton variant="danger" size="sm" :loading="revoking === invitation.id" @click="revoke(invitation)">
                {{ t('invitations.panel.revoke') }}
              </AppButton>
              <AppButton variant="ghost" size="sm" @click="confirming = null">{{
                t('invitations.panel.cancel')
              }}</AppButton>
            </div>
            <AppButton v-else variant="ghost" size="sm" @click="confirming = invitation.id">
              {{ t('invitations.panel.revoke') }}
            </AppButton>
          </template>
        </li>
      </ul>

      <AppButton
        v-if="nextCursor !== null"
        variant="secondary"
        :loading="loadingList"
        class="self-start"
        @click="loadPage(nextCursor)"
      >
        {{ t('invitations.panel.load_more') }}
      </AppButton>
    </div>
  </section>
</template>
