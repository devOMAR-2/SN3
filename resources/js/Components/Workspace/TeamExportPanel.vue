<script setup lang="ts">
import { Download } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import { useAction } from '@/Composables/useAction';
import { useI18n } from '@/i18n';
import { api, toApiError, type ApiEnvelope } from '@/lib/api';
import { createRetryKey } from '@/lib/http';
import type { TeamExport } from '@/types/domain';

/**
 * Team export (CORE-012, ADR-024): POST /api/v1/teams/{team}/exports with an Idempotency-Key, then poll
 * the export until it is ready and offer its signed download link. Used on its own and as the
 * "Export first" step before deleting a team (DECIDED-011, ADR-022 §5).
 */
const props = defineProps<{ teamId: string }>();
const emit = defineEmits<{ ready: [TeamExport] }>();

const { t } = useI18n();
const action = useAction();
const current = ref<TeamExport | null>(null);
const pollError = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;
let attempts = 0;
// Kept until an export is accepted: retrying after a failure never queues a second export.
const exportKey = createRetryKey();

const busy = computed(() => current.value?.status === 'pending' || current.value?.status === 'processing');

function stop(): void {
  if (timer !== null) {
    clearTimeout(timer);
    timer = null;
  }
}

async function poll(): Promise<void> {
  const exportId = current.value?.id;

  if (!exportId) {
    return;
  }

  try {
    const response = await api.get<ApiEnvelope<TeamExport>>(`/teams/${props.teamId}/exports/${exportId}`);
    current.value = response.data.data;
  } catch (thrown) {
    // A transient failure is retried a few times; then the user can ask again.
    if (toApiError(thrown).status !== 0 || ++attempts > 5) {
      pollError.value = true;

      return;
    }
  }

  if (current.value?.status === 'ready') {
    emit('ready', current.value);
  } else if (busy.value) {
    timer = setTimeout(() => void poll(), 2000);
  }
}

async function request(): Promise<void> {
  stop();
  pollError.value = false;
  attempts = 0;
  const created = await action.run(
    async () =>
      (
        await api.post<ApiEnvelope<TeamExport>>(`/teams/${props.teamId}/exports`, undefined, {
          headers: { 'Idempotency-Key': exportKey.for('export') },
        })
      ).data.data,
  );

  if (created) {
    exportKey.settle();
    current.value = created;
    await poll();
  }
}

onBeforeUnmount(stop);
</script>

<template>
  <div class="flex flex-col gap-3">
    <p class="text-small text-text-muted">{{ t('team.export.body') }}</p>
    <AppAlert v-if="action.message.value" variant="error">{{ action.message.value }}</AppAlert>
    <AppAlert v-if="pollError" variant="error">{{ t('team.export.status_failed') }}</AppAlert>

    <div role="status" aria-live="polite">
      <p v-if="busy" class="text-small">{{ t('team.export.preparing') }}</p>
      <AppAlert v-else-if="current?.status === 'failed' || current?.status === 'expired'" variant="error">
        {{ t(`team.export.${current.status}`) }}
      </AppAlert>
      <p v-else-if="current?.status === 'ready' && current.download_url" class="flex flex-wrap items-center gap-2">
        <a
          :href="current.download_url"
          class="inline-flex h-9 items-center gap-2 rounded-control border border-border-control bg-surface px-4 font-medium hover:bg-surface-hover pointer-coarse:h-11"
        >
          <Download class="size-4" aria-hidden="true" />{{ t('team.export.download') }}
        </a>
        <span class="text-caption text-text-muted">{{ t('team.export.ready') }}</span>
      </p>
    </div>

    <AppButton class="self-start" variant="secondary" :loading="action.processing.value || busy" @click="request">
      {{ current ? t('team.export.again') : t('team.export.request') }}
    </AppButton>
  </div>
</template>
