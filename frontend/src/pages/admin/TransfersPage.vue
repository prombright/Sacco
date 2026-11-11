<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ArrowRightIcon, ArrowUturnLeftIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { formatDate, formatMoney } from '@/money'
import type { Balances, PickedMember } from '@/types'
import type { Report } from '@/reports'
import MemberPicker from '@/components/MemberPicker.vue'
import ReportView from '@/components/ReportView.vue'
import Modal from '@/components/Modal.vue'

interface Transfer {
  id: number
  transfer_no: string
  from_name: string
  from_account_no: string
  from_account: string
  to_name: string
  to_account_no: string
  to_account: string
  loan_no: string | null
  amount: string
  value_date: string
  reason: string
  status: 'posted' | 'reversed'
  created_by_name: string | null
  reversal_reason: string | null
}

/** Moving money between accounts without going through the bank */
const kinds = [
  { key: 'own_shares', label: 'Savings → own shares', hint: 'Buy shares with savings', from: 'savings', to: 'shares', other: false },
  { key: 'member', label: 'Savings → another member', hint: 'Into a colleague\'s savings or shares', from: 'savings', to: 'savings', other: true },
  { key: 'shares', label: 'Shares → another member', hint: 'Sell shares to a colleague', from: 'shares', to: 'shares', other: true },
  { key: 'shares_savings', label: 'Shares → own savings', hint: 'Turn shares above the minimum back into savings', from: 'shares', to: 'savings', other: false },
  { key: 'loan', label: 'Savings → a loan', hint: 'Repay a loan from savings', from: 'savings', to: 'loan', other: false },
] as const
const kind = ref<(typeof kinds)[number]['key']>('own_shares')
const chosen = computed(() => kinds.find((k) => k.key === kind.value)!)

const from = ref<PickedMember | null>(null)
const to = ref<PickedMember | null>(null)
const fromBalances = ref<Balances | null>(null)
const loans = ref<{ id: number; loan_no: string; total_left: string; borrower_name: string }[]>([])
const form = reactive({ to_account: 'savings', loan_id: '', loan_owner: 'self', amount: '', value_date: new Date().toLocaleDateString('en-CA'), reason: '' })
const error = ref('')
const done = ref('')
const busy = ref(false)

watch(from, async (m) => {
  fromBalances.value = m ? (await api.get<{ balances: Balances }>(`/members/${m.id}`)).balances : null
  loadLoans()
})
watch([to, () => form.loan_owner, kind], loadLoans)
async function loadLoans() {
  loans.value = []
  form.loan_id = ''
  if (kind.value !== 'loan') return
  const owner = form.loan_owner === 'self' ? from.value : to.value
  if (!owner) return
  const res = await api.get<{ loans: { id: number; loan_no: string; borrower_name: string; user_id: number; position: { total_left: string } | null }[] }>('/loans', { status: 'active', q: owner.account_no ?? owner.full_name })
  loans.value = res.loans.filter((l) => l.user_id === owner.id).map((l) => ({ ...l, total_left: l.position?.total_left ?? '0' }))
  if (loans.value.length === 1) form.loan_id = String(loans.value[0].id)
}

async function submit() {
  error.value = ''
  done.value = ''
  busy.value = true
  try {
    const c = chosen.value
    const recipient = c.other || (c.key === 'loan' && form.loan_owner === 'other') ? to.value : from.value
    const res = await api.post<{ transfer: Transfer }>('/transfers', {
      from_user_id: from.value?.id,
      from_account: c.from,
      to_user_id: recipient?.id,
      to_account: c.key === 'member' ? form.to_account : c.to,
      loan_id: c.key === 'loan' ? form.loan_id : undefined,
      amount: form.amount,
      value_date: form.value_date,
      reason: form.reason,
    })
    done.value = `Transfer ${res.transfer.transfer_no} done: ${formatMoney(res.transfer.amount)}. Both members have been told.`
    Object.assign(form, { amount: '', reason: '' })
    from.value = { ...from.value! }
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t make the transfer.'
  } finally {
    busy.value = false
  }
}

