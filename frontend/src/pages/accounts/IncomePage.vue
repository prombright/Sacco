<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { BanknotesIcon, BuildingLibraryIcon, CalendarDaysIcon, ChartBarIcon, IdentificationIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { formatMoney } from '@/money'
import { useTheme } from '@/stores/theme'
import type { Report, ReportRow } from '@/reports'
import PageHeader from '@/components/PageHeader.vue'
import ReportView from '@/components/ReportView.vue'
import MonthChart from '@/components/MonthChart.vue'

interface Source {
  key: string
  label: string
  about: string
  total: string
  old_records: string
  count: number | null
  months: { month: string; value: string }[]
}
interface Summary { year: number | null; years: number[]; total: string; sources: Source[] }

/**
 * The SACCO's own income, one card per source: membership fees, annual subscriptions, loan
 * processing fees, loan interest and investments. A card opens what is behind it: who paid,
 * when, on which loan or investment; month by month; Excel, PDF and print.
 */
const route = useRoute()
const router = useRouter()
const theme = useTheme()
const year = ref<string>(String(route.query.year ?? new Date().getFullYear()))
const open = ref<string>(String(route.query.source ?? 'processing'))
const summary = ref<Summary | null>(null)
const detail = ref<Report[] | null>(null)
const error = ref('')
const busy = ref(false)

async function load() {
  error.value = ''
  try {
    summary.value = await api.get<Summary>('/reports/income', { year: year.value })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the income.'
  }
}
async function loadDetail() {
  busy.value = true
  detail.value = null
  try {
    detail.value = (await api.get<{ reports: Report[] }>(`/reports/income/${open.value}`, { year: year.value })).reports
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the details.'
  } finally {
    busy.value = false
  }
}
onMounted(() => { load(); loadDetail() })
watch(year, () => { load(); loadDetail(); router.replace({ query: { ...route.query, year: year.value } }) })
watch(open, () => { loadDetail(); router.replace({ query: { ...route.query, source: open.value } }) })

const icons: Record<string, unknown> = { membership: IdentificationIcon, subscription: CalendarDaysIcon, processing: BanknotesIcon, interest: ChartBarIcon, investment: BuildingLibraryIcon }
const selected = computed(() => summary.value?.sources.find((s) => s.key === open.value) ?? null)
const when = computed(() => (year.value === 'all' ? 'all years' : year.value))
const money = (v: number) => formatMoney(String(v))
// Each month of the chosen year (empty months too), or every month with income across all years
const points = computed(() => {
  const s = selected.value
  if (!s) return []
  if (year.value === 'all') return s.months.map((m) => ({ month: m.month, value: m.value }))
  const byMonth = new Map(s.months.map((m) => [m.month, m.value]))
  const last = year.value === String(new Date().getFullYear()) ? new Date().getMonth() + 1 : 12
  return Array.from({ length: last }, (_, i) => {
    const m = `${year.value}-${String(i + 1).padStart(2, '0')}`
    return { month: m, value: byMonth.get(m) ?? '0' }
  })
})
const clickable = (r: ReportRow) => !!r._loan_id || !!r._member_id
function go(r: ReportRow) {
  if (r._loan_id) router.push({ name: 'loan', params: { id: r._loan_id } })
  else if (r._member_id) router.push({ name: 'member', params: { id: r._member_id } })
}
</script>

<template>
  <div>
    <PageHeader title="Income">What the SACCO earns, source by source. Open a card to see every amount behind it: who paid, when, and on which loan or investment.</PageHeader>

    <div class="mt-5 max-w-[12rem]">
      <label for="income-year" class="label">Year</label>
      <select id="income-year" v-model="year" class="input">
        <option v-for="y in summary?.years ?? [new Date().getFullYear()]" :key="y" :value="String(y)">{{ y }}</option>
        <option value="all">All years</option>
      </select>
    </div>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <p v-if="summary" class="mt-4 text-sm text-gray-600">
      Total income, {{ when }}: <span class="text-lg font-semibold tabular-nums text-ink">{{ formatMoney(summary.total) }}</span>
    </p>

    <div v-if="summary" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
      <button
        v-for="s in summary.sources"
        :key="s.key"
        type="button"
        class="card tap min-w-0 p-4 text-left transition hover:border-navy-200"
        :class="open === s.key ? '!border-brand ring-2 ring-brand/15' : ''"
        :aria-pressed="open === s.key"
        @click="open = s.key"
      >
        <span class="flex items-center gap-2 text-sm font-semibold text-gray-700">
          <component :is="icons[s.key]" class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />{{ s.label }}
        </span>
        <span class="mt-2 block text-xl font-semibold tabular-nums">{{ formatMoney(s.total) }}</span>
        <span class="mt-1 block text-xs text-gray-500">
          <template v-if="s.count !== null">{{ s.count }} {{ s.key === 'processing' ? 'loan' : s.key === 'investment' ? 'return' : 'payment' }}{{ s.count === 1 ? '' : 's' }} · </template>{{ s.about }}
        </span>
        <span v-if="Number(s.old_records) > 0" class="mt-1 block text-xs text-amber-800">{{ formatMoney(s.old_records) }} of it brought in from the old records</span>
      </button>
    </div>

    <section v-if="selected" class="card mt-5 p-5">
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="font-semibold">{{ selected.label }}, month by month</h2>
        <p class="text-sm text-gray-600">{{ when }} · <span class="font-semibold tabular-nums text-ink">{{ formatMoney(selected.total) }}</span></p>
      </div>
      <MonthChart :key="selected.key + year" class="mt-3" :points="points" kind="bar" :color="theme.chart.blue" :label="selected.label" :format="money" />
      <p v-if="Number(selected.old_records) > 0" class="mt-2 text-xs text-gray-500">
        Amounts from the old records are counted on the day they were brought into this system, not when they were first paid.
      </p>
      <p v-if="selected.key === 'interest'" class="mt-2 text-xs text-gray-500 phone-hide">Interest is counted as the books earn it: each month's interest on what members owe.</p>
    </section>

    <p v-if="busy" class="mt-5 text-sm text-gray-500">Loading the details…</p>
    <div v-if="detail" class="mt-5 space-y-5">
      <ReportView v-for="r in detail" :key="r.title" :report="r" :clickable="clickable" @row-click="go">
        <template #empty>Nothing in {{ when }}.</template>
      </ReportView>
    </div>
  </div>
</template>
