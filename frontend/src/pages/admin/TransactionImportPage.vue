<script setup lang="ts">
import { toast } from '@/toast'
import EmptyState from '@/components/EmptyState.vue'
import { computed, onMounted, ref } from 'vue'
import { ArrowDownTrayIcon, ArrowUpTrayIcon, ArrowUturnLeftIcon, CheckCircleIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { ask } from '@/confirm'
import { formatDate, formatMoney } from '@/money'
import { downloadTransactionTemplate, readTransactionFile, type TransactionRow } from '@/transactionImport'
import MoneyAccountSelect from '@/components/MoneyAccountSelect.vue'
import Modal from '@/components/Modal.vue'

/** Dated deposits, withdrawals, shares and loan repayments from Excel, e.g. catching up after the old system */

interface Checked {
  row: number
  date: string | null
  account_no: string
  member_name: string | null
  savings: string
  shares: string
  loan: string
  loan_no: string | null
  reference: string
  errors: string[]
  warnings: string[]
}
interface Report {
  rows: Checked[]
  row_count: number
  error_count: number
  warning_count: number
  member_count: number
  totals: { savings_in: string; savings_out: string; shares: string; loans: string }
  first_date: string | null
  last_date: string | null
  problem: string
}
interface Upload {
  id: number
  upload_no: string
  file_name: string | null
  row_count: number
  total_savings: string
  total_withdrawn: string
  total_shares: string
  total_loans: string
  quiet: boolean
  first_date: string | null
  last_date: string | null
  status: 'posted' | 'reversed'
  created_at: string
  created_by_name: string | null
  reversal_reason: string | null
  money_account: string
}

const rows = ref<TransactionRow[]>([])
const fileName = ref('')
const report = ref<Report | null>(null)
const show = ref<'problems' | 'all'>('problems')
const moneyAccount = ref('')
const quiet = ref(true)
const error = ref('')
const done = ref('')
const busy = ref(false)
const uploads = ref<Upload[]>([])

const visible = computed(() => (report.value?.rows ?? []).filter((r) => show.value === 'all' || r.errors.length || r.warnings.length))

async function load() {
  uploads.value = (await api.get<{ uploads: Upload[] }>('/transaction-imports')).uploads
}
onMounted(load)

async function template() {
  const res = await api.get<{ members: { account_no: string | null; full_name: string }[] }>('/members/search', { all: '1' })
  downloadTransactionTemplate(res.members)
}

async function pick(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  error.value = ''
  done.value = ''
  report.value = null
  busy.value = true
  try {
    fileName.value = file.name
    rows.value = await readTransactionFile(file)
    if (!rows.value.length) throw new Error('The file has no rows.')
    report.value = await api.post<Report>('/transaction-imports/preview', { rows: rows.value })
    show.value = report.value.error_count || report.value.warning_count ? 'problems' : 'all'
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Couldn\'t read that file.'
  } finally {
    busy.value = false
  }
}

async function save() {
  const r = report.value
  if (!r) return
  const range = r.first_date === r.last_date ? formatDate(r.first_date) : `${formatDate(r.first_date)} to ${formatDate(r.last_date)}`
  if (!await ask(`Record ${r.row_count} entries for ${r.member_count} members, dated ${range}${quiet.value ? ', without messaging members' : ''}?`)) return
  error.value = ''
  busy.value = true
  try {
    const res = await api.post<{ upload: Upload }>('/transaction-imports', { rows: rows.value, money_account_id: moneyAccount.value || undefined, quiet: quiet.value, file_name: fileName.value })
    done.value = `Upload ${res.upload.upload_no}: ${res.upload.row_count} entries recorded.`
    toast(`${res.upload.row_count} entries recorded (${res.upload.upload_no})`)
    report.value = null
    rows.value = []
    await load()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Something went wrong. Nothing was saved.'
  } finally {
    busy.value = false
  }
}

// --- Undo a whole upload
const undoing = ref<Upload | null>(null)
const reason = ref('')
const undoError = ref('')
async function undo() {
  undoError.value = ''
  try {
    await api.post(`/payroll/batches/${undoing.value!.id}/reverse`, { reason: reason.value })
    undoing.value = null
    await load()
  } catch (err) {
    undoError.value = err instanceof ApiError ? err.message : 'Couldn\'t undo it.'
  }
}
const money = (v: string, sign = false) => (Number(v) ? formatMoney(v, { sign }) : '')
</script>

<template>
  <div class="max-w-6xl">
    <p class="eyebrow">Savings & shares</p>
    <h1 class="mt-1 text-2xl font-bold">Import transactions from Excel</h1>
    <p class="mt-1 max-w-3xl text-gray-600">
      Deposits, withdrawals, shares and loan repayments, each on its real date: for example everything since the old system's balance date.
      Every row is checked first, nothing is saved until you confirm, the same file can't go in twice, and a whole upload can be undone.
      For a month's payroll, use <RouterLink :to="{ name: 'payroll' }" class="font-medium text-brand hover:underline">Payroll deductions</RouterLink>.
    </p>

    <section class="card mt-5 p-5">
      <div class="grid gap-4 sm:grid-cols-2">
        <MoneyAccountSelect id="ti-money" v-model="moneyAccount" label="Money in and out goes through" />
        <label class="flex items-start gap-2 self-end text-sm">
          <input v-model="quiet" type="checkbox" class="mt-0.5 h-4 w-4 accent-brand" />
          <span><span class="font-medium">Don't message members</span> <span class="text-gray-600">(right for old entries; untick to tell each member what was recorded)</span></span>
        </label>
      </div>
      <div class="mt-5 flex flex-wrap gap-2">
        <button type="button" class="btn-secondary" @click="template"><ArrowDownTrayIcon class="h-4 w-4" />Download template</button>
        <label class="btn-primary cursor-pointer" :class="{ 'pointer-events-none opacity-50': busy }">
          <ArrowUpTrayIcon class="h-4 w-4" />{{ busy && !report ? 'Reading…' : 'Upload file' }}
          <input type="file" accept=".xlsx,.xls,.csv" class="sr-only" @change="pick" />
        </label>
      </div>

      <p v-if="done" class="mt-4 flex items-center gap-2 text-sm text-emerald-700"><CheckCircleIcon class="h-5 w-5" aria-hidden="true" />{{ done }}</p>
      <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

      <div v-if="report" class="mt-6 border-t border-line pt-5">
        <p class="font-medium">{{ fileName }} · {{ report.row_count }} rows · {{ report.member_count }} members<template v-if="report.first_date"> · {{ formatDate(report.first_date) }}<template v-if="report.last_date !== report.first_date"> to {{ formatDate(report.last_date) }}</template></template></p>
        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
          <div class="rounded-lg bg-gray-50 p-3"><p class="text-xs text-gray-600">Savings in</p><p class="font-semibold tabular-nums">{{ formatMoney(report.totals.savings_in) }}</p></div>
          <div class="rounded-lg bg-gray-50 p-3"><p class="text-xs text-gray-600">Savings out</p><p class="font-semibold tabular-nums">{{ formatMoney(report.totals.savings_out) }}</p></div>
          <div class="rounded-lg bg-gray-50 p-3"><p class="text-xs text-gray-600">Shares</p><p class="font-semibold tabular-nums">{{ formatMoney(report.totals.shares) }}</p></div>
          <div class="rounded-lg bg-gray-50 p-3"><p class="text-xs text-gray-600">Loan repayments</p><p class="font-semibold tabular-nums">{{ formatMoney(report.totals.loans) }}</p></div>
          <div class="col-span-2 rounded-lg p-3 sm:col-span-1" :class="report.error_count || report.problem ? 'bg-red-50' : 'bg-green-50'">
            <p class="text-xs" :class="report.error_count || report.problem ? 'text-red-700' : 'text-green-800'">{{ report.error_count || report.problem ? 'Must fix' : 'Ready' }}</p>
            <p class="font-semibold" :class="report.error_count || report.problem ? 'text-red-700' : 'text-green-800'">{{ report.error_count ? `${report.error_count} row${report.error_count === 1 ? '' : 's'} with problems` : report.problem ? 'Already in' : 'All rows check out' }}</p>
          </div>
        </div>
        <p v-if="report.problem" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ report.problem }}</p>
        <p v-if="report.warning_count" class="mt-3 text-sm text-amber-800">{{ report.warning_count }} row{{ report.warning_count === 1 ? '' : 's' }} worth a second look (see the ! notes below). They will still be recorded.</p>

        <div class="mt-4 flex gap-1 text-sm">
          <button type="button" class="rounded-md px-3 py-1" :class="show === 'problems' ? 'bg-ink text-white' : 'text-gray-600 hover:bg-gray-100'" @click="show = 'problems'">Problems only</button>
          <button type="button" class="rounded-md px-3 py-1" :class="show === 'all' ? 'bg-ink text-white' : 'text-gray-600 hover:bg-gray-100'" @click="show = 'all'">All rows</button>
        </div>
        <div class="mt-2 max-h-96 overflow-auto rounded-xl border border-line">
          <table class="w-full text-sm">
            <thead class="sticky top-0 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
              <tr><th class="px-3 py-2">Row</th><th class="px-3 py-2">Date</th><th class="px-3 py-2">Member</th><th class="px-3 py-2 text-right">Savings</th><th class="px-3 py-2 text-right">Shares</th><th class="px-3 py-2 text-right">Loan</th><th class="px-3 py-2">Reference</th></tr>
            </thead>
            <tbody class="divide-y divide-line">
              <template v-for="r in visible" :key="r.row">
                <tr :class="r.errors.length ? 'bg-red-50/40' : ''">
                  <td class="px-3 py-2 tabular-nums text-gray-500">{{ r.row }}</td>
                  <td class="whitespace-nowrap px-3 py-2">{{ r.date ? formatDate(r.date) : '' }}</td>
                  <td class="px-3 py-2">{{ r.member_name }} <span class="font-mono text-xs text-gray-500">{{ r.account_no }}</span></td>
                  <td class="px-3 py-2 text-right tabular-nums" :class="Number(r.savings) < 0 ? 'text-red-700' : ''">{{ money(r.savings, true) }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ money(r.shares) }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ money(r.loan) }}<span v-if="r.loan_no" class="block text-xs text-gray-500">{{ r.loan_no }}</span></td>
                  <td class="px-3 py-2 text-gray-600">{{ r.reference }}</td>
                </tr>
                <tr v-if="r.errors.length || r.warnings.length">
                  <td></td>
                  <td colspan="6" class="px-3 pb-2 text-xs">
                    <p v-for="e in r.errors" :key="e" class="text-red-700">✕ {{ e }}</p>
                    <p v-for="w in r.warnings" :key="w" class="text-amber-800">! {{ w }}</p>
                  </td>
                </tr>
              </template>
              <tr v-if="!visible.length"><td colspan="7" class="px-3 py-6 text-center text-gray-500">No problems found.</td></tr>
            </tbody>
          </table>
        </div>
        <div class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="report = null">Start again</button>
          <button type="button" class="btn-primary" :disabled="busy || report.error_count > 0 || !!report.problem" @click="save">{{ busy ? 'Recording…' : `Record ${report.row_count} entries` }}</button>
        </div>
      </div>
    </section>

    <section class="mt-8">
      <h2 class="font-semibold">Uploads</h2>
      <ul v-if="uploads.length" class="card mt-3 divide-y divide-line">
        <li v-for="u in uploads" :key="u.id" class="flex flex-wrap items-center justify-between gap-3 p-4 text-sm" :class="{ 'text-gray-400': u.status === 'reversed' }">
          <div class="min-w-0">
            <p class="font-medium">{{ u.upload_no }} · {{ u.file_name || 'Upload' }} · {{ u.row_count }} entries</p>
            <p class="text-gray-600">
              {{ formatDate(u.first_date) }}<template v-if="u.last_date !== u.first_date"> to {{ formatDate(u.last_date) }}</template>
              · in {{ formatMoney(u.total_savings) }}<template v-if="Number(u.total_withdrawn)"> · out {{ formatMoney(u.total_withdrawn) }}</template><template v-if="Number(u.total_shares)"> · shares {{ formatMoney(u.total_shares) }}</template><template v-if="Number(u.total_loans)"> · loans {{ formatMoney(u.total_loans) }}</template>
              · {{ u.money_account }}<template v-if="u.quiet"> · no messages</template>
            </p>
            <p class="text-xs text-gray-500">By {{ u.created_by_name }} on {{ formatDate(u.created_at) }}<template v-if="u.status === 'reversed'"> · undone: {{ u.reversal_reason }}</template></p>
          </div>
          <span v-if="u.status === 'reversed'" class="rounded bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800">Undone</span>
          <button v-else type="button" class="btn-ghost px-2 py-1 text-xs" @click="undoing = u; reason = ''; undoError = ''"><ArrowUturnLeftIcon class="h-4 w-4" />Undo</button>
        </li>
      </ul>
      <div v-else class="card mt-3"><EmptyState title="Nothing imported yet" text="Each upload is listed here, and can be undone as a whole." compact /></div>
    </section>

    <Modal v-if="undoing" :title="`Undo ${undoing.upload_no}`" @close="undoing = null">
      <form class="space-y-4" @submit.prevent="undo">
        <p class="text-sm text-gray-700">Every entry from this upload is reversed: {{ undoing.row_count }} entries. Balances go back to what they were before it.</p>
        <div><label for="ti-reason" class="label">Why</label><input id="ti-reason" v-model="reason" class="input" /></div>
        <p v-if="undoError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ undoError }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="!reason.trim()">Undo the upload</button>
      </form>
    </Modal>
  </div>
</template>
