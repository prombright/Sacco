<script setup lang="ts">
import PageBanner from '@/components/PageBanner.vue'
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowRightIcon, EyeIcon } from '@heroicons/vue/24/outline'
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

const items = computed(() => section.value.items.filter((i) => (!i.superOnly || auth.isSuperAdmin) && (!i.needs || auth.can(i.needs))))
const waitingHere = computed(() => (office.waiting ? items.value.reduce((n, i) => n + (i.badge?.(office.waiting!) ?? 0), 0) : 0))
</script>

<template>
  <div>
    <!-- Heading: the light banner, with the section's own coloured tile -->
    <PageBanner :title="section.label">
      <template #icon>
        <span class="icon-gold inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl sm:h-14 sm:w-14">
          <component :is="section.icon" class="h-6 w-6 sm:h-7 sm:w-7" aria-hidden="true" />
        </span>
      </template>
      {{ section.intro }}
      <template v-if="waitingHere" #below>
        <p class="mt-3 inline-flex items-center gap-2 icon-gold rounded-full px-3 py-1 text-sm font-semibold">
          {{ waitingHere }} thing{{ waitingHere === 1 ? '' : 's' }} waiting for you here
        </p>
      </template>
    </PageBanner>

    <p v-if="auth.viewOnly" class="mt-4 flex items-center gap-2 rounded-xl border border-line bg-surface px-4 py-3 text-sm text-gray-700">
      <EyeIcon class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
      Your account can see everything here and download every report. Changes are made by the super admin, and you get a message about each major one.
    </p>

    <!-- Modules -->
    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <RouterLink
        v-for="(item, n) in items"
        :key="item.name"
        :to="{ name: item.name }"
        :style="{ '--i': n }"
        class="reveal card-link group relative flex flex-col overflow-hidden p-5"
      >
        <span class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-saffron-300 to-saffron-500 opacity-0 transition group-hover:opacity-100" aria-hidden="true" />
        <div class="flex items-start justify-between gap-3">
          <span class="icon-gold inline-flex h-12 w-12 items-center justify-center rounded-2xl transition group-hover:scale-105">
            <component :is="item.icon" class="h-6 w-6" aria-hidden="true" />
          </span>
          <span
            v-if="office.waiting && item.badge?.(office.waiting)"
            class="icon-gold rounded-full px-2.5 py-1 text-xs font-bold"
          >{{ item.badge(office.waiting) }} waiting</span>
        </div>
        <h2 class="mt-4 text-lg font-semibold group-hover:text-brand">{{ item.label }}</h2>
        <p class="mt-1 flex-1 text-sm text-gray-600">{{ item.description }}</p>
        <div class="mt-4 flex items-center justify-between border-t border-line pt-3 text-sm">
          <span class="tabular-nums" :class="item.figure ? 'font-semibold text-ink' : 'font-medium text-gray-600'">
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
