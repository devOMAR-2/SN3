<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import LoadError from '@/Components/ui/LoadError.vue';
import SkeletonRows from '@/Components/ui/SkeletonRows.vue';
import TechnicalId from '@/Components/ui/TechnicalId.vue';
import TextLink from '@/Components/TextLink.vue';
import PageHeader from '@/Components/Workspace/PageHeader.vue';
import WorkspaceLayout from '@/Components/Workspace/WorkspaceLayout.vue';
import { useAction } from '@/Composables/useAction';
import { useCursorList } from '@/Composables/useCursorList';
import { useFormat } from '@/Composables/useFormat';
import { UNREAD_COUNT_CAP, useUnreadCount } from '@/Composables/useUnreadCount';
import { useI18n } from '@/i18n';
import { api, type ApiEnvelope } from '@/lib/api';
import { pages } from '@/lib/routes';
import type { InboxNotification } from '@/types/domain';

/**
 * `/inbox` (DESIGN_SYSTEM.md §3.4 "Notifications with unread controls, target links subject to
 * authorization"; CORE-009, ADR-029 §6). The list comes from GET /api/v1/notifications, which only ever
 * returns notifications about things the user can see now; a link always goes through the task route,
 * which checks access again. Changes are announced in a polite live region; focus never moves.
 */
type Filter = 'all' | 'unread';

const { t } = useI18n();
const format = useFormat();
const page = usePage();
const unread = useUnreadCount();
const filter = ref<Filter>('all');
const list = useCursorList<InboxNotification>(
  () => '/notifications',
  () => (filter.value === 'unread' ? { status: 'unread' } : {}),
);
const marking = useAction();
const announcement = ref('');
const inAppOff = computed(() => page.props.auth.user?.in_app_notifications === false);

onMounted(() => void list.reload());

function setFilter(next: Filter): void {
  if (filter.value !== next) {
    filter.value = next;
    void list.reload();
  }
}

function headline(item: InboxNotification): string {
  const actor = item.actor?.name ?? t('inbox.someone');
  const key = item.type.replace('.', '_');
  const many = item.event_count > 1 && (item.type === 'task.updated' || item.type === 'task.commented');

  return t(many ? `inbox.types_many.${key}` : `inbox.types.${key}`, {
    actor,
    team: item.team.name,
    count: item.event_count,
  });
}

function changed(item: InboxNotification): string | null {
  if (item.type !== 'task.updated' || item.fields.length === 0) {
    return null;
  }

  return t('inbox.changed', {
    fields: item.fields.map((field) => t(`inbox.fields.${field}`)).join(t('inbox.separator')),
  });
}

/** How long the live region stays empty before the next announcement (ms). */
const ANNOUNCE_GAP_MS = 75;
let pendingAnnouncement: ReturnType<typeof setTimeout> | undefined;

/**
 * Says `text` in the live region. It is cleared first and set again a moment later (a tick is not
 * always enough for screen readers), so the same text twice in a row (two items marked read) is still
 * a change they announce.
 */
function announce(text: string): void {
  clearTimeout(pendingAnnouncement);
  announcement.value = '';
  pendingAnnouncement = setTimeout(() => (announcement.value = text), ANNOUNCE_GAP_MS);
}

onBeforeUnmount(() => clearTimeout(pendingAnnouncement));

function markedReadText(item: InboxNotification): string {
  return item.task !== null
    ? t('inbox.marked_read', { key: item.task.key })
    : t('inbox.marked_read_invitation', { team: item.team.name });
}

async function refreshCount(): Promise<void> {
  try {
    const response = await api.get<ApiEnvelope<{ unread_count: number }>>('/notifications/unread-count');
    unread.set(response.data.data.unread_count);
  } catch {
    // The badge keeps its last value; the list itself is authoritative.
  }
}

async function markRead(item: InboxNotification): Promise<boolean> {
  if (item.read_at !== null) {
    return true;
  }

  const updated = await marking.run(
    async () => (await api.post<ApiEnvelope<InboxNotification>>(`/notifications/${item.id}/read`)).data.data,
  );

  if (updated === null) {
    // Gone meanwhile (access changed): it leaves the list.
    if (marking.error.value?.status === 404) {
      marking.clear();
      list.replace((entry) => entry.id === item.id, null);
      void refreshCount();
    }

    return false;
  }

  list.replace((entry) => entry.id === item.id, filter.value === 'unread' ? null : updated);
  // At the cap the real count is unknown (100 or more): ask again rather than count down.
  if (unread.count.value >= UNREAD_COUNT_CAP) {
    void refreshCount();
  } else {
    unread.set(unread.count.value - 1);
  }
  announce(markedReadText(item));

  return true;
}

async function open(item: InboxNotification): Promise<void> {
  if (item.task === null) {
    return;
  }

  await markRead(item);
  router.visit(pages.task(item.task.id));
}

