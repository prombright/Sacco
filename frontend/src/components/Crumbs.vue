<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, type RouteLocationRaw } from 'vue-router'
import { ChevronRightIcon } from '@heroicons/vue/20/solid'
import { useAuth } from '@/stores/auth'
import { sectionOf } from '@/sections'

/**
 * The trail back up from a page: Dashboard › section › the page it belongs under › `trail`.
 * Office pages take it from the menu (src/sections.ts); members' pages show `eyebrow` instead.
 */
const props = defineProps<{ eyebrow?: string; trail?: { label: string; to?: RouteLocationRaw }[] }>()
const route = useRoute()
const auth = useAuth()

const crumbs = computed(() => {
  const name = String(route.name)
  const section = auth.isAdmin ? sectionOf(name) : undefined
  const out: { label: string; to?: RouteLocationRaw }[] = []
  if (section) {
    out.push({ label: 'Dashboard', to: { name: 'overview' } })
    if (section.hub !== name) out.push({ label: section.label, to: { name: section.hub } })
    // A page that belongs under another (a payroll month under Payroll deductions)
    const parent = section.items.find((i) => i.name !== name && i.also?.includes(name))
    if (parent) out.push({ label: parent.label, to: { name: parent.name } })
  } else if (props.eyebrow) {
    out.push({ label: props.eyebrow })
  }
  return [...out, ...(props.trail ?? [])]
})
</script>

<template>
  <nav v-if="crumbs.length" aria-label="You are here">
    <ol class="flex flex-wrap items-center gap-x-1 text-xs font-semibold uppercase tracking-wider text-gray-500">
      <li v-for="(c, i) in crumbs" :key="i" class="flex min-w-0 items-center gap-1">
        <ChevronRightIcon v-if="i" class="h-3.5 w-3.5 shrink-0 text-gray-300" aria-hidden="true" />
        <RouterLink v-if="c.to" :to="c.to" class="truncate rounded transition hover:text-brand">{{ c.label }}</RouterLink>
        <span v-else class="truncate" :aria-current="i === crumbs.length - 1 ? 'page' : undefined">{{ c.label }}</span>
      </li>
    </ol>
  </nav>
</template>
