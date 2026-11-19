<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { BanknotesIcon, ChartPieIcon, CheckCircleIcon, ClockIcon, DocumentArrowDownIcon, ExclamationTriangleIcon, PresentationChartLineIcon, TableCellsIcon, UsersIcon, WalletIcon } from '@heroicons/vue/24/outline'
import PageHeader from '@/components/PageHeader.vue'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { useTheme } from '@/stores/theme'
import type { Report } from '@/reports'
import { downloadPdfBook, downloadWorkbook, type PdfChart } from '@/exporters'
import ReportView from '@/components/ReportView.vue'
import MonthChart from '@/components/MonthChart.vue'
import CountUp from '@/components/CountUp.vue'

interface TrendPoint { month: string; members: number; savings: string; loan_book: string; income: string; expense: string; surplus: string; par30: number }
interface Pack {
  month: string
  label: string
  period: string
  last_payroll: string | null
  payroll_behind: boolean
  balanced: boolean
  highlights: { members: number; savings: string; shares: string; loan_book: string; par30: number; surplus: string; surplus_year: string }
  trend: TrendPoint[]
  reports: Report[]
}

/**
 * The board pack: one month in one PDF for the board meeting. Headline figures on the cover, twelve
 * months of trend, then the key figures against the month before (with lines to sign), the income
 * statement, the balance sheet, how late the loan book is, the loans issued and what is waiting.
 */
const auth = useAuth()
const theme = useTheme()
const lastMonth = (() => {
  const d = new Date()
  d.setDate(1)
  d.setMonth(d.getMonth() - 1)
  return d.toLocaleDateString('en-CA').slice(0, 7)
})()
const thisMonth = new Date().toLocaleDateString('en-CA').slice(0, 7)
const month = ref(lastMonth)
const data = ref<Pack | null>(null)
const busy = ref(false)
const error = ref('')
const making = ref<'' | 'pdf' | 'excel'>('')

async function load() {
  if (!month.value) return
  busy.value = true
  error.value = ''
  try {
    data.value = await api.get<Pack>('/accounting/board-pack', { month: month.value })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t put the board pack together.'
  } finally {
    busy.value = false
  }
}
onMounted(load)
watch(month, load)

const cur = computed(() => auth.sacco?.currency ?? 'UGX')
const short = (v: string | number) => {
  const n = Number(v)
  const a = Math.abs(n)
  const s = a >= 1e9 ? `${+(a / 1e9).toFixed(2)}B` : a >= 1e6 ? `${+(a / 1e6).toFixed(1)}M` : a >= 1e3 ? `${+(a / 1e3).toFixed(0)}K` : a.toLocaleString('en-US')
  return n < 0 ? `-${s}` : s
}
const percent = (v: number) => `${+v.toFixed(1)}%`
const cards = computed(() => {
  const h = data.value?.highlights
  if (!h) return []
  return [
    { icon: UsersIcon, label: 'Members', raw: h.members, value: String(h.members), format: (v: number) => String(Math.round(v)) },
    { icon: WalletIcon, label: `Savings held (${cur.value})`, raw: h.savings, value: short(h.savings), format: short },
    { icon: ChartPieIcon, label: `Share capital (${cur.value})`, raw: h.shares, value: short(h.shares), format: short },
    { icon: BanknotesIcon, label: `Owed on loans (${cur.value})`, raw: h.loan_book, value: short(h.loan_book), format: short },
    { icon: ClockIcon, label: 'Over 30 days late (PAR 30)', raw: h.par30, value: percent(h.par30), format: percent },
    { icon: PresentationChartLineIcon, label: `${Number(h.surplus) >= 0 ? 'Surplus' : 'Deficit'} this month (${cur.value})`, raw: h.surplus, value: short(h.surplus), format: short },
  ]
})

/** The same four series on the page and in the PDF */
const series = computed(() => {
  const t = data.value?.trend ?? []
  return [
    { title: 'Savings held', note: `${cur.value}, at each month end`, kind: 'area' as const, color: theme.chart.blue, points: t.map((p) => ({ month: p.month, value: Number(p.savings) })), format: short },
    { title: 'Owed on loans (principal)', note: `${cur.value}, at each month end`, kind: 'area' as const, color: theme.chart.blue, points: t.map((p) => ({ month: p.month, value: Number(p.loan_book) })), format: short },
    { title: 'Surplus each month', note: `${cur.value}, income less expenses`, kind: 'bar' as const, color: theme.chart.amber, points: t.map((p) => ({ month: p.month, value: Number(p.surplus) })), format: short },
    { title: 'Share of loans over 30 days late', note: 'PAR 30, at each month end', kind: 'bar' as const, color: theme.chart.amber, points: t.map((p) => ({ month: p.month, value: p.par30 })), format: percent },
  ]
})

