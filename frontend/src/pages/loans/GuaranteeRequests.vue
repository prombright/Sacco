<script setup lang="ts">
import { t } from '@/i18n'
import { ask } from '@/confirm'
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api'
import type { LoanStatus } from '@/types'
import { formatDate, formatMoney } from '@/money'
import { loanStatus } from '@/loans'
import StatusBadge from '@/components/StatusBadge.vue'

interface Guarantee {
  id: number
  amount: string
  requested_amount: string | null
  status: string
  created_at: string
  loan_id: number
  loan_no: string
  loan_amount: string
  months: number
  purpose: string | null
  loan_status: LoanStatus
  closed_on: string | null
  borrower_name: string
  /** Still held from their savings for this loan */
  held: string
  repaid_percent: number
}

const guarantees = ref<Guarantee[]>([])
const savings = ref('0')
const pledged = ref('0')
const freeSavings = ref('0')
// Set when they haven't been a member long enough to guarantee yet
const waitReason = ref<string | null>(null)
// What each guarantor will agree to; starts at what was asked, and they can change it
const offers = ref<Record<number, string>>({})
const busy = ref<number | null>(null)
const error = ref('')

// Requests still waiting for an answer; the rest is what they've agreed to
const pending = computed(() => guarantees.value.filter((g) => g.status === 'pending' && g.loan_status === 'pending_guarantors'))
const standing = computed(() => guarantees.value.filter((g) => g.status === 'accepted' && ['pending_approval', 'approved', 'active', 'pending_guarantors'].includes(g.loan_status)))
// Loans fully repaid in the last three months: the guarantor is cleared
const released = computed(() => guarantees.value.filter((g) => g.status === 'accepted' && g.loan_status === 'closed' && g.closed_on && Date.now() - new Date(g.closed_on).getTime() < 92 * 864e5))

async function load() {
  const res = await api.get<{ guarantees: Guarantee[]; savings: string; pledged: string; free_savings: string; wait_reason: string | null }>('/me/guarantees')
  guarantees.value = res.guarantees
  savings.value = res.savings
  pledged.value = res.pledged
  freeSavings.value = res.free_savings
  waitReason.value = res.wait_reason
  for (const g of res.guarantees) {
    offers.value[g.id] ??= String(Number(g.requested_amount ?? g.amount))
  }
}

async function answer(g: Guarantee, action: 'accept' | 'decline') {
  if (action === 'decline' && !await ask(`Decline to guarantee ${g.borrower_name}'s loan?`)) return
  error.value = ''
  busy.value = g.id
  try {
    await api.post(`/guarantees/${g.id}/${action}`, action === 'accept' ? { amount: offers.value[g.id] } : {})
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Something went wrong. Please try again.'
  } finally {
    busy.value = null
  }
}

onMounted(load)
</script>

