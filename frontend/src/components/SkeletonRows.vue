<script setup lang="ts">
/** Grey shimmering rows in the shape of what is loading, so the page doesn't jump or look empty */
withDefaults(defineProps<{ rows?: number; avatar?: boolean; amount?: boolean }>(), { rows: 5, avatar: false, amount: true })
</script>

<template>
  <ul class="divide-y divide-line" aria-busy="true" aria-label="Loading">
    <li v-for="n in rows" :key="n" class="flex items-center gap-3 px-4 py-3.5">
      <span v-if="avatar" class="h-9 w-9 shrink-0 animate-pulse rounded-full bg-gray-100" />
      <span class="min-w-0 flex-1 space-y-2">
        <span class="block h-3.5 animate-pulse rounded bg-gray-100" :style="{ width: 35 + ((n * 23) % 40) + '%' }" />
        <span class="block h-3 animate-pulse rounded bg-gray-100/70" :style="{ width: 20 + ((n * 17) % 25) + '%' }" />
      </span>
      <span v-if="amount" class="h-4 w-20 shrink-0 animate-pulse rounded bg-gray-100" />
    </li>
  </ul>
</template>
