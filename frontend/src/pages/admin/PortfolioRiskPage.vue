<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { DocumentArrowDownIcon, ExclamationTriangleIcon, TableCellsIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { formatMoney } from '@/money'
import { useAuth } from '@/stores/auth'
import { useTheme } from '@/stores/theme'
import { downloadExcel, downloadPdf } from '@/exporters'
import type { Report } from '@/reports'
import PageHeader from '@/components/PageHeader.vue'
import MonthChart from '@/components/MonthChart.vue'
import SkeletonRows from '@/components/SkeletonRows.vue'

/**
 * Portfolio at risk: how much of the money lent is in loans that are late, and how late, on any
 * day; by loan type; the most-late loans; and PAR30 at each of the last 12 month ends.
 */
interface Risk {
  as_of: string
  last_payroll: string | null
  payroll_behind: boolean
  loans: number
  principal: string
  late_loans: number
  par: Record<'30' | '60' | '90', number>
  buckets: { key: string; label: string; loans: number; principal: string; share: number }[]
  by_product: { name: string; loans: number; principal: string; par30: number }[]
  worst: { id: number; loan_no: string; borrower: string; product: string; days: number; principal: string }[]
  trend: { month: string; value: number; principal: string }[]
}

const auth = useAuth()
const theme = useTheme()
const asOf = ref(new Date().toLocaleDateString('en-CA'))
const data = ref<Risk | null>(null)
const error = ref('')
async function load() {
  error.value = ''
  try {
    data.value = await api.get<Risk>('/reports/portfolio-risk', { as_of: asOf.value })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t work out the portfolio.'
  }
}
watch(asOf, (v) => v && load())
onMounted(load)

/** Colour by how late: calm for current, warmer as it gets later (status colours, with words beside) */
const BAND: Record<string, string> = {
  current: 'bg-emerald-500',
  '1-30': 'bg-amber-300',
  '31-60': 'bg-amber-500',
  '61-90': 'bg-orange-500',
  '91-180': 'bg-red-500',
  '180+': 'bg-red-700',
}
const parTone = (v: number) => (v >= 10 ? 'text-red-700' : v >= 5 ? 'text-amber-700' : 'text-emerald-700')
const monthName = (m: string | null) => (m ? new Date(m + '-01T00:00:00').toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }) : '')
const dayName = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })

const report = computed<Report | null>(() => {
  const d = data.value
  if (!d) return null
  return {
    title: 'Portfolio at risk',
    subtitle: `As at ${dayName(d.as_of)} · ${d.loans} loans · PAR30 ${d.par['30']}% · PAR60 ${d.par['60']}% · PAR90 ${d.par['90']}%`,
    columns: [
      { key: 'band', label: 'How late', type: 'text' },
      { key: 'loans', label: 'Loans', type: 'number' },
      { key: 'principal', label: 'Principal still owed', type: 'money' },
      { key: 'share', label: '% of the book', type: 'text' },
    ],
    rows: [
      ...d.buckets.map((b) => ({ band: b.label, loans: b.loans, principal: b.principal, share: `${b.share}%` })),
      { _kind: 'heading' as const, band: 'By loan type' },
      ...d.by_product.map((p) => ({ band: p.name, loans: p.loans, principal: p.principal, share: `PAR30 ${p.par30}%` })),
      { _kind: 'heading' as const, band: 'The most-late loans' },
      ...d.worst.map((w) => ({ band: `${w.loan_no} · ${w.borrower} · ${w.days} days`, loans: 1, principal: w.principal, share: '' })),
    ],
    totals: null,
  }
})
const making = ref('')
async function download(kind: 'excel' | 'pdf') {
  if (!report.value) return
  making.value = kind
  try {
    const who = { sacco: auth.sacco, user: auth.user?.full_name ?? null }
    await (kind === 'excel' ? downloadExcel(report.value, who) : downloadPdf(report.value, who))
  } finally {
    making.value = ''
  }
}
</script>

