<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { MegaphoneIcon } from '@heroicons/vue/24/outline'
import { api } from '@/api'
import { formatDate } from '@/money'

/** The office's current notices, on the member dashboard */
const notices = ref<{ id: number; title: string; body: string; starts_on: string }[]>([])
onMounted(async () => {
  try {
    notices.value = (await api.get<{ notices: typeof notices.value }>('/notices/current')).notices
  } catch {
    // Notices are extra; the dashboard works without them
  }
})
</script>

<template>
  <section v-if="notices.length" class="space-y-3" aria-label="Notices from the SACCO office">
    <article v-for="n in notices" :key="n.id" class="flex gap-4 rounded-2xl border border-saffron-300 bg-saffron-50 p-4">
      <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-saffron-400 text-navy-950"><MegaphoneIcon class="h-5 w-5" aria-hidden="true" /></span>
      <div class="min-w-0">
        <h2 class="font-semibold">{{ n.title }}</h2>
        <p class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ n.body }}</p>
        <p class="mt-1 text-xs text-gray-500">From the SACCO office · {{ formatDate(n.starts_on) }}</p>
      </div>
    </article>
  </section>
</template>
