<script setup lang="ts">
import { FolderKanban, House, LayoutGrid, Lock, Settings, Users } from '@lucide/vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, watch, type Component } from 'vue';
import TeamSwitcher from '@/Components/Workspace/TeamSwitcher.vue';
import { useNavProjects } from '@/Composables/useNavProjects';
import { useI18n } from '@/i18n';
import { pages } from '@/lib/routes';
import type { Team, TeamAbilities } from '@/types/domain';

/**
 * Sidebar content (DESIGN_SYSTEM.md §3.1): workspace switcher, Home, Teams, and the current team's pages and
 * projects. In the tablet rail (`collapsed`) links show icons with accessible names. Settings appears only
 * when the server says the user may open it (`teamCan.view_settings`).
 */
const props = withDefaults(
  defineProps<{ team: Team | null; teamCan: TeamAbilities | null; projectId?: string | null; collapsed?: boolean }>(),
  { projectId: null, collapsed: false },
);

const { t } = useI18n();
const page = usePage();
const nav = useNavProjects();

watch(
  () => props.team?.id,
  (id) => {
    if (id) {
      void nav.load(id);
    }
  },
  { immediate: true },
);

const projects = computed(() => (props.team ? nav.projectsOf(props.team.id) : []));

interface Item {
  href: string;
  label: string;
  icon: Component;
  exact?: boolean;
}

const general = computed<Item[]>(() => [
  { href: pages.home(), label: t('nav.home'), icon: House, exact: true },
  { href: pages.teams(), label: t('nav.teams'), icon: LayoutGrid, exact: true },
]);

const teamItems = computed<Item[]>(() => {
  if (!props.team) {
    return [];
  }

  const slug = props.team.slug;
  const items: Item[] = [
    { href: pages.team(slug), label: t('nav.overview'), icon: LayoutGrid, exact: true },
    { href: pages.projects(slug), label: t('nav.projects'), icon: FolderKanban },
    { href: pages.teamMembers(slug), label: t('nav.members'), icon: Users },
  ];

  if (props.teamCan?.view_settings) {
    items.push({ href: pages.teamSettings(slug), label: t('nav.settings'), icon: Settings });
  }

  return items;
});

function path(): string {
  return page.url.split('?')[0] ?? page.url;
}

function isActive(item: Item): boolean {
  return item.exact ? path() === item.href : path() === item.href || path().startsWith(`${item.href}/`);
}

const linkClass =
  'flex min-h-11 items-center gap-3 rounded-control px-2 text-body text-text hover:bg-surface-hover aria-[current=page]:bg-surface-hover aria-[current=page]:font-semibold lg:min-h-9 pointer-coarse:min-h-11';
</script>

<template>
  <div class="flex flex-col gap-4">
    <TeamSwitcher :current="team" :collapsed="collapsed" />

    <ul class="flex flex-col gap-0.5">
      <li v-for="item in general" :key="item.href">
        <Link
          :href="item.href"
          :class="[linkClass, collapsed && 'justify-center']"
          :aria-current="isActive(item) ? 'page' : undefined"
          :aria-label="collapsed ? item.label : undefined"
          :title="collapsed ? item.label : undefined"
        >
          <component :is="item.icon" class="size-4 shrink-0 text-text-muted" aria-hidden="true" />
          <span v-if="!collapsed">{{ item.label }}</span>
        </Link>
      </li>
    </ul>

    <section v-if="team" :aria-label="team.name" class="flex flex-col gap-1">
      <h2 v-if="!collapsed" class="truncate px-2 text-caption font-semibold text-text-muted">
        {{ team.name }}
      </h2>
      <ul class="flex flex-col gap-0.5">
        <li v-for="item in teamItems" :key="item.href">
          <Link
            :href="item.href"
            :class="[linkClass, collapsed && 'justify-center']"
            :aria-current="isActive(item) ? 'page' : undefined"
            :aria-label="collapsed ? item.label : undefined"
            :title="collapsed ? item.label : undefined"
          >
            <component :is="item.icon" class="size-4 shrink-0 text-text-muted" aria-hidden="true" />
            <span v-if="!collapsed">{{ item.label }}</span>
          </Link>
        </li>
      </ul>

      <template v-if="!collapsed && projects.length > 0">
        <h3 class="mt-2 px-2 text-caption text-text-muted">{{ t('nav.projects') }}</h3>
        <ul class="flex flex-col gap-0.5">
          <li v-for="project in projects" :key="project.id">
            <Link
              :href="pages.project(team.slug, project.key)"
              :class="linkClass"
              :aria-current="project.id === projectId ? 'page' : undefined"
            >
              <span class="min-w-0 flex-1 truncate">{{ project.name }}</span>
              <Lock
                v-if="project.visibility === 'private'"
                class="size-3.5 shrink-0 text-text-muted"
                :aria-label="t('project.visibility.private')"
              />
            </Link>
          </li>
        </ul>
      </template>
    </section>
  </div>
</template>
