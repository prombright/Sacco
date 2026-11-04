<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api'
import type { Report } from '@/reports'
import ReportView from '@/components/ReportView.vue'

interface ReportInfo {
  key: string
  title: string
  description: string
  needs_dates: boolean
}

const route = useRoute()
const list = ref<ReportInfo[]>([])
const selected = ref<ReportInfo | null>(null)
const today = new Date()
const from = ref(new Date(today.getFullYear(), today.getMonth(), 1).toLocaleDateString('en-CA'))
const to = ref(today.toLocaleDateString('en-CA'))
const report = ref<Report | null>(null)
const error = ref('')
const busy = ref(false)

onMounted(async () => {
  list.value = (await api.get<{ reports: ReportInfo[] }>('/reports')).reports
  pickFromRoute()
})
// Members summary and Loan portfolio open straight on their report
function pickFromRoute() {
  const fixed = list.value.find((r) => r.key === route.meta.report)
  if (fixed) open(fixed)
  else selected.value = null
}
watch(() => route.meta.report, pickFromRoute)

async function open(r: ReportInfo) {
  selected.value = r
  await run()
}

async function run() {
  if (!selected.value) return
  error.value = ''
  busy.value = true
  report.value = null
  try {
    report.value = await api.get<Report>(`/reports/${selected.value.key}`, selected.value.needs_dates ? { from: from.value, to: to.value } : undefined)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t run the report.'
  } finally {
    busy.value = false
  }
}

</script>

<template>
  <div>
    <template v-if="route.meta.report">
      <p class="eyebrow">Member reports</p>
      <h1 class="mt-1 text-2xl font-bold">{{ route.meta.title }}</h1>
      <p class="mt-1 text-gray-600">{{ selected?.description }}</p>
    </template>
    <template v-else>
      <p class="eyebrow">Administration</p>
      <h1 class="mt-1 text-2xl font-bold">Other reports</h1>
      <p class="mt-1 text-gray-600">For the office, the committee and the auditors. Each one downloads as Excel and prints or saves as PDF.</p>
      <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <button
          v-for="r in list"
          :key="r.key"
          type="button"
          class="card p-4 text-left transition hover:border-gray-300"
          :class="{ '!border-brand ring-2 ring-brand/20': selected?.key === r.key }"
          @click="open(r)"
        >
          <p class="font-medium">{{ r.title }}</p>
          <p class="mt-1 text-sm text-gray-600">{{ r.description }}</p>
        </button>
      </div>
    </template>

    <ReportView v-if="selected" class="mt-6" :report="report" :busy="busy" :error="error">
      <template v-if="selected.needs_dates" #controls>
        <div><label for="from" class="label">From</label><input id="from" v-model="from" type="date" class="input" @change="run" /></div>
        <div><label for="to" class="label">To</label><input id="to" v-model="to" type="date" class="input" @change="run" /></div>
      </template>
    </ReportView>
  </div>
</template>
