<script setup lang="ts">
import { Menu, X } from '@lucide/vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue';
import AppBrand from '@/Components/AppBrand.vue';
import SkipLink from '@/Components/SkipLink.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import LocaleSwitcher from '@/Components/ui/LocaleSwitcher.vue';
import ThemeToggle from '@/Components/ui/ThemeToggle.vue';
import { useMediaQuery } from '@/Composables/useMediaQuery';
import type { ThemePreference } from '@/Composables/useTheme';
import { useI18n } from '@/i18n';
import { api } from '@/lib/api';

/**
 * Authenticated application shell (DESIGN_SYSTEM.md §3):
 * - desktop (>= 1024px): 248px sidebar;
 * - tablet (768–1023px): 64px icon rail — the `navigation` slot receives `collapsed: true`;
 * - mobile (< 768px): the sidebar becomes a modal drawer opened from the topbar menu button,
 *   and the language/theme controls move into it.
 *
 * Slots: default (page content), `navigation` ({ collapsed }), `header` (topbar actions such as search
 * and Create), `user-menu`.
 */
withDefaults(
  defineProps<{
    /** Sets the document title. */
    title?: string;
  }>(),
  { title: undefined },
);

const { t } = useI18n();
const page = usePage();

/** Saves a signed-in user's theme to their profile (PATCH /me); the change is already applied locally. */
function saveThemePreference(theme: ThemePreference): void {
  if (page.props.auth.user === null) {
    return;
  }

  api.patch('/me', { theme }).catch(() => {
    // Offline or signed out: the theme still applies on this device and is retried on the next change.
  });
}

const isTabletUp = useMediaQuery('(min-width: 48rem)');
const isDesktop = useMediaQuery('(min-width: 64rem)');

const drawer = useTemplateRef<HTMLDialogElement>('drawer');
const menuButton = useTemplateRef<InstanceType<typeof AppButton>>('menuButton');
const drawerOpen = ref(false);

async function openDrawer(): Promise<void> {
  drawerOpen.value = true;
  await nextTick();
  drawer.value?.showModal();
}

function closeDrawer(): void {
  drawer.value?.close();
}

// Native <dialog> gives the focus trap, inert background and Escape handling; restore focus explicitly.
function onDrawerClose(): void {
  drawerOpen.value = false;
  (menuButton.value?.$el as HTMLElement | undefined)?.focus();
}

function onDrawerClick(event: MouseEvent): void {
  // A click on the backdrop targets the <dialog> element itself.
  if (event.target === drawer.value) {
    closeDrawer();
  }
}

watch(isTabletUp, (wide) => {
  if (wide) {
    closeDrawer();
  }
});

let stopListening: (() => void) | undefined;

onMounted(() => {
  stopListening = router.on('navigate', closeDrawer);
});

onBeforeUnmount(() => stopListening?.());
</script>

<template>
  <Head v-if="title" :title="title" />
  <div class="min-h-dvh bg-bg text-text">
    <SkipLink />

    <header class="sticky top-0 z-30 flex h-topbar items-center gap-2 border-b bg-surface px-2 sm:px-4">
      <AppButton
        ref="menuButton"
        class="md:hidden"
        variant="ghost"
        icon-only
        :aria-label="t('layout.open_navigation')"
        aria-haspopup="dialog"
        aria-controls="app-navigation-drawer"
        :aria-expanded="drawerOpen ? 'true' : 'false'"
        @click="openDrawer"
      >
        <Menu class="size-5" aria-hidden="true" />
      </AppButton>
      <AppBrand />
      <div class="ms-auto flex min-w-0 items-center gap-2">
        <slot name="header" />
        <div class="hidden items-center gap-2 md:flex">
          <LocaleSwitcher />
          <ThemeToggle @change="saveThemePreference" />
        </div>
        <slot name="user-menu" />
      </div>
    </header>

    <div class="flex">
      <aside
        class="sticky top-topbar hidden h-[calc(100dvh-var(--spacing-topbar))] w-rail shrink-0 overflow-y-auto border-e bg-surface md:block lg:w-sidebar"
      >
        <nav :aria-label="t('layout.main_navigation')" class="p-2">
          <slot name="navigation" :collapsed="!isDesktop" />
        </nav>
      </aside>

      <main id="main-content" tabindex="-1" class="min-w-0 flex-1 focus:outline-none">
        <div class="mx-auto w-full max-w-reading px-4 py-6 md:px-6 lg:px-8">
          <slot />
        </div>
      </main>
    </div>

    <dialog
      id="app-navigation-drawer"
      ref="drawer"
      :aria-label="t('layout.main_navigation')"
      class="fixed inset-y-0 start-0 end-auto m-0 h-dvh max-h-none w-[min(20rem,85vw)] max-w-none border-0 bg-surface p-0 text-text shadow-panel transition-[translate] duration-(--motion-panel) ease-(--motion-ease) backdrop:bg-overlay starting:open:-translate-x-full rtl:starting:open:translate-x-full"
      @close="onDrawerClose"
      @click="onDrawerClick"
    >
      <div v-if="drawerOpen" class="flex h-full flex-col">
        <div class="flex h-topbar shrink-0 items-center justify-between gap-2 border-b px-2 sm:px-4">
          <AppBrand />
          <AppButton variant="ghost" icon-only :aria-label="t('layout.close_navigation')" @click="closeDrawer">
            <X class="size-5" aria-hidden="true" />
          </AppButton>
        </div>
        <nav :aria-label="t('layout.main_navigation')" class="flex-1 overflow-y-auto p-2">
          <slot name="navigation" :collapsed="false" />
        </nav>
        <div class="flex shrink-0 flex-col gap-3 border-t p-4">
          <p class="text-caption font-medium text-text-muted">{{ t('layout.preferences') }}</p>
          <div class="flex flex-wrap items-center gap-2">
            <LocaleSwitcher />
            <ThemeToggle @change="saveThemePreference" />
          </div>
        </div>
      </div>
    </dialog>
  </div>
</template>
