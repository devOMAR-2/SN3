<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

/** Inline navigation link in body copy (Inertia visit, keyboard-focusable, visible on focus). */
defineProps<{ href: string }>();
</script>

<template>
  <Link
    :href="href"
    class="rounded-tiny font-medium text-primary underline-offset-4 hover:text-primary-hover hover:underline"
  >
    <slot />
  </Link>
</template>