async function markAllRead(): Promise<void> {
  const result = await marking.run(
    async () => (await api.post<ApiEnvelope<{ marked_read: number }>>('/notifications/read-all')).data.data,
  );

  if (result === null) {
    return;
  }

  announce(result.marked_read > 0 ? t('inbox.all_marked_read') : t('inbox.nothing_to_mark'));
  await list.reload();
  await refreshCount();
}

const filterClass =
  'h-8 rounded-control px-3 text-small font-medium aria-pressed:bg-surface-hover aria-pressed:text-text text-text-muted hover:bg-surface-hover pointer-coarse:h-11';
</script>

<template>
  <WorkspaceLayout :title="t('inbox.title')" :breadcrumbs="[{ label: t('inbox.title') }]">
    <PageHeader :title="t('inbox.title')">
      <template #actions>
        <AppButton
          variant="secondary"
          :loading="marking.processing.value"
          :disabled="list.loading.value"
          @click="markAllRead"
          >{{ t('inbox.mark_all_read') }}</AppButton
        >
      </template>
    </PageHeader>

    <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>
    <AppAlert v-if="inAppOff" variant="info" class="mb-4">
      {{ t('inbox.disabled') }} <TextLink :href="pages.profile()">{{ t('nav.profile') }}</TextLink>
    </AppAlert>
    <AppAlert v-if="marking.message.value" variant="error" class="mb-4">{{ marking.message.value }}</AppAlert>

    <div role="group" :aria-label="t('inbox.filters.label')" class="mb-4 flex gap-1">
      <button type="button" :class="filterClass" :aria-pressed="filter === 'all'" @click="setFilter('all')">
        {{ t('inbox.filters.all') }}
      </button>
      <button type="button" :class="filterClass" :aria-pressed="filter === 'unread'" @click="setFilter('unread')">
        {{ t('inbox.filters.unread') }}
      </button>
    </div>

    <SkeletonRows v-if="list.loading.value" />
    <LoadError v-else-if="list.error.value" :error="list.error.value" @retry="list.reload" />
    <EmptyState
      v-else-if="list.items.value.length === 0"
      :title="filter === 'unread' ? t('inbox.empty.unread_title') : t('inbox.empty.all_title')"
      :description="filter === 'unread' ? t('inbox.empty.unread_body') : t('inbox.empty.all_body')"
    />
    <ul v-else class="flex flex-col divide-y rounded-card border bg-surface">
      <li
        v-for="item in list.items.value"
        :key="item.id"
        class="flex flex-wrap items-start gap-3 p-4"
        :class="item.read_at === null && 'bg-surface-muted'"
        :data-unread="item.read_at === null ? 'true' : undefined"
      >
        <span
          class="mt-2 size-2 shrink-0 rounded-pill"
          :class="item.read_at === null ? 'bg-primary' : 'bg-transparent'"
          aria-hidden="true"
        ></span>
        <div class="min-w-0 flex-1">
          <p :class="item.read_at === null ? 'font-semibold' : 'font-medium'">
            <span v-if="item.read_at === null" class="sr-only">{{ t('inbox.unread') }}: </span>{{ headline(item) }}
          </p>
          <!-- A plain link, so a new tab still works; a normal click marks it read, then visits. -->
          <a
            v-if="item.task"
            :href="pages.task(item.task.id)"
            class="mt-1 flex flex-wrap items-baseline gap-2 hover:underline"
            @click.exact.prevent="open(item)"
          >
            <TechnicalId class="text-small text-text-muted">{{ item.task.key }}</TechnicalId>
            <span class="min-w-0 break-words" dir="auto">{{ item.task.title }}</span>
          </a>
          <p v-else-if="item.type === 'team.invitation'" class="mt-1 text-small text-text-muted">
            {{ t('inbox.invitation_hint') }}
          </p>
          <p v-if="changed(item)" class="mt-1 text-small text-text-muted">{{ changed(item) }}</p>
          <p class="mt-1 text-caption text-text-muted">
            <span v-if="item.project" dir="auto">{{
              t('inbox.where', { project: item.project.name, team: item.team.name })
            }}</span>
            <span v-if="item.project" aria-hidden="true"> · </span>
            <time :datetime="item.updated_at">{{ format.dateTime(item.updated_at) }}</time>
          </p>
        </div>
        <AppButton
          v-if="item.read_at === null"
          variant="ghost"
          size="sm"
          :disabled="marking.processing.value"
          @click="markRead(item)"
          >{{ t('inbox.mark_read') }}</AppButton
        >
      </li>
    </ul>
    <AppButton
      v-if="list.hasMore.value"
      class="mt-3"
      variant="secondary"
      :loading="list.loadingMore.value"
      @click="list.loadMore"
      >{{ t('ui.load_more') }}</AppButton
    >
  </WorkspaceLayout>
</template>
