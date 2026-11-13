<script setup lang="ts">
import { feeText } from '@/loanFee'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/api'
import type { Installment, Loan, LoanProduct, LoanRules, PickedMember } from '@/types'
import { formatDate, formatMoney } from '@/money'
import MemberPicker from '@/components/MemberPicker.vue'
import UserAvatar from '@/components/UserAvatar.vue'
import AmountField from '@/components/AmountField.vue'
import CountUp from '@/components/CountUp.vue'
import Money from '@/components/Money.vue'

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
const STEPS = ['The loan', 'Guarantors', 'Check & send']
const step = ref(0)
/** Whether step i can be opened: the loan must be sensible before guarantors, and covered before sending */
function canGo(i: number) {
  if (i <= 0) return true
  const loanOk = amountNumber.value > 0 && amountNumber.value <= limit.value && form.purpose.trim() !== ''
  if (i === 1) return loanOk
  return loanOk && guarantors.value.length >= minGuarantors.value && covered.value >= coverNeeded.value
}

const maxMonths = computed(() => quote.value?.rules.max_months ?? 24)
/** The amount slider: up to the limit, in steps that suit the size of the limit */
const sliderMax = computed(() => Math.max(0, Math.floor(limit.value)))
const sliderStep = computed(() => (sliderMax.value > 5_000_000 ? 50_000 : sliderMax.value > 1_000_000 ? 10_000 : 5_000))
const slideAmount = computed({
  get: () => Math.min(amountNumber.value, sliderMax.value),
  set: (v: number) => (form.amount = String(v)),
})

/**
 * What the loan costs. The server's quote is exact; while it is on its way (or for an amount it
 * hasn't seen yet) the same reducing-balance sum is done here so the figures move with the slider.
 */
