<script setup lang="ts">
import AmountWords from '@/components/AmountWords.vue'
import MoneyAccountSelect from '@/components/MoneyAccountSelect.vue'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowUturnLeftIcon, PlusIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { formatMoney } from '@/money'
import type { Report } from '@/reports'
import ReportView from '@/components/ReportView.vue'
import Modal from '@/components/Modal.vue'

interface Entry {
  id: number
  kind: 'income' | 'expense'
  category: string
  amount: string
  entry_date: string
  description: string
  reference: string | null
  reversal_of: number | null
  reversed_by: number | null
  created_by_name: string | null
}
interface Account { id: number; code: string; name: string; type: 'income' | 'expense' }

/**
 * Payments (the SACCO's expenses) and receipts (its other income), each posted to an account in
 * the chart. Loan interest, penalties and fees are counted from the loans by themselves.
 */
const route = useRoute()
const kind = computed(() => (route.meta.kind === 'income' ? 'income' : 'expense'))
const isIncome = computed(() => kind.value === 'income')

const today = new Date().toLocaleDateString('en-CA')
const from = ref(`${today.slice(0, 4)}-01-01`)
const to = ref(today)
const entries = ref<Entry[]>([])
const accounts = ref<Account[]>([])
const busy = ref(false)
const error = ref('')

async function load() {
  busy.value = true
  error.value = ''
  try {
    const res = await api.get<{ entries: Entry[]; accounts: Account[] }>('/cashbook', { from: from.value, to: to.value })
    entries.value = res.entries
    accounts.value = res.accounts
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the entries.'
  } finally {
    busy.value = false
  }
}
onMounted(load)
watch(kind, load)

const mine = computed(() => entries.value.filter((e) => e.kind === kind.value))
const report = computed<Report>(() => ({
  title: isIncome.value ? 'Receipts (income)' : 'Payments (expenses)',
  subtitle: `${new Date(from.value).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })} to ${new Date(to.value).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}`,
  columns: [
    { key: 'entry_date', label: 'Date', type: 'date' },
    { key: 'reference', label: isIncome.value ? 'Receipt no.' : 'Voucher no.', type: 'text' },
    { key: 'category', label: 'Account', type: 'text' },
    { key: 'description', label: 'Details', type: 'text' },
    { key: 'created_by_name', label: 'Recorded by', type: 'text' },
    { key: 'amount', label: 'Amount', type: 'money' },
  ],
  rows: mine.value.map((e) => ({ ...e, description: e.description + (e.reversed_by ? ' (reversed)' : ''), _id: e.id })),
  totals: { amount: String(mine.value.reduce((s, e) => s + Number(e.amount), 0)) },
}))

// --- Recording one ---
const adding = ref(false)
const form = reactive({ account_id: '', amount: '', entry_date: today, description: '', reference: '', money_account_id: '' })
const formError = ref('')
const choices = computed(() => accounts.value.filter((a) => a.type === kind.value))
function start() {
  Object.assign(form, { account_id: '', amount: '', entry_date: today, description: '', reference: '' })
  formError.value = ''
  adding.value = true
}
async function save() {
  formError.value = ''
  try {
    await api.post('/cashbook', { ...form, kind: kind.value })
    adding.value = false
    await load()
  } catch (e) {
    formError.value = e instanceof ApiError ? e.message : 'Couldn\'t save.'
  }
}

const reversing = ref<Entry | null>(null)
const reason = ref('')
async function reverse() {
  formError.value = ''
  try {
    await api.post(`/cashbook/${reversing.value!.id}/reverse`, { reason: reason.value })
    reversing.value = null
    await load()
  } catch (e) {
    formError.value = e instanceof ApiError ? e.message : 'Couldn\'t reverse it.'
  }
}
const byId = (id: unknown) => entries.value.find((e) => e.id === id)
</script>

<template>
  <div>
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <p class="eyebrow">Cash book</p>
        <h1 class="mt-1 text-2xl font-bold">{{ isIncome ? 'Receipts (income)' : 'Payments (expenses)' }}</h1>
        <p class="mt-1 max-w-3xl text-gray-600">
          <template v-if="isIncome">Money the SACCO receives besides members' savings and loans: bank interest, membership fees, grants. Loan interest, penalties and fees are counted from the loans by themselves.</template>
          <template v-else>What the SACCO spends: stationery, bank charges, meetings, allowances. Each payment goes to an expense account in the chart.</template>
        </p>
      </div>
      <button v-can="'money'" type="button" class="btn-primary" @click="start"><PlusIcon class="h-4 w-4" />{{ isIncome ? 'Record a receipt' : 'Record a payment' }}</button>
    </div>

    <ReportView class="mt-5" :report="report" :busy="busy" :error="error">
      <template #controls>
        <div><label for="c-from" class="label">From</label><input id="c-from" v-model="from" type="date" class="input" @change="load" /></div>
        <div><label for="c-to" class="label">To</label><input id="c-to" v-model="to" type="date" class="input" @change="load" /></div>
      </template>
      <template #actions="{ row }">
        <button v-can="'money'" v-if="byId(row._id) && !byId(row._id)!.reversed_by && !byId(row._id)!.reversal_of" type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100" @click="reversing = byId(row._id)!; reason = ''">
          <ArrowUturnLeftIcon class="h-3.5 w-3.5" />Reverse
        </button>
      </template>
      <template #empty>Nothing recorded in this period.</template>
    </ReportView>

    <Modal v-if="adding" :title="isIncome ? 'Record a receipt' : 'Record a payment'" @close="adding = false">
      <form class="space-y-4" @submit.prevent="save">
        <div>
          <label for="e-acct" class="label">{{ isIncome ? 'Income account' : 'Expense account' }}</label>
          <select id="e-acct" v-model="form.account_id" class="input">
            <option value="">Choose…</option>
            <option v-for="a in choices" :key="a.id" :value="String(a.id)">{{ a.code }} · {{ a.name }}</option>
          </select>
          <p class="mt-1 text-xs text-gray-500">Need another? The super admin can add it in the chart of accounts.</p>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div><label for="e-amount" class="label">Amount</label><input id="e-amount" v-model="form.amount" inputmode="decimal" class="input tabular-nums" /><AmountWords :value="form.amount" /></div>
          <div><label for="e-date" class="label">Date</label><input id="e-date" v-model="form.entry_date" type="date" :max="today" class="input" /></div>
        </div>
        <MoneyAccountSelect id="e-money" v-model="form.money_account_id" :label="isIncome ? 'Received into' : 'Paid from'" />
        <div><label for="e-desc" class="label">Details</label><input id="e-desc" v-model="form.description" class="input" :placeholder="isIncome ? 'e.g. Bank interest for October' : 'e.g. Receipt books for the office'" /></div>
        <div><label for="e-ref" class="label">{{ isIncome ? 'Receipt no.' : 'Voucher / receipt no.' }} <span class="font-normal text-gray-500">(optional)</span></label><input id="e-ref" v-model="form.reference" class="input" /></div>
        <p v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="!form.account_id || !form.amount">Save</button>
      </form>
    </Modal>

    <Modal v-if="reversing" title="Reverse this entry" @close="reversing = null">
      <form class="space-y-4" @submit.prevent="reverse">
        <p class="text-sm text-gray-700">{{ reversing.description }}: {{ formatMoney(reversing.amount) }}. An equal and opposite entry is posted today.</p>
        <div><label for="er-reason" class="label">Why</label><input id="er-reason" v-model="reason" class="input" /></div>
        <p v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="!reason">Reverse it</button>
      </form>
    </Modal>
  </div>
</template>
