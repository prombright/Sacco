<script setup lang="ts">
import BannerArt from '@/components/BannerArt.vue'
import { computed, onMounted, ref, watch } from 'vue'
import { CheckCircleIcon, DocumentArrowDownIcon, ExclamationTriangleIcon, TableCellsIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import type { Report } from '@/reports'
import { downloadPdfBook, downloadWorkbook } from '@/exporters'
import ReportView from '@/components/ReportView.vue'
import CountUp from '@/components/CountUp.vue'

interface Annual {
  year: number
  period: string
  highlights: { members: number; savings: string; shares: string; loans_issued: string; income: string; surplus: string; balanced: boolean }
  reports: Report[]
}

/** The year in one document for the AGM and the auditors: key figures, statements, loans, payouts */
const auth = useAuth()
const thisYear = new Date().getFullYear()
const year = ref(thisYear)
const data = ref<Annual | null>(null)
const busy = ref(false)
const error = ref('')
const making = ref<'' | 'pdf' | 'excel'>('')

async function load() {
  busy.value = true
  error.value = ''
  try {
    data.value = await api.get<Annual>('/accounting/annual', { year: year.value })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t put the report together.'
  } finally {
    busy.value = false
  }
}
onMounted(load)
watch(year, load)

const short = (v: string | number) => {
  const n = Number(v)
  return n >= 1e9 ? `${+(n / 1e9).toFixed(2)}B` : n >= 1e6 ? `${+(n / 1e6).toFixed(2)}M` : n.toLocaleString('en-US')
}
const cards = computed(() => {
  const h = data.value?.highlights
  if (!h) return []
  const cur = auth.sacco?.currency ?? 'UGX'
  return [
    { label: 'Members', raw: h.members, value: String(h.members) },
    { label: `Savings (${cur})`, raw: h.savings, value: short(h.savings) },
    { label: `Share capital (${cur})`, raw: h.shares, value: short(h.shares) },
    { label: `Loans issued (${cur})`, raw: h.loans_issued, value: short(h.loans_issued) },
    { label: `Income (${cur})`, raw: h.income, value: short(h.income) },
    { label: `${Number(h.surplus) >= 0 ? 'Surplus' : 'Deficit'} (${cur})`, raw: h.surplus, value: short(h.surplus) },
  ]
})

async function download(kind: 'pdf' | 'excel') {
  if (!data.value) return
  making.value = kind
  const who = { sacco: auth.sacco, user: auth.user?.full_name ?? null }
  const title = `${auth.sacco?.short_name ?? 'SACCO'} annual report ${data.value.year}`
  try {
    if (kind === 'pdf') {
      await downloadPdfBook(data.value.reports, who, title, {
        title: `Annual report ${data.value.year}`,
        subtitle: data.value.period,
        note: 'Prepared for the Annual General Meeting',
        highlights: cards.value.map((c) => [c.label, c.value]),
      })
    } else {
      await downloadWorkbook(data.value.reports, who, title)
    }
  } finally {
    making.value = ''
  }
}
</script>

<template>
  <div>
    <div class="page-banner relative overflow-hidden rounded-[1.25rem] border border-[#e5ddfd] p-5 shadow-sm dark:border-line sm:p-6">
      <BannerArt />
      <div class="relative flex flex-wrap items-end justify-between gap-4">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wider text-[#6a1ff5] dark:text-navy-200">Reports</p>
          <h1 class="mt-1 text-[1.3rem] font-bold leading-tight text-[#16133a] dark:text-white sm:text-[1.75rem]">Annual report {{ year }}</h1>
          <p class="mt-1 text-gray-600 dark:text-gray-400">{{ data?.period ?? ' ' }} · for the AGM, the committee and the auditors</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <div class="flex rounded-lg bg-[#e6ecfb] p-0.5 text-sm font-semibold dark:bg-white/10" role="radiogroup" aria-label="Year">
            <button v-for="y in [thisYear - 2, thisYear - 1, thisYear]" :key="y" type="button" role="radio" :aria-checked="year === y" class="rounded-md px-3 py-1.5" :class="year === y ? 'bg-white text-[#6a1ff5] shadow-sm dark:bg-surface' : 'text-gray-600 hover:text-ink'" @click="year = y">{{ y }}</button>
          </div>
          <button type="button" class="btn-primary" :disabled="!data || !!making" @click="download('pdf')"><DocumentArrowDownIcon class="h-4 w-4" :class="{ 'animate-pulse': making === 'pdf' }" />Annual report (PDF)</button>
          <button type="button" class="btn-secondary" :disabled="!data || !!making" @click="download('excel')"><TableCellsIcon class="h-4 w-4" :class="{ 'animate-pulse': making === 'excel' }" />Excel workbook</button>
        </div>
      </div>
      <div v-if="data" class="relative mt-6 grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-[#e5ddfd] bg-[#e5ddfd] dark:border-line dark:bg-line sm:grid-cols-3 xl:grid-cols-6">
        <div v-for="(c, n) in cards" :key="c.label" class="bg-white/90 p-4 dark:bg-surface">
          <p class="text-2xl font-bold text-[#16133a] dark:text-white"><CountUp :value="c.raw" :format="short" :delay="n * 120" /></p>
          <p class="mt-0.5 text-xs text-gray-500">{{ c.label }}</p>
        </div>
      </div>
      <p v-if="data" class="relative mt-4 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold" :class="data.highlights.balanced ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'">
        <component :is="data.highlights.balanced ? CheckCircleIcon : ExclamationTriangleIcon" class="h-4 w-4" />{{ data.highlights.balanced ? 'The books balance' : 'The books do not balance: check before the AGM' }}
      </p>
    </div>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
    <p v-if="busy && !data" class="mt-6 text-sm text-gray-500">Putting the year together…</p>

    <div v-if="data" class="mt-6 space-y-6">
      <ReportView v-for="r in data.reports" :key="r.title" :report="r" />
    </div>
  </div>
</template>