// --- The list ---
const list = ref<{ transfers: Transfer[]; total: number; per_page: number } | null>(null)
const page = ref(1)
async function load() {
  list.value = await api.get('/transfers', { page: page.value })
}
onMounted(load)
watch(page, load)
const report = computed<Report | null>(() => list.value && {
  title: 'Internal transfers',
  subtitle: `${list.value.total} transfers`,
  columns: [
    { key: 'no', label: 'No.', type: 'text' },
    { key: 'date', label: 'Date', type: 'date' },
    { key: 'from', label: 'From', type: 'text' },
    { key: 'to', label: 'To', type: 'text' },
    { key: 'reason', label: 'Reason', type: 'text' },
    { key: 'amount', label: 'Amount', type: 'money' },
  ],
  rows: list.value.transfers.map((t) => ({
    no: t.transfer_no,
    date: t.value_date,
    from: `${t.from_name} · ${t.from_account}`,
    to: t.to_account === 'loan' ? `Loan ${t.loan_no} (${t.to_name})` : `${t.to_name} · ${t.to_account}`,
    reason: t.status === 'reversed' ? `REVERSED: ${t.reversal_reason} (was: ${t.reason})` : t.reason,
    amount: t.amount,
    _id: t.id,
  })),
  totals: null,
})
const reversing = ref<Transfer | null>(null)
const reason = ref('')
const reverseError = ref('')
async function reverse() {
  reverseError.value = ''
  try {
    await api.post(`/transfers/${reversing.value!.id}/reverse`, { reason: reason.value })
    reversing.value = null
    await load()
  } catch (e) {
    reverseError.value = e instanceof ApiError ? e.message : 'Couldn\'t reverse it.'
  }
}
const byId = (id: unknown) => list.value?.transfers.find((t) => t.id === id)
</script>

