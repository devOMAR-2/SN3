<script setup lang="ts">
import { Circle, CircleCheck, CircleDot } from '@lucide/vue';
import { computed } from 'vue';
import AppBadge from '@/Components/ui/AppBadge.vue';
import type { ProjectStatus } from '@/types/domain';

/** A status by its name, with a category icon and tone; the name carries the meaning (DS-ACC-015). */
const props = defineProps<{ status: ProjectStatus | undefined }>();

const icons = { todo: Circle, in_progress: CircleDot, done: CircleCheck };
const variants = { todo: 'neutral', in_progress: 'info', done: 'success' } as const;
const icon = computed(() => icons[props.status?.category ?? 'todo']);
const variant = computed(() => variants[props.status?.category ?? 'todo']);
</script>

<template>
  <AppBadge v-if="status" :variant="variant">
    <component :is="icon" class="size-3 shrink-0" aria-hidden="true" />
    <span class="truncate" dir="auto">{{ status.name }}</span>
  </AppBadge>
</template>
