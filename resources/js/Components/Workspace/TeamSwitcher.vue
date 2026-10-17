<script setup lang="ts">
import { Check, ChevronsUpDown, Plus } from '@lucide/vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, useId, useTemplateRef } from 'vue';
import { useI18n } from '@/i18n';
import { pages } from '@/lib/routes';
import type { Team } from '@/types/domain';

/**
 * Workspace switcher (FR-AUTH-003, DESIGN_SYSTEM.md §3.1): a disclosure button with the user's teams as
 * links. The active team is UI context only; every page re-resolves it on the server (ARCHITECTURE §5.1).
 */
const props = withDefaults(defineProps<{ current: Team | null; collapsed?: boolean }>(), { collapsed: false });

const { t } = useI18n();
const page = usePage();
const open = ref(false);
const listId = `team-switcher-${useId()}`;
const root = useTemplateRef<HTMLElement>('root');
const button = useTemplateRef<HTMLButtonElement>('button');

const teams = computed(() => page.props.workspace?.teams ?? []);
const hasMore = computed(() => page.props.workspace?.has_more ?? false);
const initial = computed(() => (props.current?.name ?? '').trim().charAt(0) || '·');

function close(returnFocus = false): void {
  open.value = false;

  if (returnFocus) {
    button.value?.focus();
  }
}

function onDocumentClick(event: MouseEvent): void {
  if (open.value && root.value && !root.value.contains(event.target as Node)) {
    close();
  }
}

onMounted(() => document.addEventListener('click', onDocumentClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick));
</script>

<template>
  <div ref="root" class="relative" @keydown.escape.stop="close(true)">
    <button
      ref="button"
      type="button"
      class="flex w-full items-center gap-2 rounded-control border border-border-control bg-surface px-2 text-start text-body hover:bg-surface-hover"
      :class="collapsed ? 'size-11 justify-center' : 'h-11'"
      :aria-expanded="open ? 'true' : 'false'"
      :aria-controls="listId"
      :aria-label="collapsed ? t('nav.switch_team') : undefined"
      :title="collapsed ? (current?.name ?? t('nav.switch_team')) : undefined"
      @click="open = !open"
    >
      <span
        class="flex size-7 shrink-0 items-center justify-center rounded-tiny bg-primary text-small font-semibold text-primary-on"
        aria-hidden="true"
        >{{ initial }}</span
      >
      <template v-if="!collapsed">
        <span class="min-w-0 flex-1">
          <span class="block text-caption text-text-muted">{{ t('nav.team') }}</span>
          <span class="block truncate font-medium">{{ current?.name ?? t('nav.choose_team') }}</span>
        </span>
        <ChevronsUpDown class="size-4 shrink-0 text-text-muted" aria-hidden="true" />
      </template>
    </button>

    <div
      v-show="open"
      :id="listId"
      class="absolute inset-x-0 top-full z-40 mt-1 min-w-56 rounded-card border bg-surface p-1 shadow-panel"
    >
      <p class="px-2 py-1 text-caption text-text-muted">{{ t('nav.your_teams') }}</p>
      <ul class="max-h-72 overflow-y-auto">
        <li v-for="team in teams" :key="team.id">
          <Link
            :href="pages.team(team.slug)"
            class="flex items-center gap-2 rounded-control px-2 py-2 text-body hover:bg-surface-hover"
            :aria-current="team.id === current?.id ? 'true' : undefined"
            @click="close()"
          >
            <Check v-if="team.id === current?.id" class="size-4 shrink-0 text-primary" aria-hidden="true" />
            <span v-else class="size-4 shrink-0" aria-hidden="true" />
            <span class="min-w-0 flex-1 truncate">{{ team.name }}</span>
            <span v-if="team.archived" class="text-caption text-text-muted">{{ t('team.archived') }}</span>
          </Link>
        </li>
        <li v-if="teams.length === 0" class="px-2 py-2 text-small text-text-muted">{{ t('nav.no_teams') }}</li>
      </ul>
      <div class="mt-1 flex flex-col border-t pt-1">
        <Link
          v-if="hasMore"
          :href="pages.teams()"
          class="rounded-control px-2 py-2 text-small hover:bg-surface-hover"
          @click="close()"
          >{{ t('nav.all_teams') }}</Link
        >
        <Link
          :href="pages.newTeam()"
          class="flex items-center gap-2 rounded-control px-2 py-2 text-small hover:bg-surface-hover"
          @click="close()"
        >
          <Plus class="size-4" aria-hidden="true" />{{ t('team.create') }}
        </Link>
      </div>
    </div>
  </div>
</template>
