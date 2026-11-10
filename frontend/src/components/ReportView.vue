<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { CheckCircleIcon, DocumentArrowDownIcon, ExclamationTriangleIcon, PrinterIcon, TableCellsIcon } from '@heroicons/vue/24/outline'
import { useAuth } from '@/stores/auth'
import { formatDate, formatMoney } from '@/money'
import type { Report, ReportColumn, ReportRow } from '@/reports'
import { downloadExcel, downloadPdf } from '@/exporters'
import SaccoMark from '@/components/SaccoMark.vue'

/**
 * Shows a report the same way everywhere: on screen, as an Excel download, and printed (or saved
 * as PDF from the print window) on a clean page with the SACCO's heading. Controls (dates, an
 * account...) go in the `controls` slot; per-row buttons in `actions`.
 */
const props = defineProps<{
  report: Report | null
  busy?: boolean
  error?: string
  /** Rows are clickable (e.g. an account opens its ledger) */
  clickable?: (row: ReportRow) => boolean
}>()
const emit = defineEmits<{ rowClick: [row: ReportRow] }>()
const slots = defineSlots<{ controls?: () => unknown; actions?: (p: { row: ReportRow }) => unknown; empty?: () => unknown }>()

const auth = useAuth()
// The screen shows the first rows; Excel and the printout have all of them
const SHOWN = 300
const printing = ref(false)
const rows = computed(() => (printing.value ? props.report?.rows ?? [] : props.report?.rows.slice(0, SHOWN) ?? []))
const numeric = (c: ReportColumn) => c.type === 'money' || c.type === 'number'

function show(c: ReportColumn, v: string | number | null | undefined) {
  if (v === null || v === undefined || v === '') return ''
  if (c.type === 'money') return formatMoney(String(v))
  if (c.type === 'date') return formatDate(String(v))
  if (c.type === 'number') return Number(v).toLocaleString()
  return String(v)
}

async function print() {
  printing.value = true
  document.body.classList.add('printing-report')
  await nextTick()
  const done = () => {
    document.body.classList.remove('printing-report')
    printing.value = false
    window.removeEventListener('afterprint', done)
  }
  window.addEventListener('afterprint', done)
  window.print()
}

// Excel and PDF are made in the browser; the libraries load the first time
const making = ref<'' | 'excel' | 'pdf'>('')
async function exportAs(kind: 'excel' | 'pdf') {
  if (!props.report) return
  making.value = kind
  try {
    const who = { sacco: auth.sacco, user: auth.user?.full_name ?? null }
    await (kind === 'excel' ? downloadExcel(props.report, who) : downloadPdf(props.report, who))
  } finally {
    making.value = ''
  }
}

const printedAt = computed(() => new Date().toLocaleString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }))
</script>

