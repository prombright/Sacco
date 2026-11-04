<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ArrowUturnLeftIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { formatMoney } from '@/money'
import type { Report, ReportRow } from '@/reports'
import ReportView from '@/components/ReportView.vue'
import Modal from '@/components/Modal.vue'

interface Line { code: string; account: string; debit: string; credit: string; memo: string | null }
interface Entry {
  id: number
  entry_no: string | null
  entry_date: string
  narration: string
  reference: string | null
  auto: boolean
  source: string
  reversal_of: number | null
  reversed_by: number | null
  created_by_name: string | null
  lines: Line[]
  total: string
}
interface Account { id: number; code: string; name: string; type: string; status: string; control: boolean }

/**
 * Every journal entry in the books: the ones the system posts from deposits, loans, payroll and
 * so on, and the ones the office types in (corrections, bank charges, accruals...).
 */
const today = new Date().toLocaleDateString('en-CA')
const from = ref(`${today.slice(0, 7)}-01`)
const to = ref(today)
const kind = ref<'all' | 'manual' | 'auto'>('all')
const q = ref('')
const page = ref(1)
const data = ref<{ entries: Entry[]; total: number; per_page: number } | null>(null)
const busy = ref(false)
const error = ref('')

async function load() {
  busy.value = true
  error.value = ''
  try {
    data.value = await api.get('/accounting/journal', { from: from.value, to: to.value, kind: kind.value, q: q.value || undefined, page: page.value })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the journal.'
  } finally {
    busy.value = false
  }
}
onMounted(load)
watch([kind, page], load)
let timer: ReturnType<typeof setTimeout> | undefined
watch(q, () => {
  clearTimeout(timer)
  timer = setTimeout(() => { page.value = 1; load() }, 300)
})

const report = computed<Report | null>(() => {
  if (!data.value) return null
  const rows: ReportRow[] = []
  for (const e of data.value.entries) {
    rows.push({ date: e.entry_date, entry: e.entry_no ?? `A${e.id}`, details: e.narration + (e.reference ? ` · ${e.reference}` : ''), debit: null, credit: null, _kind: 'subtotal', _id: e.id })
    for (const l of e.lines) {
      rows.push({ date: null, entry: '', details: `  ${l.code} ${l.account}${l.memo ? ' · ' + l.memo : ''}`, debit: Number(l.debit) ? l.debit : null, credit: Number(l.credit) ? l.credit : null })
    }
  }
  return {
    title: 'Journal entries',
    subtitle: `${new Date(from.value).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })} to ${new Date(to.value).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })} · ${data.value.total} entries`,
    columns: [
      { key: 'date', label: 'Date', type: 'date' },
      { key: 'entry', label: 'Entry', type: 'text' },
      { key: 'details', label: 'Details', type: 'text' },
      { key: 'debit', label: 'Debit', type: 'money' },
      { key: 'credit', label: 'Credit', type: 'money' },
    ],
    rows,
    totals: null,
  }
})
const entryOf = (row: ReportRow) => data.value?.entries.find((e) => e.id === row._id)

// --- A new entry ---
const accounts = ref<Account[]>([])
const adding = ref(false)
const form = reactive({ entry_date: today, narration: '', reference: '', lines: [] as { account_id: string; memo: string; debit: string; credit: string }[] })
const formError = ref('')
const saving = ref(false)
async function startAdding() {
  if (!accounts.value.length) accounts.value = (await api.get<{ accounts: Account[] }>('/accounting/accounts')).accounts
  Object.assign(form, { entry_date: today, narration: '', reference: '', lines: [blank(), blank()] })
  formError.value = ''
  adding.value = true
}
const blank = () => ({ account_id: '', memo: '', debit: '', credit: '' })
const usable = computed(() => accounts.value.filter((a) => a.status === 'active' && !a.control))
const debits = computed(() => form.lines.reduce((s, l) => s + (Number(l.debit) || 0), 0))
const credits = computed(() => form.lines.reduce((s, l) => s + (Number(l.credit) || 0), 0))
const balanced = computed(() => debits.value > 0 && Math.abs(debits.value - credits.value) < 0.005)
async function save() {
  saving.value = true
  formError.value = ''
  try {
    await api.post('/accounting/journal', form)
    adding.value = false
    page.value = 1
    await load()
  } catch (e) {
    formError.value = e instanceof ApiError ? e.message : 'Couldn\'t post the entry.'
  } finally {
    saving.value = false
  }
}