<template>
  <div>
    <p class="eyebrow">Members</p>
    <h1 class="mt-1 text-2xl font-bold">Internal transfers</h1>
    <p class="mt-1 max-w-3xl text-gray-600">Move money between accounts without cash. The same rules as a withdrawal apply: the minimum savings stay, pledged savings stay, and members keep their minimum shares.</p>

    <section v-can="'money'" class="card mt-5 p-5">
      <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-5" role="radiogroup" aria-label="Kind of transfer">
        <button
          v-for="k in kinds"
          :key="k.key"
          type="button"
          role="radio"
          :aria-checked="kind === k.key"
          class="rounded-xl border p-3 text-left transition"
          :class="kind === k.key ? 'border-brand bg-navy-50/60 ring-2 ring-brand/15' : 'border-line hover:border-gray-300'"
          @click="kind = k.key"
        >
          <span class="block text-sm font-semibold">{{ k.label }}</span>
          <span class="block text-xs text-gray-600">{{ k.hint }}</span>
        </button>
      </div>

      <form class="mt-5 grid gap-4 lg:grid-cols-[1fr_auto_1fr]" @submit.prevent="submit">
        <!-- From -->
        <div class="space-y-2">
          <p class="label">From {{ chosen.from }} of</p>
          <div v-if="from" class="flex items-center justify-between rounded-xl border border-line p-3">
            <div>
              <p class="font-semibold">{{ from.full_name }} <span class="font-mono text-xs text-gray-500">{{ from.account_no }}</span></p>
              <p v-if="fromBalances" class="text-xs text-gray-600">Savings {{ formatMoney(fromBalances.savings) }} · Shares {{ formatMoney(fromBalances.shares) }}</p>
            </div>
            <button type="button" class="rounded-lg p-1 text-gray-500 hover:bg-gray-100" aria-label="Change member" @click="from = null"><XMarkIcon class="h-5 w-5" /></button>
          </div>
          <MemberPicker v-else placeholder="Search a member" @pick="(m) => (from = m)" />
        </div>

        <ArrowRightIcon class="hidden h-6 w-6 self-center text-gray-400 lg:block" aria-hidden="true" />

        <!-- To -->
        <div class="space-y-2">
          <template v-if="chosen.other || (kind === 'loan' && form.loan_owner === 'other')">
            <p class="label">To</p>
            <div v-if="to" class="flex items-center justify-between rounded-xl border border-line p-3">
              <p class="font-semibold">{{ to.full_name }} <span class="font-mono text-xs text-gray-500">{{ to.account_no }}</span></p>
              <button type="button" class="rounded-lg p-1 text-gray-500 hover:bg-gray-100" aria-label="Change member" @click="to = null"><XMarkIcon class="h-5 w-5" /></button>
            </div>
            <MemberPicker v-else placeholder="Search the member receiving it" :exclude="from ? [from.id] : []" @pick="(m) => (to = m)" />
          </template>
          <p v-else-if="kind === 'own_shares' || kind === 'shares_savings'" class="rounded-xl border border-dashed border-line p-3 text-sm text-gray-600">Into {{ from ? from.full_name + '\'s' : 'the same member\'s' }} {{ chosen.to }}</p>
          <div v-if="kind === 'member'" class="flex gap-2 text-sm">
            <label v-for="a in ['savings', 'shares']" :key="a" class="flex items-center gap-1.5"><input v-model="form.to_account" type="radio" :value="a" class="accent-brand" />Their {{ a }}</label>
          </div>
          <template v-if="kind === 'loan'">
            <div class="flex gap-3 text-sm">
              <label class="flex items-center gap-1.5"><input v-model="form.loan_owner" type="radio" value="self" class="accent-brand" />Their own loan</label>
              <label class="flex items-center gap-1.5"><input v-model="form.loan_owner" type="radio" value="other" class="accent-brand" />Another member's loan</label>
            </div>
            <select v-model="form.loan_id" class="input" aria-label="Loan">
              <option value="">{{ loans.length ? 'Choose the loan…' : 'No active loan found' }}</option>
              <option v-for="l in loans" :key="l.id" :value="String(l.id)">{{ l.loan_no }} · {{ formatMoney(l.total_left) }} left</option>
            </select>
          </template>
        </div>

        <div class="grid gap-4 sm:grid-cols-3 lg:col-span-3">
          <div><label for="t-amount" class="label">Amount</label><input id="t-amount" v-model="form.amount" inputmode="decimal" class="input tabular-nums" placeholder="40000" /></div>
          <div><label for="t-date" class="label">Date</label><input id="t-date" v-model="form.value_date" type="date" class="input" /></div>
          <div><label for="t-reason" class="label">Reason</label><input id="t-reason" v-model="form.reason" class="input" placeholder="e.g. Member's written request" /></div>
        </div>
        <div class="flex flex-wrap items-center gap-3 lg:col-span-3">
          <button type="submit" class="btn-primary" :disabled="busy || !from || !form.amount || !form.reason">{{ busy ? 'Moving…' : 'Make the transfer' }}</button>
          <p v-if="done" class="text-sm text-emerald-700">{{ done }}</p>
          <p v-if="error" class="text-sm text-red-700" role="alert">{{ error }}</p>
        </div>
      </form>
    </section>

    <ReportView class="mt-6" :report="report">
      <template #actions="{ row }">
        <span v-if="byId(row._id)?.status === 'reversed'" class="rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-semibold text-amber-800">Reversed</span>
        <button v-can="'money'" v-else type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100" @click="reversing = byId(row._id)!; reason = ''; reverseError = ''">
          <ArrowUturnLeftIcon class="h-3.5 w-3.5" />Reverse
        </button>
      </template>
      <template #empty>No transfers yet.</template>
    </ReportView>
    <div v-if="list && list.total > list.per_page" class="mt-3 flex justify-end gap-2">
      <button type="button" class="btn-secondary" :disabled="page === 1" @click="page--">Previous</button>
      <button type="button" class="btn-secondary" :disabled="page * list.per_page >= list.total" @click="page++">Next</button>
    </div>

    <Modal v-if="reversing" :title="`Reverse ${reversing.transfer_no}`" @close="reversing = null">
      <form class="space-y-4" @submit.prevent="reverse">
        <p class="text-sm text-gray-700">{{ formatMoney(reversing.amount) }} goes back to {{ reversing.from_name }}'s {{ reversing.from_account }} ({{ formatDate(reversing.value_date) }} transfer). Both members are told.</p>
        <div><label for="tr-reason" class="label">Why</label><input id="tr-reason" v-model="reason" class="input" /></div>
        <p v-if="reverseError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ reverseError }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="!reason">Reverse it</button>
      </form>
    </Modal>
  </div>
</template>