<template>
  <section class="card print-area overflow-hidden print:rounded-none print:border-0 print:shadow-none">
    <!-- The heading on paper -->
    <div class="hidden border-b-2 border-gray-800 pb-3 print:block">
      <div class="flex items-center gap-3">
        <SaccoMark :sacco="auth.sacco" />
        <div>
          <p class="text-lg font-bold">{{ auth.sacco?.name }}</p>
          <p v-if="auth.sacco?.slogan" class="text-xs text-gray-600">{{ auth.sacco.slogan }}</p>
        </div>
      </div>
      <h1 class="mt-3 text-xl font-bold">{{ report?.title }}</h1>
      <p class="text-sm text-gray-700">{{ report?.subtitle }}</p>
    </div>

    <!-- On screen: title, controls and downloads -->
    <div class="flex flex-wrap items-end justify-between gap-3 border-b border-line p-4 print:hidden">
      <div class="min-w-0">
        <h2 class="font-semibold">{{ report?.title ?? ' ' }}</h2>
        <p v-if="report" class="flex flex-wrap items-center gap-2 text-sm text-gray-600">
          <span>{{ report.subtitle.replace(/ · (NOT )?BALANCED.*$| · balanced$/, '') }}</span>
          <span v-if="report.balanced === true" class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700"><CheckCircleIcon class="h-3.5 w-3.5" />Balanced</span>
          <span v-else-if="report.balanced === false" class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700"><ExclamationTriangleIcon class="h-3.5 w-3.5" />{{ report.subtitle.match(/NOT BALANCED.*/)?.[0] ?? 'Not balanced' }}</span>
        </p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <slot name="controls" />
        <div class="flex overflow-hidden rounded-lg border border-line bg-surface shadow-sm" role="group" aria-label="Download or print">
          <button type="button" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-50 disabled:opacity-40" :disabled="!report || !report.rows.length || !!making" title="Download as Excel" @click="exportAs('excel')">
            <TableCellsIcon class="h-4 w-4" :class="{ 'animate-pulse': making === 'excel' }" />Excel
          </button>
          <button type="button" class="inline-flex items-center gap-1.5 border-l border-line px-3 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-50 disabled:opacity-40" :disabled="!report || !report.rows.length || !!making" title="Download as PDF" @click="exportAs('pdf')">
            <DocumentArrowDownIcon class="h-4 w-4" :class="{ 'animate-pulse': making === 'pdf' }" />PDF
          </button>
          <button type="button" class="inline-flex items-center gap-1.5 border-l border-line px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 disabled:opacity-40" :disabled="!report || !report.rows.length" title="Print" @click="print">
            <PrinterIcon class="h-4 w-4" />Print
          </button>
        </div>
      </div>
    </div>

    <div v-if="error" class="m-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 print:hidden" role="alert">{{ error }}</div>
    <p v-if="busy && !report" class="p-6 text-sm text-gray-500">Working it out…</p>

    <div v-if="report" class="max-h-[40rem] overflow-auto print:max-h-none print:overflow-visible" :class="{ 'opacity-60': busy }">
      <table class="w-full text-sm print:text-[11px]">
        <thead class="sticky top-0 z-10 bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 print:static print:bg-white print:text-gray-800">
          <tr class="print:border-b print:border-gray-800">
            <th v-for="c in report.columns" :key="c.key" class="whitespace-nowrap px-3 py-2.5" :class="{ 'text-right': numeric(c) }">{{ c.label }}</th>
            <th v-if="slots.actions" class="px-3 py-2.5 print:hidden"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="(row, i) in rows" :key="i">
            <tr v-if="row._kind === 'heading'" class="bg-gray-50/60 print:bg-white">
              <td :colspan="report.columns.length + (slots.actions ? 1 : 0)" class="px-3 pb-1.5 pt-4 text-xs font-bold uppercase tracking-wider text-gray-700">
                {{ report.columns.map((c) => row[c.key]).find((v) => v) }}
              </td>
            </tr>
            <tr
              v-else
              class="border-t border-line/70 print:break-inside-avoid"
              :class="[
                row._kind === 'subtotal' && 'font-semibold',
                row._kind === 'total' && 'border-t-2 !border-gray-700 bg-gray-50 text-base font-bold print:bg-white',
                clickable?.(row) && 'cursor-pointer hover:bg-navy-50/50',
              ]"
              @click="clickable?.(row) && emit('rowClick', row)"
            >
              <td
                v-for="c in report.columns"
                :key="c.key"
                class="px-3 py-2 print:py-1"
                :class="{
                  'whitespace-nowrap text-right tabular-nums': numeric(c),
                  'whitespace-nowrap': c.type === 'date',
                  'font-mono text-xs': c.key === 'code' || c.key === 'account_no' || c.key === 'loan_no' || c.key === 'entry',
                  'pl-8': c.key === 'details' && String(row[c.key] ?? '').startsWith('  '),
                }"
              >{{ show(c, row[c.key]) }}</td>
              <td v-if="slots.actions" class="whitespace-nowrap px-3 py-1 text-right print:hidden"><slot name="actions" :row="row" /></td>
            </tr>
          </template>
          <tr v-if="!report.rows.length">
            <td :colspan="report.columns.length + 1" class="px-3 py-10 text-center text-gray-500"><slot name="empty">Nothing to show for this period.</slot></td>
          </tr>
        </tbody>
        <tfoot v-if="report.totals && report.rows.length" class="sticky bottom-0 border-t-2 border-gray-700 bg-gray-50 font-bold print:static print:bg-white">
          <tr>
            <td v-for="(c, i) in report.columns" :key="c.key" class="whitespace-nowrap px-3 py-2.5" :class="{ 'text-right tabular-nums': numeric(c) }">
              {{ i === 0 ? 'Total' : c.key in report.totals ? formatMoney(report.totals[c.key]) : '' }}
            </td>
            <td v-if="slots.actions" class="print:hidden" />
          </tr>
        </tfoot>
      </table>
    </div>
    <p v-if="report && report.rows.length > SHOWN" class="border-t border-line px-4 py-2 text-xs text-gray-500 print:hidden">Showing the first {{ SHOWN }} rows. Excel and the printout have all {{ report.rows.length }}.</p>

    <p class="hidden pt-4 text-[11px] text-gray-500 print:block">Printed {{ printedAt }} by {{ auth.user?.full_name }} · {{ auth.sacco?.name }}</p>
  </section>
</template>