<template>
  <div>
    <PageHeader title="Portfolio at risk">
      How much of the money lent is in loans that are late, and how late. PAR30 is the share of the loan book in loans more than 30 days behind: the figure boards and regulators watch.
    </PageHeader>

    <section class="mt-5 flex flex-wrap items-end gap-3">
      <div><label for="par-day" class="label">As at</label><input id="par-day" v-model="asOf" type="date" class="input" :max="new Date().toLocaleDateString('en-CA')" /></div>
      <div class="flex overflow-hidden rounded-lg border border-line bg-surface shadow-sm sm:ml-auto" role="group" aria-label="Download">
        <button type="button" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50 disabled:opacity-40" :disabled="!data || !!making" @click="download('excel')"><TableCellsIcon class="h-4 w-4" />Excel</button>
        <button type="button" class="inline-flex items-center gap-1.5 border-l border-line px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-40" :disabled="!data || !!making" @click="download('pdf')"><DocumentArrowDownIcon class="h-4 w-4" />PDF</button>
      </div>
    </section>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
    <div v-if="!data && !error" class="card mt-4"><SkeletonRows :rows="5" /></div>

    <template v-if="data">
      <!-- Without the payroll, repayments from salaries aren't in: say so before anyone reads the figures -->
      <p v-if="data.payroll_behind" class="mt-4 flex gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <ExclamationTriangleIcon class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
        <span>
          <strong>Read with care:</strong> {{ data.last_payroll ? `the last payroll posted was for ${monthName(data.last_payroll)}` : 'no payroll has been posted yet' }}.
          <span class="phone-hide">Repayments come off salaries, so until the missing months are posted, loans look later than they are.</span>
        </span>
      </p>

      <section class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Summary">
        <div class="card col-span-2 p-4 lg:col-span-1">
          <p class="eyebrow">Principal still owed</p>
          <p class="mt-1.5 text-2xl font-bold tabular-nums">{{ formatMoney(data.principal) }}</p>
          <p class="mt-0.5 text-xs text-gray-500">{{ data.loans }} running loans · {{ data.late_loans }} late</p>
        </div>
        <div v-for="k in (['30', '60', '90'] as const)" :key="k" class="card p-4">
          <p class="eyebrow">PAR{{ k }}</p>
          <p class="mt-1.5 text-2xl font-bold tabular-nums" :class="parTone(data.par[k])">{{ data.par[k] }}%</p>
          <p class="mt-0.5 text-xs text-gray-500">of the book over {{ k }} days late</p>
        </div>
      </section>

      <div class="mt-4 grid gap-4 xl:grid-cols-5">
        <!-- How late, as one bar and a table -->
        <section class="card p-5 xl:col-span-3">
          <h2 class="font-semibold">How late the loan book is</h2>
          <div class="mt-3 flex h-3 overflow-hidden rounded-full bg-gray-100" role="img" :aria-label="data.buckets.map((b) => `${b.label} ${b.share}%`).join(', ')">
            <div v-for="b in data.buckets.filter((x) => x.share > 0)" :key="b.key" class="h-full" :class="BAND[b.key]" :style="{ width: b.share + '%' }" />
          </div>
          <table v-stack class="mt-4 w-full text-sm">
            <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
              <tr><th class="py-2">How late</th><th class="py-2 text-right">Loans</th><th class="py-2 text-right">Principal still owed</th><th class="py-2 text-right">% of the book</th></tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="b in data.buckets" :key="b.key">
                <td class="py-2"><span class="mr-2 inline-block h-2.5 w-2.5 rounded-full align-middle" :class="BAND[b.key]" aria-hidden="true" />{{ b.label }}</td>
                <td class="py-2 text-right">{{ b.loans }}</td>
                <td class="py-2 text-right">{{ formatMoney(b.principal) }}</td>
                <td class="py-2 text-right font-semibold">{{ b.share }}%</td>
              </tr>
            </tbody>
          </table>
          <h3 class="mt-5 text-sm font-semibold">By loan type</h3>
          <table v-stack class="mt-2 w-full text-sm">
            <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
              <tr><th class="py-2">Loan type</th><th class="py-2 text-right">Loans</th><th class="py-2 text-right">Principal still owed</th><th class="py-2 text-right">PAR30</th></tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="p in data.by_product" :key="p.name">
                <td class="py-2">{{ p.name }}</td>
                <td class="py-2 text-right">{{ p.loans }}</td>
                <td class="py-2 text-right">{{ formatMoney(p.principal) }}</td>
                <td class="py-2 text-right font-semibold" :class="parTone(p.par30)">{{ p.par30 }}%</td>
              </tr>
            </tbody>
          </table>
        </section>

        <!-- PAR30 over the year -->
        <section class="card min-w-0 p-5 xl:col-span-2">
          <h2 class="font-semibold">PAR30, month by month</h2>
          <p class="phone-hide text-xs text-gray-500">At each month end; lower is better</p>
          <MonthChart class="mt-4" :points="data.trend" kind="bar" :color="theme.chart.amber" label="PAR30" :format="(v: number) => `${v}%`" />
        </section>
      </div>

      <!-- Who is most behind -->
      <section class="card mt-4 overflow-hidden">
        <h2 class="border-b border-line px-5 py-4 font-semibold">The most-late loans</h2>
        <p v-if="!data.worst.length" class="px-5 py-8 text-center text-sm text-gray-500">No loan is late on this day.</p>
        <ul v-else class="divide-y divide-line">
          <li v-for="w in data.worst" :key="w.id">
            <RouterLink :to="{ name: 'loan', params: { id: w.id } }" class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-gray-50">
              <span class="min-w-0"><span class="block truncate font-medium">{{ w.borrower }}</span><span class="text-xs text-gray-500">{{ w.loan_no }} · {{ w.product }}</span></span>
              <span class="shrink-0 text-right text-sm tabular-nums"><span class="block font-semibold" :class="w.days > 90 ? 'text-red-700' : w.days > 30 ? 'text-amber-700' : ''">{{ w.days }} days late</span><span class="text-xs text-gray-500">{{ formatMoney(w.principal) }} still owed</span></span>
            </RouterLink>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>
