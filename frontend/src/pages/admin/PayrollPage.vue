<script setup lang="ts">
import { ask } from '@/confirm'
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/api'
import { formatDate, formatMoney } from '@/money'
import { downloadTemplate, readPayrollFile, type PayrollRow, type TemplateMember } from '@/payroll'
import StatusBadge from '@/components/StatusBadge.vue'

interface CheckedRow {
  row: number
  account_no: string
  name: string
  member_name: string | null
  savings: string
  shares: string
  loan: string
  principal: string
  interest: string
  total: string
  loan_no: string | null
  errors: string[]
  warnings: string[]
}
interface Report {
  period: string
  rows: CheckedRow[]
  row_count: number
  posted_rows: number
  error_count: number
  warning_count: number
  totals: { savings: string; shares: string; loan: string; principal: string; interest: string; total: string }
  duplicate_period: boolean
}
interface Batch {
  id: number
  period: string
  file_name: string | null
  row_count: number
  total_savings: string
  total_shares: string
  total_loans: string
  status: 'posted' | 'reversed'
  created_by_name: string | null
  created_at: string
}

const router = useRouter()
const today = new Date()
const period = ref(`${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`)
const valueDate = ref(today.toLocaleDateString('en-CA'))

const batches = ref<Batch[]>([])
const fileName = ref('')
const rows = ref<PayrollRow[]>([])
const report = ref<Report | null>(null)
const show = ref<'all' | 'problems'>('problems')
const error = ref('')
const busy = ref(false)

const visibleRows = computed(() =>
  (report.value?.rows ?? []).filter((r) => show.value === 'all' || r.errors.length || r.warnings.length),
)

async function loadBatches() {
  batches.value = (await api.get<{ batches: Batch[] }>('/payroll/batches')).batches
}
onMounted(() => loadBatches().catch(() => {}))

async function template() {
  error.value = ''
  try {
    const res = await api.get<{ members: TemplateMember[] }>('/payroll/members')
    downloadTemplate(res.members, period.value)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t make the template.'
  }
}

async function pick(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  ;(event.target as HTMLInputElement).value = ''
  if (!file) return
  error.value = ''
  report.value = null
  busy.value = true
  try {
    fileName.value = file.name
    rows.value = await readPayrollFile(file)
    await preview()
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Couldn\'t read that file.'
    rows.value = []
  } finally {
    busy.value = false
  }
}

async function preview() {
  report.value = await api.post<Report>('/payroll/preview', { period: period.value, rows: rows.value })
  show.value = report.value.error_count || report.value.warning_count ? 'problems' : 'all'
}

async function post() {
  if (!report.value) return
  let confirmDuplicate = false
  if (report.value.duplicate_period) {
    if (!await ask(`A payroll for ${period.value} has already been posted. Post this one as well?`)) return
    confirmDuplicate = true
  }
  if (!await ask(`Post ${report.value.posted_rows} members' deductions for ${period.value}?`)) return
  error.value = ''
  busy.value = true
  try {
    const res = await api.post<{ batch: Batch }>('/payroll/batches', {
      period: period.value,
      value_date: valueDate.value,
      file_name: fileName.value,
      rows: rows.value,
      confirm_duplicate: confirmDuplicate,
    })
    await router.push({ name: 'payroll-batch', params: { id: res.batch.id } })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Something went wrong. Nothing was posted.'
  } finally {
    busy.value = false
  }
}

function reset() {
  report.value = null
  rows.value = []
  fileName.value = ''
}
</script>

