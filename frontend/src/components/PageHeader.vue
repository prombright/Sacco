<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'
import Crumbs from '@/components/Crumbs.vue'
import PageBanner from '@/components/PageBanner.vue'

/**
 * The top of every page, the same everywhere (inside the light banner of the dashboard): a trail back up (Dashboard › section › page it
 * belongs under), the title, a line saying what the page is for, and the page's main buttons.
 *
 *   <PageHeader title="Deposit">Money a member pays in…<template #actions>…</template></PageHeader>
 *
 * Office pages take their trail from the menu (src/sections.ts); members' pages show `eyebrow`.
 * `trail` adds steps after the section (e.g. a member's name above their loan).
 */
defineProps<{ title?: string; eyebrow?: string; keepNote?: boolean; trail?: { label: string; to?: RouteLocationRaw }[] }>()
</script>

<template>
  <PageBanner :keep-note="keepNote">
    <template #above><Crumbs class="phone-hide" :eyebrow="eyebrow" :trail="trail" /></template>
    <template #title><slot name="title">{{ title }}</slot></template>
    <template v-if="$slots.default" #default><slot /></template>
    <template v-if="$slots.actions" #actions><slot name="actions" /></template>
  </PageBanner>
</template>
