<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import TextLink from '@/Components/TextLink.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import { intlLocales, useI18n } from '@/i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { api, toApiError, type ApiEnvelope } from '@/lib/api';
import { readInvitationToken, rememberPendingInvitation, takePendingInvitation } from '@/lib/invitationLink';

/**
 * `/invite#<token>` (CORE-002, API_SPEC.md §3.1, ADR-025 §4): shows the invitation's team and role,
 * then lets a verified, signed-in user join. The token comes from the URL fragment (never sent to the
 * server) or, after a sign-in round trip, from this tab's sessionStorage.
 */
interface Preview {
  team: { name: string };
  role: 'admin' | 'member' | 'viewer';
  type: 'email' | 'link';
  expires_at: string;
}

type Problem = 'missing' | 'not_found' | 'expired' | 'wrong_email' | 'archived' | 'other';

const { t, locale } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);

const token = readInvitationToken(window.location.hash) ?? takePendingInvitation();
const preview = ref<Preview | null>(null);
const loading = ref(token !== null);
const joining = ref(false);
const joined = ref<'joined' | 'already' | null>(null);
const problem = ref<Problem | null>(token === null ? 'missing' : null);
const message = ref<string | null>(null);

const expires = computed(() =>
  preview.value === null
    ? ''
    : new Intl.DateTimeFormat(intlLocales[locale.value], { dateStyle: 'long' }).format(
        new Date(preview.value.expires_at),
      ),
);

function problemFor(status: number): Problem {
  switch (status) {
    case 403:
      return 'wrong_email';
    case 404:
      return 'not_found';
    case 410:
      return 'expired';
    case 423:
      return 'archived';
    default:
      return 'other';
  }
}

onMounted(async () => {
  // Drop the token from the address bar and history (Inertia keeps writing its page URL back).
  if (window.location.hash !== '') {
    router.replace({
      url: window.location.pathname + window.location.search,
      preserveState: true,
      preserveScroll: true,
    });
  }

  if (token === null) {
    return;
  }

  try {
    const response = await api.get<ApiEnvelope<Preview>>(`/invitations/${token}/preview`);
    preview.value = response.data.data;
  } catch (thrown) {
    const error = toApiError(thrown);
    problem.value = problemFor(error.status);
    message.value = problem.value === 'other' ? error.message : null;
  } finally {
    loading.value = false;
  }
});

function signInFirst(path: '/login' | '/register'): void {
  if (token !== null) {
    rememberPendingInvitation(token);
  }

  router.visit(path);
}

async function join(): Promise<void> {
  if (token === null || joining.value) {
    return;
  }

  joining.value = true;
  message.value = null;

  try {
    const response = await api.post<ApiEnvelope<unknown>>(`/invitations/${token}/accept`);
    joined.value = response.status === 201 ? 'joined' : 'already';
  } catch (thrown) {
    const error = toApiError(thrown);
    problem.value = problemFor(error.status);
    message.value = problem.value === 'other' ? error.message : null;
  } finally {
    joining.value = false;
  }
}
</script>

<template>
  <GuestLayout :title="t('invitations.accept.title')">
    <p v-if="loading" role="status" class="text-body text-text-muted">{{ t('invitations.accept.loading') }}</p>

    <template v-else-if="joined !== null && preview !== null">
      <AppAlert variant="success">
        {{
          t(joined === 'joined' ? 'invitations.accept.joined' : 'invitations.accept.already_member', {
            team: preview.team.name,
          })
        }}
      </AppAlert>
      <p class="mt-6 text-center">
        <TextLink href="/">{{ t('invitations.accept.continue') }}</TextLink>
      </p>
    </template>

    <AppAlert v-else-if="problem !== null" variant="error">
      <template v-if="problem === 'other'">{{ message }}</template>
      <template v-else>{{ t(`invitations.accept.${problem}`) }}</template>
    </AppAlert>

    <div v-else-if="preview !== null" class="flex flex-col gap-4">
      <h2 class="text-heading font-semibold">
        {{ t('invitations.accept.heading', { team: preview.team.name }) }}
      </h2>
      <p class="text-body">{{ t('invitations.accept.role', { role: t(`invitations.roles.${preview.role}`) }) }}</p>
      <p class="text-small text-text-muted">{{ t('invitations.accept.expires', { date: expires }) }}</p>
      <p v-if="preview.type === 'email'" class="text-small text-text-muted">
        {{ t('invitations.accept.email_bound') }}
      </p>
      <p class="text-small text-text-muted">{{ t('invitations.accept.private_note') }}</p>

      <template v-if="user === null">
        <AppButton class="w-full" @click="signInFirst('/login')">{{ t('invitations.accept.sign_in') }}</AppButton>
        <AppButton
          v-if="page.props.features.registration"
          variant="secondary"
          class="w-full"
          @click="signInFirst('/register')"
        >
          {{ t('invitations.accept.register') }}
        </AppButton>
      </template>

      <AppAlert v-else-if="!user.email_verified" variant="warning">
        {{ t('invitations.accept.verify_first') }}
        <TextLink href="/verify-email">{{ t('invitations.accept.verify_link') }}</TextLink>
      </AppAlert>

      <AppButton v-else class="w-full" :loading="joining" @click="join">{{ t('invitations.accept.join') }}</AppButton>
    </div>
  </GuestLayout>
</template>
