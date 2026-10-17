<script setup lang="ts">
/** Concise empty state with at most one relevant action (DESIGN_SYSTEM.md §4 EmptyState, §9.2). */
defineProps<{ title: string; description?: string }>();
</script>

<template>
  <div class="flex flex-col items-start gap-2 rounded-card border border-dashed bg-surface p-6">
    <p class="font-semibold">{{ title }}</p>
    <p v-if="description" class="text-small text-text-muted">{{ description }}</p>
    <div v-if="$slots.default" class="mt-2"><slot /></div>
  </div>
</template>
