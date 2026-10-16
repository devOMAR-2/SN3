<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppBrand from '@/Components/AppBrand.vue';
import SkipLink from '@/Components/SkipLink.vue';
import LocaleSwitcher from '@/Components/ui/LocaleSwitcher.vue';
import ThemeToggle from '@/Components/ui/ThemeToggle.vue';

/**
 * Minimal public shell for sign-in/up, verification and reset pages (DESIGN_SYSTEM.md §3.4):
 * brand, language and theme controls, and a centred card. No workspace navigation.
 */
withDefaults(
  defineProps<{
    /** Sets the document title and the card heading. */
    title?: string;
  }>(),
  { title: undefined },
);
</script>

<template>
  <Head v-if="title" :title="title" />
  <div class="flex min-h-dvh flex-col bg-bg text-text">
    <SkipLink />
    <header class="flex h-topbar items-center justify-between gap-3 px-4 sm:px-6">
      <AppBrand />
      <LocaleSwitcher />
    </header>

    <main
      id="main-content"
      tabindex="-1"
      class="flex flex-1 items-start justify-center px-4 py-6 focus:outline-none sm:items-center sm:py-10"
    >
      <div class="w-full max-w-md rounded-card border bg-surface p-4 shadow-panel sm:p-6">
        <h1 v-if="title" class="mb-4 text-title font-semibold">{{ title }}</h1>
        <slot />
      </div>
    </main>

    <footer class="flex items-center justify-center px-4 pb-6">
      <ThemeToggle />
    </footer>
  </div>
</template>
