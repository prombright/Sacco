<script setup lang="ts">
import { feeText } from '@/loanFee'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/api'
import type { Installment, Loan, LoanProduct, LoanRules, PickedMember } from '@/types'
import { formatDate, formatMoney } from '@/money'
import MemberPicker from '@/components/MemberPicker.vue'

interface Quote {
  products: LoanProduct[]
  rules: LoanRules
  eligibility: { savings: string; owed: string; limit: string }
  /** How long they've saved; wait_reason is set when that's not long enough to borrow yet */
  membership: { since: string | null; months: number; wait_reason: string | null }
  /** Running loans of this type that this loan can top up, with what clearing each costs */
  top_ups: { id: number; loan_no: string; amount: string; to_clear: string }[]
  schedule: null | { installment: string; total_interest: string; total_repayable: string; processing_fee: string; you_receive: string; rows: (Installment & { balance_after: string })[] }
}

const router = useRouter()

const form = reactive({ product_id: 0, amount: '', months: 12, purpose: '', replaces_loan_id: 0 })
const guarantors = ref<(PickedMember & { amount: string })[]>([])
const quote = ref<Quote | null>(null)
const showSchedule = ref(false)
const errors = ref<Record<string, string>>({})
const error = ref('')
const busy = ref(false)

/** Typed amounts as numbers, for the live checks only - the server re-checks everything */
const toNumber = (s: string) => Number(s.replace(/[,\s]/g, '')) || 0

const amountNumber = computed(() => toNumber(form.amount))
const limit = computed(() => Number(quote.value?.eligibility.limit ?? 0))
const coverNeeded = computed(() => Math.ceil((amountNumber.value * (quote.value?.rules.guarantee_cover_percent ?? 100)) / 100))
const covered = computed(() => guarantors.value.reduce((sum, g) => sum + toNumber(g.amount), 0))
const minGuarantors = computed(() => quote.value?.rules.min_guarantors ?? 0)

const problems = computed(() => {
  const list: string[] = []
  if (amountNumber.value > limit.value) list.push(`You can borrow up to ${formatMoney(String(limit.value))}.`)
  if (guarantors.value.length < minGuarantors.value) list.push(`Choose at least ${minGuarantors.value} guarantor${minGuarantors.value === 1 ? '' : 's'}.`)
  if (covered.value < coverNeeded.value) list.push(`Guarantees must add up to ${formatMoney(String(coverNeeded.value))}; ${formatMoney(String(coverNeeded.value - covered.value))} more needed.`)
  return list
})
const ready = computed(() => amountNumber.value > 0 && form.purpose.trim() !== '' && problems.value.length === 0)

async function loadQuote() {
  try {
    quote.value = await api.get<Quote>('/loans/quote', {
      product_id: form.product_id || undefined,
      amount: form.amount.replace(/[,\s]/g, '') || undefined,
      months: form.months,
      replaces_loan_id: form.replaces_loan_id || undefined,
    })
    // The first product is chosen until the member picks another; months stay within its limit
    form.product_id ||= quote.value.rules.product_id ?? 0
    if (form.months > quote.value.rules.max_months) form.months = quote.value.rules.max_months
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t work out your loan limit.'
  }
}
let timer: ReturnType<typeof setTimeout> | undefined
watch(() => form.product_id, () => (form.replaces_loan_id = 0))
watch(() => [form.product_id, form.amount, form.months, form.replaces_loan_id], () => {
  clearTimeout(timer)
  timer = setTimeout(loadQuote, 300)
})
onMounted(loadQuote)

function addGuarantor(m: PickedMember) {
  // Suggest splitting what's still needed
  const remaining = Math.max(0, coverNeeded.value - covered.value)
  guarantors.value.push({ ...m, amount: remaining ? String(remaining) : '' })
}

