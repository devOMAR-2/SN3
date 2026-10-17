<script setup lang="ts">
import { Lock, Users } from '@lucide/vue';
import AppBadge from '@/Components/ui/AppBadge.vue';
import { useI18n } from '@/i18n';
import type { Visibility } from '@/types/domain';

/** Project visibility as text plus icon (never colour alone). */
defineProps<{ visibility: Visibility }>();
const { t } = useI18n();
</script>

<template>
  <AppBadge :variant="visibility === 'private' ? 'warning' : 'neutral'">
    <component :is="visibility === 'private' ? Lock : Users" class="size-3" aria-hidden="true" />
    {{ t(`project.visibility.${visibility}`) }}
  </AppBadge>
</template>
