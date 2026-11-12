<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue'
import { ask } from '@/confirm'
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api'
import { formatDate, formatMoney } from '@/money'
import { type Withdrawal, withdrawalStatus } from '@/withdrawals'
import StatusBadge from '@/components/StatusBadge.vue'

interface Mine {
  requests: Withdrawal[]
  savings: string
  pledged: string
  held: string
  min_balance: string
  available: string
  fee_percent: string
  fee_on_top: boolean
}

const data = ref<Mine | null>(null)
const amount = ref('')
const reason = ref('')
const error = ref('')
const busy = ref(false)

const open = computed(() => data.value?.requests.find((r) => r.status === 'pending' || r.status === 'approved'))

/** What the typed amount means: the fee, the cheque, and what leaves savings (worked out like the server) */
const quote = computed(() => {
  const a = Number(amount.value.replace(/[,\s]/g, '')) || 0
  if (!data.value || !a) return null
  const fee = Math.round((a * Number(data.value.fee_percent)) / 100)
  return {
    fee,
    cheque: data.value.fee_on_top ? a : a - fee,
    leaves: data.value.fee_on_top ? a + fee : a,
    tooMuch: a > Number(data.value.available),
  }
})

async function load() {
  data.value = await api.get<Mine>('/me/withdrawals')
}

async function submit() {
  error.value = ''
  busy.value = true
  try {
    await api.post('/withdrawals', { amount: amount.value, reason: reason.value })
    amount.value = ''
    reason.value = ''
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Something went wrong. Please try again.'
  } finally {
    busy.value = false
  }
}

async function cancel(r: Withdrawal) {
  if (!await ask(`Cancel your request to withdraw ${formatMoney(r.amount)}?`)) return
  try {
    await api.post(`/withdrawals/${r.id}/cancel`)
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t cancel the request.'
  }
}

onMounted(() => load().catch((e) => (error.value = e instanceof ApiError ? e.message : 'Couldn\'t load your withdrawals.')))
</script>

<template>
  <div class="max-w-3xl">
    <p class="eyebrow">My account</p>
    <h1 class="mt-1 text-2xl font-bold">Withdraw savings</h1>
    <p class="mt-1 text-sm text-gray-600">Ask for part of your savings. Once approved, you'll get a message to pick up your cheque.</p>

    <div v-if="data" class="card mt-5 p-5">
      <p class="text-sm text-gray-600">You can withdraw up to</p>
      <p class="mt-1 text-2xl font-semibold tabular-nums">{{ formatMoney(data.available) }}</p>
      <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm text-gray-600 sm:grid-cols-3">
        <div><dt class="inline">Savings </dt><dd class="inline tabular-nums text-gray-900">{{ formatMoney(data.savings) }}</dd></div>
        <div v-if="Number(data.pledged) > 0"><dt class="inline">Pledged for loans </dt><dd class="inline tabular-nums text-gray-900">−{{ formatMoney(data.pledged) }}</dd></div>
        <div v-if="Number(data.held) > 0"><dt class="inline">Already requested </dt><dd class="inline tabular-nums text-gray-900">−{{ formatMoney(data.held) }}</dd></div>
        <div v-if="Number(data.min_balance) > 0"><dt class="inline">Must stay in savings </dt><dd class="inline tabular-nums text-gray-900">−{{ formatMoney(data.min_balance) }}</dd></div>
      </dl>
      <p v-if="Number(data.fee_percent) > 0" class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">
        A {{ data.fee_percent }}% fee is charged on every withdrawal{{ data.fee_on_top ? ', on top of the amount' : ', taken out of the amount' }}.
      </p>
    </div>

    <form v-if="data && !open" class="card mt-4 space-y-4 p-5" novalidate @submit.prevent="submit">
      <h2 class="font-semibold">New request</h2>
      <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label for="amount" class="label">Amount</label>
          <input id="amount" v-model="amount" class="input tabular-nums" inputmode="numeric" autocomplete="off" />
        </div>
        <div>
          <label for="reason" class="label">Reason <span class="font-normal text-gray-400">(optional)</span></label>
          <input id="reason" v-model="reason" class="input" maxlength="255" />
        </div>
      </div>
      <dl v-if="quote" class="grid grid-cols-3 gap-3 rounded-xl bg-gray-50 p-3 text-sm" aria-live="polite">
        <div><dt class="text-xs text-gray-500">Fee ({{ data?.fee_percent }}%)</dt><dd class="font-semibold tabular-nums">{{ formatMoney(String(quote.fee)) }}</dd></div>
        <div><dt class="text-xs text-gray-500">Your cheque</dt><dd class="font-semibold tabular-nums">{{ formatMoney(String(quote.cheque)) }}</dd></div>
        <div><dt class="text-xs text-gray-500">Off your savings</dt><dd class="font-semibold tabular-nums">{{ formatMoney(String(quote.leaves)) }}</dd></div>
      </dl>
      <p v-if="quote?.tooMuch" class="text-sm text-amber-800">That's more than you can withdraw right now ({{ formatMoney(data!.available) }}).</p>
      <div class="flex justify-end">
        <button type="submit" class="btn-primary" :disabled="busy || !amount || quote?.tooMuch">{{ busy ? 'Sending…' : 'Send request' }}</button>
      </div>
    </form>
    <p v-else-if="open" class="mt-4 rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-700">
      You can make a new request once {{ open.request_no }} is finished.
    </p>

    <h2 class="mt-8 font-semibold">My requests</h2>
    <ul class="card mt-3 divide-y divide-gray-100">
      <li v-for="r in data?.requests ?? []" :key="r.id" class="p-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p class="font-medium tabular-nums">{{ formatMoney(r.amount) }}</p>
            <p class="text-sm text-gray-600">{{ r.request_no }} · asked {{ formatDate(r.created_at) }}<template v-if="r.reason"> · {{ r.reason }}</template></p>
          </div>
          <StatusBadge :label="withdrawalStatus[r.status].label" :style-class="withdrawalStatus[r.status].style" />
        </div>
        <p v-if="withdrawalStatus[r.status].explain" class="mt-2 text-sm text-gray-600">{{ withdrawalStatus[r.status].explain }}</p>
        <p v-if="Number(r.fee) > 0" class="mt-1 text-sm text-gray-600">Fee {{ formatMoney(r.fee) }} · cheque {{ formatMoney(r.cheque_amount) }}</p>
        <p v-if="r.status === 'paid'" class="mt-2 text-sm text-gray-600">Cheque {{ r.cheque_no }} issued {{ formatDate(r.paid_on) }}.</p>
        <p v-if="r.status === 'rejected'" class="mt-2 text-sm text-gray-600">Rejected: {{ r.decision_note }}</p>
        <div class="mt-2 flex gap-2">
          <RouterLink v-if="r.status === 'paid'" :to="{ name: 'withdrawal-slip', params: { id: r.id } }" class="btn-secondary px-3 py-1.5">Withdrawal slip</RouterLink>
          <button v-if="r.status === 'pending' || r.status === 'approved'" type="button" class="btn-secondary px-3 py-1.5" @click="cancel(r)">Cancel request</button>
        </div>
      </li>
      <li v-if="data && !data.requests.length"><EmptyState title="No withdrawal requests yet" text="When you ask to take out savings, you can follow the request here until the cheque is ready." compact /></li>
    </ul>
  </div>
</template>