<template>
  <div>
    <h1 class="text-xl font-semibold">Payroll uploads</h1>
    <p class="mt-1 text-sm text-gray-600">Post the month's deductions for everyone at once: loan principal and interest, savings, shares, and the total taken from each person's pay.</p>

    <section class="card mt-5 p-5">
      <h2 class="font-semibold">New upload</h2>
      <div class="mt-4 grid gap-4 sm:grid-cols-3">
        <div>
          <label for="period" class="label">Payroll month</label>
          <input id="period" v-model="period" type="month" class="input" :disabled="!!report" />
        </div>
        <div>
          <label for="vdate" class="label">Posting date</label>
          <input id="vdate" v-model="valueDate" type="date" :max="today.toLocaleDateString('en-CA')" class="input" />
        </div>
      </div>

      <ol class="mt-5 space-y-3 text-sm">
        <li class="flex flex-wrap items-center gap-3">
          <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-gray-900 text-xs font-semibold text-white">1</span>
          <span>Download the template: every active member, filled in with this month's loan principal and interest, last month's savings and shares, and each person's total.</span>
          <button type="button" class="btn-secondary px-3 py-1.5" @click="template">Download template</button>
        </li>
        <li class="flex flex-wrap items-center gap-3">
          <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-gray-900 text-xs font-semibold text-white">2</span>
          <span>Correct the amounts for this month, then upload it (.xlsx, .xls or .csv).</span>
          <label class="btn-primary cursor-pointer px-3 py-1.5" :class="{ 'pointer-events-none opacity-50': busy }">
            {{ busy ? 'Reading…' : 'Upload file' }}
            <input type="file" accept=".xlsx,.xls,.csv" class="sr-only" @change="pick" />
          </label>
        </li>
      </ol>

      <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

      <!-- The check, before anything is saved -->
      <div v-if="report" class="mt-6 border-t border-gray-100 pt-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="font-medium">{{ fileName }} · {{ report.row_count }} rows</p>
          <button type="button" class="btn-ghost px-2 py-1" @click="reset">Start again</button>
        </div>

        <div class="mt-3 grid gap-3 sm:grid-cols-5">
          <div class="rounded-lg bg-gray-50 p-3"><p class="text-xs text-gray-600">Savings</p><p class="font-semibold tabular-nums">{{ formatMoney(report.totals.savings) }}</p></div>
          <div class="rounded-lg bg-gray-50 p-3"><p class="text-xs text-gray-600">Shares</p><p class="font-semibold tabular-nums">{{ formatMoney(report.totals.shares) }}</p></div>
          <div class="rounded-lg bg-gray-50 p-3"><p class="text-xs text-gray-600">Loan repayments</p><p class="font-semibold tabular-nums">{{ formatMoney(report.totals.loan) }}</p><p v-if="Number(report.totals.principal) || Number(report.totals.interest)" class="text-xs text-gray-500">{{ formatMoney(report.totals.principal) }} principal · {{ formatMoney(report.totals.interest) }} interest</p></div>
          <div class="rounded-lg bg-navy-50 p-3"><p class="text-xs text-gray-600">Total deducted</p><p class="font-semibold tabular-nums">{{ formatMoney(report.totals.total) }}</p></div>
          <div class="rounded-lg p-3" :class="report.error_count ? 'bg-red-50' : 'bg-green-50'">
            <p class="text-xs" :class="report.error_count ? 'text-red-700' : 'text-green-800'">{{ report.error_count ? 'Must fix' : 'Ready' }}</p>
            <p class="font-semibold" :class="report.error_count ? 'text-red-700' : 'text-green-800'">
              {{ report.error_count ? `${report.error_count} row${report.error_count === 1 ? '' : 's'} with errors` : `${report.posted_rows} members to post` }}
            </p>
          </div>
        </div>

        <p v-if="report.duplicate_period" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">A payroll for {{ report.period }} has already been posted. Check this isn't the same file again.</p>
        <p v-if="report.warning_count" class="mt-3 text-sm text-amber-800">{{ report.warning_count }} row{{ report.warning_count === 1 ? '' : 's' }} worth a second look (they will still post).</p>

        <div class="mt-4 flex gap-1 text-sm">
          <button type="button" class="rounded-md px-3 py-1" :class="show === 'problems' ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-100'" @click="show = 'problems'">Problems only</button>
          <button type="button" class="rounded-md px-3 py-1" :class="show === 'all' ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-100'" @click="show = 'all'">All rows</button>
        </div>

        <div class="mt-3 overflow-x-auto rounded-lg border border-gray-200">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
              <tr>
                <th class="px-3 py-2">Row</th>
                <th class="px-3 py-2">Account</th>
                <th class="px-3 py-2">Member</th>
                <th class="px-3 py-2 text-right">Principal</th>
                <th class="px-3 py-2 text-right">Interest</th>
                <th class="px-3 py-2 text-right">Savings</th>
                <th class="px-3 py-2 text-right">Shares</th>
                <th class="px-3 py-2 text-right">Total</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <template v-for="r in visibleRows" :key="r.row">
                <tr :class="{ 'bg-red-50/50': r.errors.length }">
                  <td class="px-3 py-2 text-gray-500">{{ r.row }}</td>
                  <td class="px-3 py-2 font-mono">{{ r.account_no }}</td>
                  <td class="px-3 py-2">{{ r.member_name ?? r.name }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ Number(r.principal) ? formatMoney(r.principal) : (Number(r.loan) && !Number(r.interest) ? formatMoney(r.loan) : '') }}<span v-if="r.loan_no" class="block text-xs text-gray-500">{{ r.loan_no }}</span></td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ Number(r.interest) ? formatMoney(r.interest) : '' }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ Number(r.savings) ? formatMoney(r.savings) : '' }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ Number(r.shares) ? formatMoney(r.shares) : '' }}</td>
                  <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ Number(r.total) ? formatMoney(r.total) : '' }}</td>
                </tr>
                <tr v-if="r.errors.length || r.warnings.length" :class="{ 'bg-red-50/50': r.errors.length }">
                  <td></td>
                  <td colspan="7" class="px-3 pb-2 text-xs">
                    <p v-for="m in r.errors" :key="m" class="text-red-700">✕ {{ m }}</p>
                    <p v-for="m in r.warnings" :key="m" class="text-amber-800">! {{ m }}</p>
                  </td>
                </tr>
              </template>
              <tr v-if="!visibleRows.length"><td colspan="6" class="px-3 py-6 text-center text-gray-500">No problems found.</td></tr>
            </tbody>
          </table>
        </div>

        <div class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-end">
          <p v-if="report.error_count" class="text-sm text-red-700 sm:mr-auto">Fix the rows marked ✕ in the file and upload it again. Nothing has been saved.</p>
          <button v-can="'money'" type="button" class="btn-primary" :disabled="busy || report.error_count > 0 || report.posted_rows === 0" @click="post">
            {{ busy ? 'Posting…' : `Post ${report.posted_rows} members` }}
          </button>
        </div>
      </div>
    </section>

    <h2 class="mt-8 font-semibold">Past uploads</h2>
    <ul class="card mt-3 divide-y divide-gray-100">
      <li v-for="b in batches" :key="b.id">
        <RouterLink :to="{ name: 'payroll-batch', params: { id: b.id } }" class="flex flex-wrap items-center justify-between gap-3 p-4 hover:bg-gray-50">
          <div>
            <p class="font-medium">{{ b.period }} <span class="font-normal text-gray-500">· {{ b.row_count }} members</span></p>
            <p class="text-sm text-gray-600">{{ b.file_name }} · {{ formatDate(b.created_at) }} · by {{ b.created_by_name }}</p>
          </div>
          <div class="text-right text-sm tabular-nums">
            <p>{{ formatMoney(String(Number(b.total_savings) + Number(b.total_shares) + Number(b.total_loans))) }}</p>
            <StatusBadge v-if="b.status === 'reversed'" label="Reversed" style-class="bg-gray-100 text-gray-600" />
          </div>
        </RouterLink>
      </li>
      <li v-if="!batches.length" class="px-4 py-10 text-center text-sm text-gray-500">No uploads yet.</li>
    </ul>
  </div>
</template>