// --- Reversing an entry the office typed in ---
const reversing = ref<Entry | null>(null)
const reason = ref('')
async function reverse() {
  if (!reversing.value) return
  try {
    await api.post(`/accounting/journal/${reversing.value.id}/reverse`, { reason: reason.value })
    reversing.value = null
    reason.value = ''
    await load()
  } catch (e) {
    formError.value = e instanceof ApiError ? e.message : 'Couldn\'t reverse it.'
  }
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <p class="eyebrow">Cash book</p>
        <h1 class="mt-1 text-2xl font-bold">Journal entries</h1>
        <p class="mt-1 max-w-3xl text-gray-600">Deposits, loans, repayments, withdrawals, payroll and dividends post here by themselves. Add your own entries for anything else, such as bank charges or corrections. Debits must equal credits.</p>
      </div>
      <button v-can="'money'" type="button" class="btn-primary" @click="startAdding"><PlusIcon class="h-4 w-4" />New journal entry</button>
    </div>

    <ReportView class="mt-5" :report="report" :busy="busy" :error="error">
      <template #controls>
        <div><label for="j-q" class="label">Search</label><input id="j-q" v-model="q" type="search" class="input !w-44" placeholder="Details or number" /></div>
        <div>
          <label for="j-kind" class="label">Show</label>
          <select id="j-kind" v-model="kind" class="input">
            <option value="all">All entries</option>
            <option value="manual">Typed in by the office</option>
            <option value="auto">Posted by the system</option>
          </select>
        </div>
        <div><label for="j-from" class="label">From</label><input id="j-from" v-model="from" type="date" class="input" @change="page = 1; load()" /></div>
        <div><label for="j-to" class="label">To</label><input id="j-to" v-model="to" type="date" class="input" @change="page = 1; load()" /></div>
      </template>
      <template #actions="{ row }">
        <template v-if="row._kind === 'subtotal' && entryOf(row)">
          <span v-if="entryOf(row)!.auto" class="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-semibold text-gray-600">System</span>
          <span v-else-if="entryOf(row)!.reversed_by || entryOf(row)!.reversal_of" class="rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-semibold text-amber-800">{{ entryOf(row)!.reversal_of ? 'Reversal' : 'Reversed' }}</span>
          <button v-can="'money'" v-else type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100" @click="reversing = entryOf(row)!; reason = ''">
            <ArrowUturnLeftIcon class="h-3.5 w-3.5" />Reverse
          </button>
        </template>
      </template>
    </ReportView>
    <div v-if="data && data.total > data.per_page" class="mt-3 flex items-center justify-between text-sm">
      <span class="text-gray-500">Page {{ page }} of {{ Math.ceil(data.total / data.per_page) }}</span>
      <span class="flex gap-2">
        <button type="button" class="btn-secondary" :disabled="page === 1" @click="page--">Previous</button>
        <button type="button" class="btn-secondary" :disabled="page * data.per_page >= data.total" @click="page++">Next</button>
      </span>
    </div>

    <Modal v-if="adding" title="New journal entry" @close="adding = false">
      <form class="space-y-4" @submit.prevent="save">
        <div class="grid gap-3 sm:grid-cols-3">
          <div><label for="n-date" class="label">Date</label><input id="n-date" v-model="form.entry_date" type="date" :max="today" class="input" /></div>
          <div class="sm:col-span-2"><label for="n-ref" class="label">Reference <span class="font-normal text-gray-500">(optional)</span></label><input id="n-ref" v-model="form.reference" class="input" placeholder="Voucher or bank slip no." /></div>
        </div>
        <div><label for="n-narr" class="label">Details</label><input id="n-narr" v-model="form.narration" class="input" placeholder="e.g. Bank charges for October" /></div>

        <div class="space-y-2">
          <div class="grid grid-cols-[1fr_6.5rem_6.5rem_2rem] gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500"><span>Account</span><span class="text-right">Debit</span><span class="text-right">Credit</span><span /></div>
          <div v-for="(l, i) in form.lines" :key="i" class="grid grid-cols-[1fr_6.5rem_6.5rem_2rem] items-center gap-2">
            <select v-model="l.account_id" class="input !px-2" :aria-label="`Account, line ${i + 1}`">
              <option value="">Choose…</option>
              <option v-for="a in usable" :key="a.id" :value="String(a.id)">{{ a.code }} {{ a.name }}</option>
            </select>
            <input v-model="l.debit" inputmode="decimal" class="input !px-2 text-right tabular-nums" :aria-label="`Debit, line ${i + 1}`" @input="l.debit && (l.credit = '')" />
            <input v-model="l.credit" inputmode="decimal" class="input !px-2 text-right tabular-nums" :aria-label="`Credit, line ${i + 1}`" @input="l.credit && (l.debit = '')" />
            <button type="button" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-red-700 disabled:opacity-30" :disabled="form.lines.length <= 2" :aria-label="`Remove line ${i + 1}`" @click="form.lines.splice(i, 1)"><TrashIcon class="h-4 w-4" /></button>
          </div>
          <button type="button" class="text-sm font-semibold text-brand hover:underline" @click="form.lines.push(blank())">+ Add a line</button>
          <div class="grid grid-cols-[1fr_6.5rem_6.5rem_2rem] gap-2 border-t border-line pt-2 text-sm font-semibold">
            <span>Totals</span>
            <span class="text-right tabular-nums">{{ formatMoney(String(debits)) }}</span>
            <span class="text-right tabular-nums">{{ formatMoney(String(credits)) }}</span>
          </div>
          <p class="text-sm" :class="balanced ? 'text-emerald-700' : 'text-amber-800'">
            {{ balanced ? 'Balanced.' : debits || credits ? `Out by ${formatMoney(String(Math.abs(debits - credits)))}.` : 'Enter the amounts.' }}
          </p>
          <p class="text-xs text-gray-500">Members' savings, share capital and loans aren't listed: they change only through deposits, transfers and loan repayments, so every member's account agrees with the books.</p>
        </div>
        <p v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="!balanced || !form.narration || saving">{{ saving ? 'Posting…' : 'Post the entry' }}</button>
      </form>
    </Modal>

    <Modal v-if="reversing" :title="`Reverse ${reversing.entry_no}`" @close="reversing = null">
      <form class="space-y-4" @submit.prevent="reverse">
        <p class="text-sm text-gray-700">An equal and opposite entry is posted today; the original stays in the books.</p>
        <div><label for="r-reason" class="label">Why</label><input id="r-reason" v-model="reason" class="input" /></div>
        <p v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="!reason">Reverse it</button>
      </form>
    </Modal>
  </div>
</template>
