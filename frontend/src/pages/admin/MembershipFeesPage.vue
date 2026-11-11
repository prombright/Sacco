<script setup lang="ts">
import MoneyAccountSelect from '@/components/MoneyAccountSelect.vue'
import { ask } from '@/confirm'
import { computed, onMounted, ref, watch } from 'vue'
import { ArrowDownLeftIcon, BanknotesIcon, CheckCircleIcon, ExclamationTriangleIcon, PlusIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { formatMoney } from '@/money'
import type { Report } from '@/reports'
import ReportView from '@/components/ReportView.vue'
import CountUp from '@/components/CountUp.vue'
import Modal from '@/components/Modal.vue'

interface Fee {
  id: number
  user_id: number
  full_name: string
  account_no: string
  employee_no: string | null
  year: number
  amount: string
  status: 'unpaid' | 'paid' | 'waived'
  method: 'savings' | 'cash' | 'old_system' | null
  paid_on: string | null
  reference: string | null
  note: string | null
  paid_by_name: string | null
}
interface Data {
  year: number
  fee: string
  fees: Fee[]
  totals: { charged: string; paid: string; unpaid: string; waived: string }
  counts: { paid: number; unpaid: number; waived: number }
  not_charged: number
}

/** The two fees: charge the members who owe one, then take it from savings, record cash, or waive */
const auth = useAuth()
const year = ref(new Date().getFullYear())
const kind = ref<'subscription' | 'membership'>('subscription')
const feeName = computed(() => (kind.value === 'membership' ? 'membership fee' : `${year.value} subscription`))
const data = ref<Data | null>(null)
const show = ref<'all' | 'unpaid' | 'paid' | 'waived'>('all')
const busy = ref(false)
const message = ref('')
const error = ref('')

async function load() {
  error.value = ''
  try {
    data.value = await api.get<Data>('/membership-fees', { kind: kind.value, year: year.value })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the fees.'
  }
}
onMounted(load)
watch([year, kind], load)

async function act(fn: () => Promise<string>) {
  busy.value = true
  message.value = ''
  error.value = ''
  try {
    message.value = await fn()
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'That didn\'t work.'
  } finally {
    busy.value = false
  }
}
const charge = () => act(async () => {
  const r = await api.post<{ charged: number }>('/membership-fees/charge', { kind: kind.value, year: year.value })
  return r.charged ? `${r.charged} member${r.charged === 1 ? '' : 's'} charged the ${feeName.value}. They have been told.` : 'Everyone has already been charged.'
})
const collect = () => act(async () => {
  if (!await ask(`Take the ${feeName.value} from the savings of every member who hasn't paid? Each is told.`)) return ''
  const r = await api.post<{ collected: number; not_enough_savings: string[] }>('/membership-fees/collect', { kind: kind.value, year: year.value })
  return `${r.collected} fee${r.collected === 1 ? '' : 's'} taken from savings.` + (r.not_enough_savings.length ? ` Not enough free savings: ${r.not_enough_savings.join(', ')}. Record those when they pay in cash.` : '')
})

const paidPct = computed(() => {
  const t = data.value?.totals
  return t && Number(t.charged) > 0 ? Math.round((Number(t.paid) / Number(t.charged)) * 100) : 0
})

const report = computed<Report | null>(() => data.value && {
  title: kind.value === 'membership' ? 'Membership fees' : `Subscriptions ${data.value.year}`,
  subtitle: `${formatMoney(data.value.fee)} a member · paid ${formatMoney(data.value.totals.paid)} · unpaid ${formatMoney(data.value.totals.unpaid)}`,
  columns: [
    { key: 'full_name', label: 'Member', type: 'text' },
    { key: 'account_no', label: 'Account no.', type: 'text' },
    { key: 'state', label: 'Status', type: 'text' },
    { key: 'paid_on', label: 'Paid on', type: 'date' },
    { key: 'amount', label: 'Fee', type: 'money' },
  ],
  rows: data.value.fees
    .filter((f) => show.value === 'all' || f.status === show.value)
    .map((f) => ({
      ...f,
      state: f.status === 'paid' ? `Paid${f.method === 'savings' ? ' from savings' : f.method === 'old_system' ? ' before this system' : ' in cash'}${f.reference ? ` (${f.reference})` : ''}` : f.status === 'waived' ? `Waived: ${f.note ?? ''}` : 'Unpaid',
      _id: f.id,
    })),
  totals: { amount: String(data.value.fees.filter((f) => show.value === 'all' || f.status === show.value).reduce((s, f) => s + Number(f.amount), 0)) },
})
const byId = (id: unknown) => data.value?.fees.find((f) => f.id === id)

// One member at a time
const acting = ref<{ fee: Fee; kind: 'pay' | 'waive' | 'undo' } | null>(null)
const reference = ref('')
const paidOn = ref(new Date().toLocaleDateString('en-CA'))
const reason = ref('')
const moneyAccount = ref('')
async function single() {
  const a = acting.value!
  await act(async () => {
    if (a.kind === 'pay') await api.post(`/membership-fees/${a.fee.id}/pay`, { reference: reference.value, paid_on: paidOn.value, money_account_id: moneyAccount.value })
    if (a.kind === 'waive') await api.post(`/membership-fees/${a.fee.id}/waive`, { reason: reason.value })
    if (a.kind === 'undo') await api.post(`/membership-fees/${a.fee.id}/undo`, { reason: reason.value })
    return a.kind === 'pay' ? `${a.fee.full_name}'s fee recorded as paid.` : a.kind === 'waive' ? `${a.fee.full_name}'s fee waived.` : `${a.fee.full_name}'s payment undone.`
  })
  acting.value = null
  reference.value = reason.value = ''
}
const thisYear = new Date().getFullYear()
</script>

<template>
  <div>
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <p class="eyebrow">Members</p>
        <h1 class="mt-1 text-2xl font-bold">Membership &amp; subscription</h1>
        <p class="mt-1 max-w-3xl text-gray-600">{{ kind === 'membership' ? 'The membership fee every member pays once.' : 'The subscription every member pays each year.' }} Charge it, then take it from savings or record it when paid in cash. It goes to "Membership fees" income in the books.</p>
      </div>
      <div class="flex rounded-lg bg-gray-200/60 p-0.5 text-sm font-semibold" v-if="kind === 'subscription'" role="radiogroup" aria-label="Year">
        <button v-for="y in [thisYear - 1, thisYear]" :key="y" type="button" role="radio" :aria-checked="year === y" class="rounded-md px-3 py-1.5" :class="year === y ? 'bg-surface text-ink shadow-sm' : 'text-gray-600'" @click="year = y">{{ y }}</button>
      </div>
    </div>

    <div class="mt-4 inline-flex rounded-lg bg-gray-200/60 p-0.5 text-sm font-semibold" role="radiogroup" aria-label="Which fee">
      <button v-for="k in (['subscription', 'membership'] as const)" :key="k" type="button" role="radio" :aria-checked="kind === k" class="rounded-md px-3 py-1.5" :class="kind === k ? 'bg-white shadow-sm' : 'text-gray-600'" @click="kind = k">{{ k === 'subscription' ? 'Subscription (yearly)' : 'Membership fee (once)' }}</button>
    </div>

    <div v-if="data && Number(data.fee) <= 0" class="mt-5 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
      <ExclamationTriangleIcon class="h-5 w-5 shrink-0" />
      <p>This fee isn't set yet. {{ auth.isSuperAdmin ? 'Set it' : 'A super admin sets it' }} on <RouterLink :to="{ name: 'settings' }" class="font-semibold underline">Setup &amp; rules</RouterLink> ("Membership fee every year").</p>
    </div>

    <template v-if="data">
      <!-- Where the year stands -->
      <section class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="relative overflow-hidden rounded-2xl bg-navy-900 p-5 text-white shadow-lift sm:col-span-2 xl:col-span-1">
          <div class="absolute -right-12 -top-14 h-32 w-32 rounded-full border-2 border-saffron-400/80" aria-hidden="true" />
          <p class="text-xs font-semibold uppercase tracking-wider text-navy-200">{{ kind === 'membership' ? 'Membership fee' : `${data.year} subscription` }}</p>
          <p class="mt-2 text-3xl font-bold tabular-nums">{{ formatMoney(data.fee) }}</p>
          <p class="mt-1 text-xs text-navy-200">{{ kind === 'membership' ? 'per member, paid once' : 'per member, per year' }}</p>
        </div>
        <div class="card p-5">
          <p class="eyebrow">Collected</p>
          <p class="figure mt-2 text-2xl text-emerald-700"><CountUp :value="data.totals.paid" /></p>
          <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100"><div class="fill-bar h-full rounded-full bg-emerald-600" :style="{ width: paidPct + '%' }" /></div>
          <p class="mt-1.5 text-xs text-gray-500">{{ paidPct }}% · {{ data.counts.paid }} member{{ data.counts.paid === 1 ? '' : 's' }}</p>
        </div>
        <div class="card p-5" :class="{ '!border-saffron-300 !bg-saffron-50': data.counts.unpaid }">
          <p class="eyebrow">Still unpaid</p>
          <p class="figure mt-2 text-2xl"><CountUp :value="data.totals.unpaid" :delay="150" /></p>
          <p class="mt-1 text-xs text-gray-500">{{ data.counts.unpaid }} member{{ data.counts.unpaid === 1 ? '' : 's' }}<template v-if="data.counts.waived"> · {{ data.counts.waived }} waived</template></p>
        </div>
        <div class="card flex flex-col justify-between gap-3 p-5">
          <p class="text-sm text-gray-600">
            <template v-if="data.not_charged">{{ data.not_charged }} active member{{ data.not_charged === 1 ? '' : 's' }} not yet charged{{ kind === 'membership' ? '' : ` for ${data.year}` }}.</template>
            <template v-else>Everyone has been charged{{ kind === 'membership' ? '' : ` for ${data.year}` }}.</template>
          </p>
          <div class="flex flex-wrap gap-2">
            <button v-can="'money'" type="button" class="btn-primary" :disabled="busy || !data.not_charged || Number(data.fee) <= 0" @click="charge"><PlusIcon class="h-4 w-4" />Charge {{ kind === 'membership' ? 'members' : data.year }}</button>
            <button v-can="'money'" type="button" class="btn-secondary" :disabled="busy || !data.counts.unpaid" @click="collect"><ArrowDownLeftIcon class="h-4 w-4" />Take from savings</button>
          </div>
        </div>
      </section>

      <p v-if="message" class="mt-4 flex items-center gap-2 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><CheckCircleIcon class="h-5 w-5" />{{ message }}</p>
      <p v-if="error" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ error }}</p>

      <ReportView class="mt-5" :report="report">
        <template #controls>
          <div class="flex rounded-lg bg-gray-100 p-0.5 text-xs font-semibold" role="radiogroup" aria-label="Show">
            <button v-for="s in (['all', 'unpaid', 'paid', 'waived'] as const)" :key="s" type="button" role="radio" :aria-checked="show === s" class="rounded-md px-2.5 py-1.5 capitalize" :class="show === s ? 'bg-surface text-ink shadow-sm' : 'text-gray-600'" @click="show = s">{{ s }}</button>
          </div>
        </template>
        <template #actions="{ row }">
          <template v-if="byId(row._id)?.status === 'unpaid'">
            <button v-can="'money'" type="button" class="rounded-lg px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50" @click="acting = { fee: byId(row._id)!, kind: 'pay' }"><BanknotesIcon class="mr-1 inline h-3.5 w-3.5" />Paid in cash</button>
            <button v-can="'money'" type="button" class="rounded-lg px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100" @click="acting = { fee: byId(row._id)!, kind: 'waive' }">Waive</button>
          </template>
          <button v-can="'money'" v-else-if="byId(row._id)?.status === 'paid'" type="button" class="rounded-lg px-2 py-1 text-xs font-semibold text-gray-500 hover:bg-gray-100" @click="acting = { fee: byId(row._id)!, kind: 'undo' }">Undo</button>
        </template>
        <template #empty>No fees charged yet.</template>
      </ReportView>
    </template>

    <Modal v-if="acting" :title="acting.kind === 'pay' ? 'Fee paid in cash' : acting.kind === 'waive' ? 'Waive the fee' : 'Undo the payment'" @close="acting = null">
      <form class="space-y-4" @submit.prevent="single">
        <p class="text-sm text-gray-700">{{ acting.fee.full_name }} · {{ acting.fee.year }} · {{ formatMoney(acting.fee.amount) }}</p>
        <template v-if="acting.kind === 'pay'">
          <div class="grid gap-3 sm:grid-cols-2">
            <div><label for="f-date" class="label">Paid on</label><input id="f-date" v-model="paidOn" type="date" class="input" /></div>
            <div><label for="f-ref" class="label">Receipt no.</label><input id="f-ref" v-model="reference" class="input" /></div>
          </div>
          <MoneyAccountSelect id="f-money" v-model="moneyAccount" label="Received into" />
        </template>
        <div v-else><label for="f-reason" class="label">Why</label><input id="f-reason" v-model="reason" class="input" /></div>
        <p v-if="acting.kind === 'undo' && acting.fee.method === 'savings'" class="text-xs text-gray-500">The fee goes back into their savings.</p>
        <button type="submit" class="btn-primary w-full" :disabled="busy || (acting.kind !== 'pay' && !reason)">Save</button>
      </form>
    </Modal>
  </div>
</template>
