<script setup lang="ts">
import { computed } from 'vue'
import type { Sacco } from '@/types'

/** The SACCO's logo, or its initials on its colour when it has none */
const props = defineProps<{ sacco: Sacco | null; size?: 'sm' | 'lg' }>()
const base = import.meta.env.BASE_URL

const initials = computed(() => {
  const name = props.sacco?.short_name || props.sacco?.name || 'SACCO'
  return name.replace(/sacco/i, '').trim().slice(0, 2).toUpperCase() || 'S'
})
</script>

<template>
  <img
    v-if="sacco?.logo_url"
    :src="/^(https?:)?\/\//.test(sacco.logo_url) || sacco.logo_url.startsWith('/') ? sacco.logo_url : base + sacco.logo_url"
    :alt="sacco.name"
    :class="size === 'lg' ? 'h-14 w-14' : 'h-9 w-9'"
    class="rounded-lg object-contain"
  />
  <span
    v-else
    :class="size === 'lg' ? 'h-14 w-14 text-lg' : 'h-9 w-9 text-sm'"
    class="inline-flex shrink-0 items-center justify-center rounded-lg bg-brand font-semibold text-white"
    aria-hidden="true"
  >{{ initials }}</span>
</template>