const cost = computed(() => {
  const P = amountNumber.value
  const n = Math.max(1, form.months)
  const r = Number(quote.value?.rules.interest_rate_monthly ?? 0) / 100
  const q = quote.value?.schedule
  const fresh = q && Number(q.total_repayable) - Number(q.total_interest) === P
  const monthly = fresh ? Number(q!.installment) : P <= 0 ? 0 : r > 0 ? Math.round((P * r) / (1 - (1 + r) ** -n)) : Math.round(P / n)
  const interest = fresh ? Number(q!.total_interest) : Math.max(0, monthly * n - P)
  const total = P + interest
  const fee = fresh ? Number(q!.processing_fee) : Number(q?.processing_fee ?? 0)
  return { monthly, interest, total, fee, receive: Math.max(0, P - fee), principalShare: total > 0 ? (P / total) * 100 : 100 }
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
    <fieldset v-if="quote && quote.products.length > 1 && step === 0" class="mt-5">
      <legend class="label">Type of loan</legend>
      <div class="grid grid-cols-2 gap-2 sm:gap-3">
        <label
          v-for="p in quote.products"
          :key="p.id"
          class="cursor-pointer rounded-2xl border bg-surface p-3 transition sm:p-4"
          :class="form.product_id === p.id ? 'border-brand ring-2 ring-brand/15' : 'border-line hover:border-gray-300'"
        >
          <input v-model="form.product_id" type="radio" :value="p.id" class="sr-only" />
          <span class="flex flex-col items-start gap-1 sm:flex-row sm:justify-between sm:gap-2">
            <span class="text-sm font-semibold sm:text-base">{{ p.name }}</span>
            <span class="rounded-full bg-navy-50 px-2 py-0.5 text-xs font-semibold text-navy-800">{{ p.interest_rate_monthly }}% a month</span>
          </span>
          <span v-if="p.description" class="mt-1 hidden text-sm text-gray-600 sm:block">{{ p.description }}</span>
          <span class="mt-1.5 block text-xs text-gray-500 sm:mt-2">
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
    <!-- Where you are: three short steps -->
    <ol class="mt-6 grid grid-cols-3 gap-2" aria-label="Steps">
      <li v-for="(name, i) in STEPS" :key="name">
        <button
          type="button"
          class="w-full text-left"
          :disabled="i > step && !canGo(i)"
          :aria-current="i === step ? 'step' : undefined"
          @click="i <= step || canGo(i) ? (step = i) : null"
        >
          <span class="block h-1.5 rounded-full transition-all" :class="i <= step ? 'bg-brand' : 'bg-gray-200'" />
          <span class="mt-2 block text-xs font-semibold" :class="i === step ? 'text-brand' : i < step ? 'text-ink' : 'text-gray-500'">{{ i + 1 }} · {{ name }}</span>
        </button>
      </li>
    </ol>

    <form class="mt-5 space-y-5" novalidate @submit.prevent="submit">
      <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

      <!-- Step 1: the calculator -->
      <section v-show="step === 0" class="grid gap-5 lg:grid-cols-[1.15fr_1fr]">
        <div class="card space-y-6 p-5 sm:p-6">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-semibold">How much do you need?</h2>
            <p v-if="quote" class="text-sm text-gray-600">You can borrow up to <span class="font-semibold text-ink tabular-nums">{{ formatMoney(quote.eligibility.limit) }}</span></p>
          </div>

          <div>
            <div class="flex items-end justify-between gap-3">
              <label for="amount-slide" class="label !mb-0">Amount</label>
              <p class="text-2xl font-bold tracking-tight sm:text-3xl"><Money :value="amountNumber" /></p>
            </div>
            <input
              id="amount-slide"
              v-model.number="slideAmount"
              type="range"
              class="slider mt-3 w-full"
              :min="0"
              :max="sliderMax"
              :step="sliderStep"
              :style="{ '--fill': (sliderMax ? (Math.min(amountNumber, sliderMax) / sliderMax) * 100 : 0) + '%' }"
              aria-describedby="amount-hint"
            />
            <p id="amount-hint" class="mt-1 flex justify-between text-xs text-gray-500"><span>0</span><span>{{ formatMoney(String(sliderMax)) }}</span></p>
            <details class="mt-2 text-sm">
              <summary class="cursor-pointer font-medium text-brand">Type an exact amount</summary>
              <div class="mt-2"><AmountField id="amount" v-model="form.amount" :quick="[]" :max="limit || null" max-label="The most I can" /></div>
            </details>
            <p v-if="errors.amount" class="field-error">{{ errors.amount }}</p>
            <p v-else-if="amountNumber > limit && limit > 0" class="mt-1 text-sm text-amber-800">That's more than you can borrow ({{ formatMoney(String(limit)) }}).</p>
          </div>

          <div>
            <div class="flex items-end justify-between gap-3">
              <label for="months" class="label !mb-0">Repay over</label>
              <p class="text-xl font-bold">{{ form.months }} <span class="text-sm font-medium text-gray-500">month{{ form.months === 1 ? '' : 's' }}</span></p>
            </div>
            <input
              id="months"
              v-model.number="form.months"
              type="range"
              class="slider mt-3 w-full"
              min="1"
              :max="maxMonths"
              step="1"
              :style="{ '--fill': ((form.months - 1) / Math.max(1, maxMonths - 1)) * 100 + '%' }"
            />
            <p class="mt-1 flex justify-between text-xs text-gray-500"><span>1 month</span><span>{{ maxMonths }} months</span></p>
          </div>

          <div>
            <label for="purpose" class="label">What is it for?</label>
            <input id="purpose" v-model="form.purpose" class="input" maxlength="255" placeholder="e.g. School fees" />
            <p v-if="errors.purpose" class="field-error">{{ errors.purpose }}</p>
          </div>
        </div>

        <!-- What it costs, as you move the sliders -->
        <aside class="bg-gradient-to-br from-navy-800 via-navy-900 to-navy-950 relative overflow-hidden rounded-2xl p-5 text-white shadow-lift sm:p-6 lg:sticky lg:top-24 lg:self-start" aria-live="polite">
          <p class="text-sm text-navy-100/80">Each month you pay</p>
          <p class="mt-1 text-4xl font-bold tracking-tight"><CountUp :value="cost.monthly" :duration="450" /></p>
          <p class="mt-1 text-sm text-navy-100/80">for {{ form.months }} month{{ form.months === 1 ? '' : 's' }}, off your pay</p>

          <div v-if="cost.total > 0" class="mt-5">
            <div class="flex h-3 overflow-hidden rounded-full bg-white/10" role="img" :aria-label="`Loan ${Math.round(cost.principalShare)}%, interest ${100 - Math.round(cost.principalShare)}%`">
              <div class="h-full bg-navy-200 transition-all duration-300" :style="{ width: cost.principalShare + '%' }" />
              <div class="ml-0.5 h-full flex-1 bg-saffron-400" />
            </div>
            <div class="mt-2 flex justify-between text-xs text-navy-100/80">
              <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-navy-200" />The loan</span>
              <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-saffron-400" />Interest</span>
            </div>
          </div>

          <dl class="mt-5 space-y-2 border-t border-white/10 pt-4 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-navy-100/80">Total interest</dt><dd class="font-semibold"><Money :value="cost.interest" /></dd></div>
            <div class="flex justify-between gap-3"><dt class="text-navy-100/80">Total to repay</dt><dd class="font-semibold"><Money :value="cost.total" /></dd></div>
            <div v-if="cost.fee > 0" class="flex justify-between gap-3"><dt class="text-navy-100/80">Processing fee</dt><dd class="font-semibold">−<Money :value="cost.fee" /></dd></div>
            <div class="flex justify-between gap-3 border-t border-white/10 pt-2"><dt class="font-semibold text-saffron-300">You receive</dt><dd class="text-lg font-bold"><Money :value="cost.receive" /></dd></div>
          </dl>
          <p class="mt-4 text-xs text-navy-100/70">{{ quote?.rules.interest_rate_monthly }}% a month on what you still owe, so each month's interest is a little smaller.</p>
          <button v-if="quote?.schedule" type="button" class="mt-3 text-sm font-semibold text-saffron-300 hover:underline" @click="showSchedule = !showSchedule">
            {{ showSchedule ? 'Hide' : 'See' }} the month-by-month schedule
          </button>
        </aside>

        <div v-if="showSchedule && quote?.schedule" class="card overflow-x-auto p-4 lg:col-span-2">
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
      </section>

      <!-- Step 2: guarantors -->
      <section v-show="step === 1" class="card space-y-4 p-5 sm:p-6">
        <div>
          <h2 class="font-semibold">Who will guarantee it?</h2>
          <p class="mt-1 text-sm text-gray-600">
            Colleagues who stand behind your loan with part of their savings. You need {{ minGuarantors }}, together covering
            {{ quote?.rules.guarantee_cover_percent ?? 100 }}% ({{ formatMoney(String(coverNeeded)) }}). Each is asked on their own dashboard and may agree to less.
          </p>
        </div>

        <ul v-if="guarantors.length" class="space-y-2">
          <li v-for="(g, i) in guarantors" :key="g.id" class="flex flex-wrap items-center gap-3 rounded-xl border border-line p-3">
            <UserAvatar :name="g.full_name" />
            <div class="min-w-0 flex-1">
              <p class="font-medium">{{ g.full_name }}</p>
              <p class="font-mono text-xs text-gray-500">{{ g.account_no }}</p>
            </div>
            <label class="sr-only" :for="`g-${g.id}`">Amount {{ g.full_name }} guarantees</label>
            <input :id="`g-${g.id}`" v-model="g.amount" class="input w-36 tabular-nums" inputmode="numeric" placeholder="Amount" />
            <button type="button" class="btn-ghost px-2 py-1" :aria-label="`Remove ${g.full_name}`" @click="guarantors.splice(i, 1)">Remove</button>
          </li>
        </ul>

        <MemberPicker :exclude="guarantors.map((g) => g.id)" placeholder="Type a name, or tap to pick" @pick="addGuarantor" />

        <div v-if="amountNumber > 0" class="rounded-xl bg-gray-50 p-3 text-sm">
          <div class="h-2.5 overflow-hidden rounded-full bg-gray-200" role="progressbar" :aria-valuenow="Math.min(100, Math.round((covered / (coverNeeded || 1)) * 100))" aria-valuemin="0" aria-valuemax="100">
            <div class="h-full rounded-full transition-all" :class="covered >= coverNeeded ? 'bg-emerald-600' : 'bg-brand'" :style="{ width: Math.min(100, (covered / (coverNeeded || 1)) * 100) + '%' }" />
          </div>
          <p class="mt-1.5 text-gray-700">
            <template v-if="covered >= coverNeeded && guarantors.length >= minGuarantors">✓ Fully guaranteed: {{ formatMoney(String(covered)) }}</template>
            <template v-else>Guaranteed {{ formatMoney(String(covered)) }} of {{ formatMoney(String(coverNeeded)) }}<template v-if="guarantors.length < minGuarantors"> · {{ minGuarantors - guarantors.length }} more guarantor{{ minGuarantors - guarantors.length === 1 ? '' : 's' }} needed</template></template>
          </p>
        </div>
        <p v-if="errors.guarantors" class="field-error">{{ errors.guarantors }}</p>
      </section>

      <!-- Step 3: everything on one card -->
      <section v-show="step === 2" class="card overflow-hidden">
        <div class="border-b border-line bg-navy-50/60 px-5 py-4">
          <p class="eyebrow">Your application</p>
          <p class="mt-1 text-2xl font-bold"><Money :value="amountNumber" /> <span class="text-base font-medium text-gray-600">over {{ form.months }} months</span></p>
          <p class="text-sm text-gray-600">{{ quote?.products.find((p) => p.id === form.product_id)?.name ?? 'Loan' }} · {{ form.purpose }}</p>
        </div>
        <dl class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
          <div><dt class="text-xs text-gray-500">Each month</dt><dd class="text-lg font-semibold"><Money :value="cost.monthly" /></dd></div>
          <div><dt class="text-xs text-gray-500">Total interest</dt><dd class="text-lg font-semibold"><Money :value="cost.interest" /></dd></div>
          <div><dt class="text-xs text-gray-500">Total to repay</dt><dd class="text-lg font-semibold"><Money :value="cost.total" /></dd></div>
          <div><dt class="text-xs text-gray-500">You receive</dt><dd class="text-lg font-semibold text-emerald-700"><Money :value="cost.receive" /></dd></div>
        </dl>
        <div class="border-t border-line p-5">
          <p class="text-sm font-semibold">Guarantors</p>
          <ul class="mt-2 space-y-2">
            <li v-for="g in guarantors" :key="g.id" class="flex items-center gap-3 text-sm">
              <UserAvatar :name="g.full_name" size="h-7 w-7 text-xs" />
              <span class="flex-1">{{ g.full_name }}</span>
              <span class="font-semibold tabular-nums">{{ formatMoney(String(toNumber(g.amount))) }}</span>
            </li>
          </ul>
          <p class="mt-4 text-xs text-gray-500">When you send it, each guarantor is asked on their dashboard. Once they agree, the SACCO office decides and tells you.</p>
        </div>
      </section>

      <ul v-if="step === 2 && problems.length" class="space-y-1 text-sm text-amber-800">
        <li v-for="p in problems" :key="p">· {{ p }}</li>
      </ul>

      <div class="form-actions flex flex-wrap gap-2 sm:justify-end">
        <p v-if="step === 0 && amountNumber > 0" class="w-full text-center text-sm lg:hidden">Each month <span class="font-bold"><Money :value="cost.monthly" /></span> · {{ form.months }} months</p>
        <button v-if="step > 0" type="button" class="btn-secondary max-sm:flex-1" @click="step--">Back</button>
        <RouterLink v-else :to="{ name: 'loans' }" class="btn-secondary max-sm:flex-1">Cancel</RouterLink>
        <button v-if="step < 2" type="button" class="btn-primary max-sm:flex-1" :disabled="!canGo(step + 1)" @click="step++">Next: {{ STEPS[step + 1] }}</button>
        <button v-else type="submit" class="btn-primary max-sm:flex-1" :disabled="busy || !ready">{{ busy ? 'Sending…' : 'Send application' }}</button>
      </div>
    </form>
    </template>
  </div>
</template>