async function submit() {
  errors.value = {}
  error.value = ''
  busy.value = true
  try {
    const res = await api.post<{ loan: Loan }>('/loans', {
      product_id: form.product_id,
      amount: form.amount,
      months: form.months,
      purpose: form.purpose,
      replaces_loan_id: form.replaces_loan_id || undefined,
      guarantors: guarantors.value.map((g) => ({ user_id: g.id, amount: g.amount })),
    })
    await router.replace({ name: 'loan', params: { id: res.loan.id } })
  } catch (e) {
    if (e instanceof ApiError) {
      errors.value = e.errors
      error.value = e.message
    } else {
      error.value = 'Something went wrong. Please try again.'
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-3xl">
    <RouterLink :to="{ name: 'loans' }" class="text-sm font-medium text-gray-600 hover:text-gray-900">← Loans</RouterLink>
    <h1 class="mt-3 text-2xl font-bold">Apply for a loan</h1>

    <!-- Which kind of loan -->
    <fieldset v-if="quote && quote.products.length > 1" class="mt-5">
      <legend class="label">Type of loan</legend>
      <div class="grid gap-3 sm:grid-cols-2">
        <label
          v-for="p in quote.products"
          :key="p.id"
          class="cursor-pointer rounded-2xl border bg-surface p-4 transition"
          :class="form.product_id === p.id ? 'border-brand ring-2 ring-brand/15' : 'border-line hover:border-gray-300'"
        >
          <input v-model="form.product_id" type="radio" :value="p.id" class="sr-only" />
          <span class="flex items-start justify-between gap-2">
            <span class="font-semibold">{{ p.name }}</span>
            <span class="rounded-full bg-navy-50 px-2 py-0.5 text-xs font-semibold text-navy-800">{{ p.interest_rate_monthly }}% a month</span>
          </span>
          <span v-if="p.description" class="mt-1 block text-sm text-gray-600">{{ p.description }}</span>
          <span class="mt-2 block text-xs text-gray-500">
            Up to {{ p.max_months }} months · {{ p.savings_multiplier }}× savings<template v-if="feeText(p) !== 'no fee'"> · {{ feeText(p) }}</template>
          </span>
        </label>
      </div>
    </fieldset>

    <div v-if="quote?.membership.wait_reason" class="card mt-5 p-5">
      <p class="font-medium">Not yet</p>
      <p class="mt-1 text-sm text-gray-700">{{ quote.membership.wait_reason }}</p>
      <p v-if="quote.membership.since" class="mt-2 text-sm text-gray-600">Saving since {{ formatDate(quote.membership.since) }}.</p>
      <div v-if="quote.top_ups.length" class="mt-4 space-y-2">
        <p class="text-sm font-semibold">You can top up instead</p>
        <button v-for="t in quote.top_ups" :key="t.id" type="button" class="btn-primary" @click="form.replaces_loan_id = t.id">Top up {{ t.loan_no }} ({{ formatMoney(t.to_clear) }} still to clear)</button>
      </div>
      <RouterLink v-else :to="{ name: 'loans' }" class="btn-secondary mt-4">Back to loans</RouterLink>
    </div>

    <template v-else>
    <p v-if="form.replaces_loan_id" class="mt-5 flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-navy-100 bg-navy-50/60 px-4 py-3 text-sm">
      <span>Topping up <strong class="font-semibold">{{ quote?.top_ups.find((t) => t.id === form.replaces_loan_id)?.loan_no }}</strong>: the new loan first clears the {{ formatMoney(quote?.top_ups.find((t) => t.id === form.replaces_loan_id)?.to_clear ?? '0') }} still owed on it, and you receive the rest.</span>
      <button type="button" class="font-semibold text-brand hover:underline" @click="form.replaces_loan_id = 0">Don't top up</button>
    </p>
    <div v-if="quote" class="card mt-5 grid gap-4 p-5 sm:grid-cols-3">
      <div>
        <p class="text-sm text-gray-600">You can borrow up to</p>
        <p class="mt-1 text-xl font-semibold tabular-nums">{{ formatMoney(quote.eligibility.limit) }}</p>
      </div>
      <div class="text-sm text-gray-600 sm:col-span-2">
        <p>{{ quote.rules.savings_multiplier }} × your savings ({{ formatMoney(quote.eligibility.savings) }})<template v-if="Number(quote.eligibility.owed) > 0">, less the {{ formatMoney(quote.eligibility.owed) }} you already owe</template>.</p>
        <p class="mt-1">Interest is {{ quote.rules.interest_rate_monthly }}% a month on what you still owe, so it falls as you repay.</p>
      </div>
    </div>

    <form class="mt-6 space-y-6" novalidate @submit.prevent="submit">
      <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

      <section class="card space-y-4 p-5">
        <h2 class="font-semibold">The loan</h2>
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="amount" class="label">Amount</label>
            <input id="amount" v-model="form.amount" class="input tabular-nums" inputmode="numeric" autocomplete="off" placeholder="e.g. 1,000,000" />
            <p v-if="errors.amount" class="field-error">{{ errors.amount }}</p>
          </div>
          <div>
            <label for="months" class="label">Repay over</label>
            <select id="months" v-model.number="form.months" class="input">
              <option v-for="m in quote?.rules.max_months ?? 24" :key="m" :value="m">{{ m }} month{{ m === 1 ? '' : 's' }}</option>
            </select>
          </div>
        </div>
        <div>
          <label for="purpose" class="label">What is it for?</label>
          <input id="purpose" v-model="form.purpose" class="input" maxlength="255" placeholder="e.g. School fees" />
          <p v-if="errors.purpose" class="field-error">{{ errors.purpose }}</p>
        </div>

        <div v-if="quote?.schedule" class="rounded-xl bg-gray-50 p-4">
          <div class="grid gap-3 text-sm sm:grid-cols-3">
            <div>
              <p class="text-gray-600">Monthly payment</p>
              <p class="text-lg font-semibold tabular-nums">{{ formatMoney(quote.schedule.installment) }}</p>
            </div>
            <div>
              <p class="text-gray-600">Total interest</p>
              <p class="text-lg font-semibold tabular-nums">{{ formatMoney(quote.schedule.total_interest) }}</p>
            </div>
            <div>
              <p class="text-gray-600">Total to repay</p>
              <p class="text-lg font-semibold tabular-nums">{{ formatMoney(quote.schedule.total_repayable) }}</p>
            </div>
          </div>
          <p v-if="Number(quote.schedule.processing_fee) > 0" class="mt-3 border-t border-line pt-3 text-sm text-gray-700">
            A processing fee of {{ formatMoney(quote.schedule.processing_fee) }} comes off the cheque:
            you receive <strong class="font-semibold">{{ formatMoney(quote.schedule.you_receive) }}</strong>.
          </p>
          <button type="button" class="mt-3 text-sm font-medium text-brand hover:underline" @click="showSchedule = !showSchedule">
            {{ showSchedule ? 'Hide' : 'See' }} the month-by-month schedule
          </button>
          <div v-if="showSchedule" class="mt-3 overflow-x-auto">
            <table class="w-full text-sm tabular-nums">
              <thead class="text-left text-xs uppercase tracking-wide text-gray-500">
                <tr><th class="py-1 pr-3">#</th><th class="py-1 pr-3">Due</th><th class="py-1 pr-3 text-right">Principal</th><th class="py-1 pr-3 text-right">Interest</th><th class="py-1 pr-3 text-right">Payment</th><th class="py-1 text-right">Still owed</th></tr>
              </thead>
              <tbody class="divide-y divide-gray-200">
                <tr v-for="r in quote.schedule.rows" :key="r.number">
                  <td class="py-1 pr-3">{{ r.number }}</td>
                  <td class="whitespace-nowrap py-1 pr-3">{{ formatDate(r.due_date) }}</td>
                  <td class="py-1 pr-3 text-right">{{ formatMoney(r.principal) }}</td>
                  <td class="py-1 pr-3 text-right">{{ formatMoney(r.interest) }}</td>
                  <td class="py-1 pr-3 text-right font-medium">{{ formatMoney(r.total) }}</td>
                  <td class="py-1 text-right">{{ formatMoney(r.balance_after) }}</td>
                </tr>
              </tbody>
            </table>
            <p class="mt-2 text-xs text-gray-500">Dates assume the loan is paid out today; they're fixed when it actually is.</p>
          </div>
        </div>
      </section>

      <section class="card space-y-4 p-5">
        <div>
          <h2 class="font-semibold">Guarantors</h2>
          <p class="mt-1 text-sm text-gray-600">
            Members who agree to stand behind your loan with part of their savings.
            You need {{ minGuarantors }}, and together they must cover {{ quote?.rules.guarantee_cover_percent ?? 100 }}% of the loan.
            Guarantors must have been members for at least {{ quote?.rules.guarantor_min_member_months ?? 6 }} months.
            Each will be asked on their own dashboard, and may agree to a different amount than you ask.
          </p>
        </div>

        <ul v-if="guarantors.length" class="divide-y divide-gray-100 rounded-lg border border-gray-200">
          <li v-for="(g, i) in guarantors" :key="g.id" class="flex flex-wrap items-center gap-3 p-3">
            <div class="min-w-0 flex-1">
              <p class="font-medium">{{ g.full_name }}</p>
              <p class="font-mono text-xs text-gray-500">{{ g.account_no }}</p>
            </div>
            <label class="sr-only" :for="`g-${g.id}`">Amount {{ g.full_name }} guarantees</label>
            <input :id="`g-${g.id}`" v-model="g.amount" class="input w-40 tabular-nums" inputmode="numeric" placeholder="Amount" />
            <button type="button" class="btn-ghost px-2 py-1" :aria-label="`Remove ${g.full_name}`" @click="guarantors.splice(i, 1)">Remove</button>
          </li>
        </ul>

        <MemberPicker :exclude="guarantors.map((g) => g.id)" placeholder="Type a name, or tap to pick" @pick="addGuarantor" />

        <div v-if="amountNumber > 0" class="text-sm">
          <div class="h-2 overflow-hidden rounded-full bg-gray-100" role="progressbar" :aria-valuenow="Math.min(100, Math.round((covered / (coverNeeded || 1)) * 100))" aria-valuemin="0" aria-valuemax="100">
            <div class="h-full bg-brand transition-all" :style="{ width: Math.min(100, (covered / (coverNeeded || 1)) * 100) + '%' }" />
          </div>
          <p class="mt-1 text-gray-600">Guaranteed {{ formatMoney(String(covered)) }} of {{ formatMoney(String(coverNeeded)) }}</p>
        </div>
        <p v-if="errors.guarantors" class="field-error">{{ errors.guarantors }}</p>
      </section>

      <ul v-if="amountNumber > 0 && problems.length" class="space-y-1 text-sm text-amber-800">
        <li v-for="p in problems" :key="p">· {{ p }}</li>
      </ul>

      <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <RouterLink :to="{ name: 'loans' }" class="btn-secondary">Cancel</RouterLink>
        <button type="submit" class="btn-primary" :disabled="busy || !ready">{{ busy ? 'Sending…' : 'Send application' }}</button>
      </div>
    </form>
    </template>
  </div>
</template>
