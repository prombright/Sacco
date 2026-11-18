<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'
import Crumbs from '@/components/Crumbs.vue'

/**
 * The top of every page, the same everywhere: a trail back up (Dashboard › section › page it
 * belongs under), the title, a line saying what the page is for, and the page's main buttons.
 *
 *   <PageHeader title="Deposit">Money a member pays in…<template #actions>…</template></PageHeader>
 *
 * Office pages take their trail from the menu (src/sections.ts); members' pages show `eyebrow`.
 * `trail` adds steps after the section (e.g. a member's name above their loan).
 */
defineProps<{ title?: string; eyebrow?: string; trail?: { label: string; to?: RouteLocationRaw }[] }>()
</script>

<template>
  <header class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
    <div class="min-w-0 max-w-3xl">
      <Crumbs :eyebrow="eyebrow" :trail="trail" />
      <h1 class="mt-1 text-2xl font-bold tracking-tight text-ink sm:text-[1.85rem] sm:leading-tight"><slot name="title">{{ title }}</slot></h1>
      <p v-if="$slots.default" class="mt-1.5 text-[15px] leading-relaxed text-gray-600"><slot /></p>
    </div>
    <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2 max-sm:w-full">
      <slot name="actions" />
    </div>
  </header>
</template>
