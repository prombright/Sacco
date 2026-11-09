<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ArrowUturnLeftIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { formatMoney, transactionTypeLabel } from '@/money'
import type { Report } from '@/reports'
import { useAuth } from '@/stores/auth'
import { downloadReceiptNo } from '@/receipts'
import ReportView from '@/components/ReportView.vue'
import Modal from '@/components/Modal.vue'

interface Tx {
  id: number
  value_date: string
  full_name: string
  account_no: string
  account: string
  type: string
  amount: string
  balance_after: string
  reference: string | null
  narration: string | null
  created_by_name: string | null
  reversal_of: number | null
  reversed_by: number | null
}

/** Every savings and shares entry for every member, and reversing a mistake */
const today = new Date().toLocaleDateString('en-CA')
const from = ref(`${today.slice(0, 7)}-01`)
const to = ref(today)
const type = ref('')
const account = ref('')
const q = ref('')
const page = ref(1)
const data = ref<{ transactions: Tx[]; total: number; money_in: string; money_out: string; per_page: number } | null>(null)
const busy = ref(false)
const error = ref('')

async function load() {
  busy.value = true
  try {
    data.value = await api.get('/transactions', { from: from.value, to: to.value, type: type.value || undefined, account: account.value || undefined, q: q.value || undefined, page: page.value })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the transactions.'
  } finally {
    busy.value = false
  }
}
onMounted(load)
watch([type, account, page], load)
let timer: ReturnType<typeof setTimeout> | undefined
watch(q, () => {
  clearTimeout(timer)
  timer = setTimeout(() => { page.value = 1; load() }, 300)
})

const report = computed<Report | null>(() => data.value && {
  title: 'Transactions',
  subtitle: `${new Date(from.value).toLocaleDateString('en-GB')} to ${new Date(to.value).toLocaleDateString('en-GB')} · ${data.value.total} entries · in ${formatMoney(data.value.money_in)}, out ${formatMoney(data.value.money_out)}`,
  columns: [
    { key: 'value_date', label: 'Date', type: 'date' },
    { key: 'full_name', label: 'Member', type: 'text' },
    { key: 'account_no', label: 'Account no.', type: 'text' },
    { key: 'what', label: 'Entry', type: 'text' },
    { key: 'details', label: 'Details', type: 'text' },
    { key: 'amount', label: 'Amount', type: 'money' },
    { key: 'balance_after', label: 'Balance', type: 'money' },
  ],
  rows: data.value.transactions.map((t) => ({
    ...t,
    what: `${transactionTypeLabel[t.type] ?? t.type} · ${t.account}${t.reversed_by ? ' (reversed)' : ''}`,
    details: [t.reference, t.narration, t.created_by_name ? `by ${t.created_by_name}` : null].filter(Boolean).join(' · '),
    _id: t.id,
  })),
  totals: null,
})
const byId = (id: unknown) => data.value?.transactions.find((t) => t.id === id)
const canReverse = (t?: Tx) => !!t && !t.reversed_by && !t.reversal_of && t.type !== 'reversal' && t.type !== 'transfer'

const auth = useAuth()
const receipt = (id: unknown) => downloadReceiptNo('RCT-T' + String(id).padStart(6, '0'), { sacco: auth.sacco, user: auth.user?.full_name ?? null })
const reversing = ref<Tx | null>(null)
const reason = ref('')
const reverseError = ref('')
async function reverse() {
  reverseError.value = ''
  try {
    await api.post(`/transactions/${reversing.value!.id}/reverse`, { reason: reason.value })
    reversing.value = null
    await load()
  } catch (e) {
    reverseError.value = e instanceof ApiError ? e.message : 'Couldn\'t reverse it.'
  }
}
</script>

<template>
  <div>
    <p class="eyebrow">Administration</p>
    <h1 class="mt-1 text-2xl font-bold">Transactions &amp; reversals</h1>
    <p class="mt-1 max-w-3xl text-gray-600">Every savings and shares entry. A mistake is never deleted: it is reversed with an equal and opposite entry, and the member is told. Transfers are reversed on the Internal transfers page, payroll uploads on Payroll deductions.</p>

    <ReportView class="mt-5" :report="report" :busy="busy" :error="error">
      <template #controls>
        <div><label for="x-q" class="label">Search</label><input id="x-q" v-model="q" type="search" class="input !w-44" placeholder="Member, ref…" /></div>
        <div>
          <label for="x-type" class="label">Kind</label>
          <select id="x-type" v-model="type" class="input">
            <option value="">All</option>
            <option v-for="(label, k) in transactionTypeLabel" :key="k" :value="k">{{ label }}</option>
          </select>
        </div>
        <div>
          <label for="x-acct" class="label">Account</label>
          <select id="x-acct" v-model="account" class="input"><option value="">Both</option><option value="savings">Savings</option><option value="shares">Shares</option></select>
        </div>
        <div><label for="x-from" class="label">From</label><input id="x-from" v-model="from" type="date" class="input" @change="page = 1; load()" /></div>
        <div><label for="x-to" class="label">To</label><input id="x-to" v-model="to" type="date" class="input" @change="page = 1; load()" /></div>
      </template>
      <template #actions="{ row }">
        <button v-if="row.type !== 'reversal'" type="button" class="rounded-lg px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100" @click="receipt(row._id)">Receipt</button>
        <button v-can="'money'" v-if="canReverse(byId(row._id))" type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100" @click="reversing = byId(row._id)!; reason = ''; reverseError = ''">
          <ArrowUturnLeftIcon class="h-3.5 w-3.5" />Reverse
        </button>
      </template>
    </ReportView>
    <div v-if="data && data.total > data.per_page" class="mt-3 flex items-center justify-between text-sm">
      <span class="text-gray-500">Page {{ page }} of {{ Math.ceil(data.total / data.per_page) }}</span>
      <span class="flex gap-2">
        <button type="button" class="btn-secondary" :disabled="page === 1" @click="page--">Previous</button>
        <button type="button" class="btn-secondary" :disabled="page * data.per_page >= data.total" @click="page++">Next</button>
      </span>
    </div>

    <Modal v-if="reversing" title="Reverse this entry" @close="reversing = null">
      <form class="space-y-4" @submit.prevent="reverse">
        <p class="text-sm text-gray-700">{{ transactionTypeLabel[reversing.type] ?? reversing.type }} of {{ formatMoney(reversing.amount.replace('-', '')) }} on {{ reversing.full_name }}'s {{ reversing.account }}. An equal and opposite entry is posted today.</p>
        <div><label for="xr-reason" class="label">Why</label><input id="xr-reason" v-model="reason" class="input" /></div>
        <p v-if="reverseError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ reverseError }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="!reason">Reverse it</button>
      </form>
    </Modal>
  </div>
</template>