<template>
  <div v-if="pending.length || standing.length || released.length">
    <section v-if="pending.length" class="card border-amber-200 bg-amber-50/40 p-5">
      <h2 class="font-semibold">Asked to guarantee</h2>
      <p class="mt-1 text-sm text-gray-600">
        Agreeing ties up that much of your savings until the loan is repaid. You have {{ formatMoney(freeSavings) }} free to pledge,
        and you can agree to a smaller amount than you're asked for.
      </p>
      <p v-if="waitReason" class="mt-3 rounded-lg bg-surface px-3 py-2 text-sm text-amber-900">{{ waitReason }} You can still decline.</p>
      <div v-if="error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
      <ul class="mt-4 space-y-3">
        <li v-for="g in pending" :key="g.id" class="card p-4">
          <p class="font-medium">{{ g.borrower_name }} <span class="font-normal text-gray-600">asks you to guarantee</span> <span class="tabular-nums">{{ formatMoney(g.requested_amount ?? g.amount) }}</span></p>
          <p class="mt-1 text-sm text-gray-600">
            of a {{ formatMoney(g.loan_amount) }} loan over {{ g.months }} months<template v-if="g.purpose"> for {{ g.purpose.toLowerCase() }}</template> · asked {{ formatDate(g.created_at) }}
          </p>
          <div class="mt-3 flex flex-wrap items-end gap-2">
            <div v-if="!waitReason">
              <label :for="`offer-${g.id}`" class="label">I will guarantee</label>
              <input :id="`offer-${g.id}`" v-model="offers[g.id]" class="input w-40 tabular-nums" inputmode="numeric" />
            </div>
            <button v-if="!waitReason" type="button" class="btn-primary" :disabled="busy === g.id || !offers[g.id]" @click="answer(g, 'accept')">Agree</button>
            <button type="button" class="btn-secondary" :disabled="busy === g.id" @click="answer(g, 'decline')">Decline</button>
          </div>
        </li>
      </ul>
    </section>

    <section v-if="standing.length || released.length" :class="{ 'mt-6': pending.length }">
      <h2 class="font-semibold">Loans I guarantee</h2>
      <p class="mt-1 text-sm text-gray-600">
        Nothing is taken from your savings. The part you guarantee is held: you can't withdraw it or pledge it for someone else
        until it's repaid. As each loan is repaid the hold gets smaller, and when it's fully repaid you're cleared.
      </p>
      <dl class="card mt-3 grid grid-cols-1 divide-y divide-line text-sm sm:grid-cols-3 sm:divide-x sm:divide-y-0">
        <div class="p-4">
          <dt class="text-gray-600">{{ t('My savings') }}</dt>
          <dd class="mt-0.5 text-lg font-semibold tabular-nums">{{ formatMoney(savings) }}</dd>
        </div>
        <div class="p-4">
          <dt class="text-gray-600">{{ t('Held for guarantees') }}</dt>
          <dd class="mt-0.5 text-lg font-semibold tabular-nums text-red-700">−{{ formatMoney(pledged) }}</dd>
        </div>
        <div class="p-4">
          <dt class="text-gray-600">{{ t('Free to guarantee') }}</dt>
          <dd class="mt-0.5 text-lg font-semibold tabular-nums">{{ formatMoney(freeSavings) }}</dd>
        </div>
      </dl>
      <ul v-if="standing.length" class="card mt-3 divide-y divide-line">
        <li v-for="g in standing" :key="g.id" class="p-4 text-sm">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
              <p class="font-medium">{{ g.borrower_name }}</p>
              <p class="text-gray-600">{{ g.loan_no }} · you pledged <span class="tabular-nums">{{ formatMoney(g.amount) }}</span> of a {{ formatMoney(g.loan_amount) }} loan</p>
            </div>
            <div class="text-right">
              <p class="text-xs text-gray-500">Held now</p>
              <p class="font-semibold tabular-nums text-red-700">−{{ formatMoney(g.held) }}</p>
            </div>
          </div>
          <template v-if="g.loan_status === 'active'">
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100" role="img" :aria-label="`${g.repaid_percent}% repaid`">
              <div class="h-full rounded-full bg-brand" :style="{ width: g.repaid_percent + '%' }" />
            </div>
            <p class="mt-1 text-xs text-gray-500">
              <template v-if="g.repaid_percent > 0">{{ g.repaid_percent }}% of the loan repaid, so {{ formatMoney(String(Number(g.amount) - Number(g.held))) }} of your pledge is already free.</template>
              <template v-else>Nothing repaid yet. The hold gets smaller with each repayment.</template>
            </p>
          </template>
          <p v-else class="mt-2 flex flex-wrap gap-2">
            <StatusBadge :label="loanStatus[g.loan_status].label" :style-class="loanStatus[g.loan_status].style" />
            <span class="text-xs text-gray-500">The full pledge is held until the loan is paid out and repaid.</span>
          </p>
        </li>
      </ul>
      <ul v-if="released.length" class="card mt-3 divide-y divide-line">
        <li v-for="g in released" :key="g.id" class="flex flex-wrap items-center justify-between gap-2 p-4 text-sm">
          <div>
            <p class="font-medium">{{ g.borrower_name }}</p>
            <p class="text-gray-600">{{ g.loan_no }} fully repaid on {{ formatDate(g.closed_on!) }} · your {{ formatMoney(g.amount) }} is free again</p>
          </div>
          <StatusBadge label="Cleared" style-class="bg-emerald-50 text-emerald-700" />
        </li>
      </ul>
    </section>
  </div>
</template>