async function download(kind: 'pdf' | 'excel') {
  if (!data.value) return
  making.value = kind
  const who = { sacco: auth.sacco, user: auth.user?.full_name ?? null }
  const title = `${auth.sacco?.short_name ?? 'SACCO'} board pack ${data.value.month}`
  try {
    if (kind === 'pdf') {
      const charts: PdfChart[] = series.value.map(({ title, note, points, format }) => ({ title, note, points, format }))
      await downloadPdfBook(data.value.reports, who, title, {
        title: `Board pack, ${data.value.label}`,
        subtitle: data.value.period,
        note: 'Prepared for the board meeting',
        highlights: cards.value.map((c) => [c.label, c.value]),
        charts,
      })
    } else {
      await downloadWorkbook(data.value.reports, who, title)
    }
  } finally {
    making.value = ''
  }
}
const monthName = (m: string) => new Date(m + '-01T00:00:00').toLocaleDateString('en-GB', { month: 'long', year: 'numeric' })
</script>

<template>
  <div>
    <PageHeader :title="`Board pack${data ? `, ${data.label}` : ''}`">
      The month in one document for the board meeting
      <template #actions>
        <label for="pack-month" class="sr-only">Month</label>
        <input id="pack-month" v-model="month" type="month" :max="thisMonth" class="input w-full sm:w-auto" />
        <button type="button" class="btn-primary flex-1 sm:flex-none" :disabled="!data || !!making" @click="download('pdf')"><DocumentArrowDownIcon class="h-4 w-4" :class="{ 'animate-pulse': making === 'pdf' }" />Board pack (PDF)</button>
        <button type="button" class="btn-secondary flex-1 sm:flex-none" :disabled="!data || !!making" @click="download('excel')"><TableCellsIcon class="h-4 w-4" :class="{ 'animate-pulse': making === 'excel' }" />Excel</button>
      </template>
    </PageHeader>

    <p v-if="data" class="mt-4 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold" :class="data.balanced ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300'">
      <component :is="data.balanced ? CheckCircleIcon : ExclamationTriangleIcon" class="h-4 w-4" />{{ data.balanced ? 'The books balance' : 'The books do not balance: check before the meeting' }}
    </p>

    <div v-if="data" class="mt-4 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 2xl:grid-cols-6">
      <div v-for="(c, n) in cards" :key="c.label" class="stat-card" :class="{ 'stat-card-lead': n === 0 }">
        <div class="stat-card-top">
          <span class="icon-gold stat-card-icon"><component :is="c.icon" class="h-5 w-5" aria-hidden="true" /></span>
          <p class="stat-card-label">{{ c.label }}</p>
        </div>
        <p class="stat-card-figure text-[#16133a] dark:text-white"><CountUp :value="c.raw" :format="c.format" :delay="n * 120" /></p>
      </div>
    </div>

    <div v-if="data?.payroll_behind" class="mt-4 flex gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200" role="status">
      <ExclamationTriangleIcon class="h-5 w-5 shrink-0" />
      <p>The {{ monthName(data.month) }} payroll is not posted yet<span class="phone-hide">{{ data.last_payroll ? ` (the last is ${monthName(data.last_payroll)})` : '' }}. Loan repayments come off salaries, so until it is, loans look later than they are and the month's figures are not complete</span>.</p>
    </div>
    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
    <p v-if="busy && !data" class="mt-6 text-sm text-gray-500">Putting the month together…</p>

    <div v-if="data && data.trend.length" class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
      <section v-for="s in series" :key="s.title" class="card min-w-0 p-5">
        <h2 class="font-semibold">{{ s.title }}</h2>
        <p class="phone-hide text-xs text-gray-500">{{ s.note }}</p>
        <MonthChart :key="data.month + s.title" class="mt-3" :points="s.points" :kind="s.kind" :color="s.color" :label="s.title" :format="s.format" />
      </section>
    </div>

    <div v-if="data" class="mt-6 space-y-6">
      <ReportView v-for="r in data.reports" :key="r.title" :report="r" />
    </div>
  </div>
</template>
