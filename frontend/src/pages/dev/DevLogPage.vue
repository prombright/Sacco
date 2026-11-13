<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/api'
import { ago, type PortalLogRow } from './dev'

/** Everything done in the developer portal, to any SACCO */
const rows = ref<PortalLogRow[] | null>(null)
onMounted(async () => {
  rows.value = (await api.get<{ log: PortalLogRow[] }>('/dev/log')).log
})
</script>

<template>
  <div>
    <p class="eyebrow">Developer portal</p>
    <h1 class="mt-1 text-2xl font-bold sm:text-3xl">Portal log</h1>
    <p class="mt-1 text-gray-600">Every login and change made here. Changes to a SACCO are also in that SACCO's own audit log, and its office accounts were told.</p>
    <ol class="card mt-6 divide-y divide-line">
      <li v-for="(r, i) in rows ?? []" :key="i" class="flex gap-3 p-4 text-sm">
        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="r.action.startsWith('developer.') ? 'bg-gray-300' : 'bg-saffron-500'" aria-hidden="true" />
        <div class="min-w-0 flex-1">
          <p><span class="font-semibold">{{ r.developer }}</span> <span class="text-gray-700">{{ r.text }}</span><span v-if="r.sacco" class="text-gray-500">&#32;· {{ r.sacco }}</span></p>
          <p v-if="r.details" class="mt-1 whitespace-pre-line text-xs text-gray-500">{{ r.details }}</p>
        </div>
        <span class="shrink-0 text-right text-xs text-gray-500">{{ ago(r.at) }}<span v-if="r.ip" class="block">{{ r.ip }}</span></span>
      </li>
      <li v-if="rows && !rows.length" class="p-6 text-center text-sm text-gray-500">Nothing yet.</li>
    </ol>
  </div>
</template>
