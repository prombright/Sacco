<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowRightIcon } from '@heroicons/vue/24/outline'
import { api } from '@/api'
import { useAuth } from '@/stores/auth'
import { useOffice } from '@/stores/office'
import { sections, type SectionStats } from '@/sections'

/** A section's own dashboard: its modules as cards, each with a live figure and what's waiting */
const route = useRoute()
const auth = useAuth()
const office = useOffice()
const section = computed(() => sections.find((s) => s.hub === route.name)!)
const stats = ref<SectionStats | null>(null)

onMounted(async () => {
  office.refresh(true)
  try {
    stats.value = await api.get<SectionStats>('/office/sections')
  } catch {
    // The cards work without their figures
  }
})

const items = computed(() => section.value.items.filter((i) => !i.superOnly || auth.isSuperAdmin))
const waitingHere = computed(() => (office.waiting ? items.value.reduce((n, i) => n + (i.badge?.(office.waiting!) ?? 0), 0) : 0))
</script>

<template>
  <div>
    <!-- Heading -->
    <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-navy-800 to-navy-950 p-6 text-white shadow-lift sm:p-8">
      <div class="absolute -right-16 -top-20 h-56 w-56 rounded-full border-2 border-saffron-400/60" aria-hidden="true" />
      <div class="absolute -bottom-24 right-32 h-44 w-44 rounded-full bg-white/5" aria-hidden="true" />
      <div class="relative flex items-center gap-4">
        <span class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br shadow-lg" :class="section.tone">
          <component :is="section.icon" class="h-7 w-7 text-white" aria-hidden="true" />
        </span>
        <div>
          <h1 class="text-2xl font-bold sm:text-3xl">{{ section.label }}</h1>
          <p class="mt-1 max-w-2xl text-navy-100/80">{{ section.intro }}</p>
        </div>
      </div>
      <p v-if="waitingHere" class="relative mt-5 inline-flex items-center gap-2 rounded-full bg-saffron-400 px-3 py-1 text-sm font-semibold text-navy-950">
        {{ waitingHere }} thing{{ waitingHere === 1 ? '' : 's' }} waiting for you here
      </p>
    </div>

    <!-- Modules -->
    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <RouterLink
        v-for="item in items"
        :key="item.name"
        :to="{ name: item.name }"
        class="card group relative flex flex-col overflow-hidden p-5 transition duration-200 hover:-translate-y-1 hover:shadow-lift"
      >
        <span class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r opacity-0 transition group-hover:opacity-100" :class="section.tone" aria-hidden="true" />
        <div class="flex items-start justify-between gap-3">
          <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br text-white shadow-sm transition group-hover:scale-105" :class="section.tone">
            <component :is="item.icon" class="h-6 w-6" aria-hidden="true" />
          </span>
          <span
            v-if="office.waiting && item.badge?.(office.waiting)"
            class="rounded-full bg-saffron-400 px-2.5 py-1 text-xs font-bold text-navy-950"
          >{{ item.badge(office.waiting) }} waiting</span>
        </div>
        <h2 class="mt-4 text-lg font-semibold group-hover:text-brand">{{ item.label }}</h2>
        <p class="mt-1 flex-1 text-sm text-gray-600">{{ item.description }}</p>
        <div class="mt-4 flex items-center justify-between border-t border-line pt-3 text-sm">
          <span class="font-medium tabular-nums text-gray-700">
            <template v-if="item.figure && stats">{{ item.figure(stats) }}</template>
            <span v-else-if="item.figure" class="inline-block h-4 w-24 animate-pulse rounded bg-gray-100" aria-hidden="true" />
            <template v-else>Open</template>
          </span>
          <ArrowRightIcon class="h-4 w-4 text-gray-400 transition group-hover:translate-x-1 group-hover:text-brand" aria-hidden="true" />
        </div>
      </RouterLink>
    </div>
  </div>
</template>
