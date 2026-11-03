<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { formatDate, formatMoney } from '@/money'
import { downloadReport, type Report, type ReportColumn } from '@/reports'

interface ReportInfo {
  key: string
  title: string
  description: string
  needs_dates: boolean
}

const auth = useAuth()
const list = ref<ReportInfo[]>([])
const selected = ref<ReportInfo | null>(null)
const today = new Date()
const from = ref(new Date(today.getFullYear(), today.getMonth(), 1).toLocaleDateString('en-CA'))
const to = ref(today.toLocaleDateString('en-CA'))
const report = ref<Report | null>(null)
const error = ref('')
const busy = ref(false)

// The screen shows the first rows; the Excel file always has all of them
const SHOWN = 200
const shownRows = computed(() => report.value?.rows.slice(0, SHOWN) ?? [])

onMounted(async () => {
  list.value = (await api.get<{ reports: ReportInfo[] }>('/reports')).reports
})

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

function show(c: ReportColumn, v: string | number | null) {
  if (v === null || v === '') return ''
  if (c.type === 'money') return formatMoney(String(v))
  if (c.type === 'date') return formatDate(String(v))
  return String(v)
}
</script>

<template>
  <div>
    <h1 class="text-xl font-semibold">Reports</h1>
    <p class="mt-1 text-sm text-gray-600">For the office, the committee and the auditors. Each one downloads as an Excel file.</p>

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

    <section v-if="selected" class="card mt-6 p-5">
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 class="font-semibold">{{ selected.title }}</h2>
          <p v-if="report" class="text-sm text-gray-600">{{ report.subtitle }} · {{ report.rows.length }} row{{ report.rows.length === 1 ? '' : 's' }}</p>
        </div>
        <div class="flex flex-wrap items-end gap-2">
          <template v-if="selected.needs_dates">
            <div><label for="from" class="label">From</label><input id="from" v-model="from" type="date" class="input" /></div>
            <div><label for="to" class="label">To</label><input id="to" v-model="to" type="date" class="input" /></div>
            <button type="button" class="btn-secondary" :disabled="busy" @click="run">Show</button>
          </template>
          <button type="button" class="btn-primary" :disabled="!report || !report.rows.length" @click="report && downloadReport(report, auth.sacco?.name ?? 'SACCO')">Download Excel</button>
        </div>
      </div>

      <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
      <p v-if="busy" class="mt-6 text-sm text-gray-500">Working it out…</p>

      <div v-if="report" class="mt-4 max-h-[32rem] overflow-auto rounded-lg border border-gray-200">
        <table class="w-full text-sm">
          <thead class="sticky top-0 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
            <tr>
              <th v-for="c in report.columns" :key="c.key" class="whitespace-nowrap px-3 py-2" :class="{ 'text-right': c.type === 'money' || c.type === 'number' }">{{ c.label }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="(row, i) in shownRows" :key="i">
              <td
                v-for="c in report.columns"
                :key="c.key"
                class="px-3 py-1.5"
                :class="{ 'whitespace-nowrap text-right tabular-nums': c.type === 'money' || c.type === 'number', 'whitespace-nowrap': c.type === 'date', 'font-mono': c.key === 'account_no' || c.key === 'loan_no' }"
              >{{ show(c, row[c.key]) }}</td>
            </tr>
            <tr v-if="!report.rows.length"><td :colspan="report.columns.length" class="px-3 py-8 text-center text-gray-500">Nothing to show.</td></tr>
          </tbody>
          <tfoot v-if="report.totals" class="sticky bottom-0 border-t border-gray-300 bg-gray-50 font-semibold">
            <tr>
              <td v-for="(c, i) in report.columns" :key="c.key" class="whitespace-nowrap px-3 py-2" :class="{ 'text-right tabular-nums': c.type === 'money' }">
                {{ i === 0 ? 'Total' : c.key in report.totals ? formatMoney(report.totals[c.key]) : '' }}
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
      <p v-if="report && report.rows.length > SHOWN" class="mt-2 text-xs text-gray-500">Showing the first {{ SHOWN }} rows. The Excel file has all {{ report.rows.length }}.</p>
    </section>
  </div>
</template>
