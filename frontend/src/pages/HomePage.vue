<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import {
  ArrowRightIcon,
  BanknotesIcon,
  BuildingLibraryIcon,
  CalculatorIcon,
  ChartPieIcon,
  CheckIcon,
  DevicePhoneMobileIcon,
  SparklesIcon,
  UserGroupIcon,
} from '@heroicons/vue/24/outline'
import { api } from '@/api'
import { useAuth } from '@/stores/auth'
import { formatMoney } from '@/money'
import SaccoMark from '@/components/SaccoMark.vue'
import ThemeSwitch from '@/components/ThemeSwitch.vue'

interface Product {
  name: string
  description: string | null
  interest_rate_monthly: string
  max_months: number
  savings_multiplier: string
  processing_fee_percent: string
  min_guarantors: number
}
interface Terms {
  sacco: { name: string; short_name: string | null; slogan: string | null; about: string | null; login_image: string | null }
  loan_products: Product[]
  min_member_months: number
  dividend_rate_percent: string
  profit_basis: string
  share_price: string
  min_shares: number
  savings_min_balance: string
  withdrawal_fee_percent: string
  membership_fee_annual: string
}

/**
 * The SACCO's public homepage: what membership gives, how it works, and a loan calculator. Every
 * figure comes from the SACCO's rules, so it stays right when the committee changes them.
 */
const auth = useAuth()
const terms = ref<Terms | null>(null)
onMounted(async () => {
  try {
    terms.value = await api.get<Terms>('/public/terms')
  } catch {
    // The page still reads well without the live figures
  }
})

const name = computed(() => terms.value?.sacco.name ?? auth.sacco?.name ?? 'Our SACCO')
const photo = computed(() => (terms.value?.sacco.login_image ? import.meta.env.BASE_URL + terms.value.sacco.login_image : null))
const main = computed(() => terms.value?.loan_products[0] ?? null)
const money = (n: number) => formatMoney(String(Math.round(n)))
const minShareCapital = computed(() => (terms.value ? terms.value.min_shares * Number(terms.value.share_price) : 0))

// --- Loan calculator (an estimate; the system works out the real schedule) ------------------
const product = ref(0)
const savings = ref(1_000_000)
const amount = ref(1_000_000)
const months = ref(12)
const chosen = computed(() => terms.value?.loan_products[product.value] ?? null)
const limit = computed(() => Math.floor(savings.value * Number(chosen.value?.savings_multiplier ?? 2)))
watch([limit, chosen], () => {
  if (amount.value > limit.value) amount.value = limit.value
  if (chosen.value && months.value > chosen.value.max_months) months.value = chosen.value.max_months
})

/** Equal monthly payments, interest each month on what is still owed (as the system does) */
const plan = computed(() => {
  const p = Math.min(amount.value, limit.value)
  const r = Number(chosen.value?.interest_rate_monthly ?? 1) / 100
  const n = months.value
  if (p <= 0 || n <= 0) return null
  const payment = r === 0 ? Math.ceil(p / n) : Math.ceil((p * r) / (1 - (1 + r) ** -n))
  let owed = p
  let interest = 0
  for (let i = 0; i < n; i++) {
    const int = Math.round(owed * r)
    interest += int
    owed -= Math.min(owed, payment - int)
  }
  const fee = Math.round((p * Number(chosen.value?.processing_fee_percent ?? 0)) / 100)
  return { payment, interest, total: p + interest, fee }
})

// --- Earnings example -----------------------------------------------------------------------
const shares = ref(10)
watch(terms, (t) => { if (t && t.min_shares > shares.value) shares.value = t.min_shares })
const dividend = computed(() => (terms.value ? shares.value * Number(terms.value.share_price) * (Number(terms.value.dividend_rate_percent) / 100) : 0))

const faqs = computed(() => {
  const t = terms.value
  if (!t) return []
  return [
    {
      q: 'Who can join?',
      a: `Staff members. You join by buying at least ${t.min_shares} shares of ${money(Number(t.share_price))} each (${money(minShareCapital.value)}) and saving every month through the payroll.`,
    },
    {
      q: 'How much can I borrow?',
      a: `Up to ${main.value?.savings_multiplier ?? 2} times your savings, less anything you already owe, once you have been saving for ${t.min_member_months} months. Colleagues who are members guarantee your loan, and they agree online.`,
    },
    {
      q: 'How is loan interest worked out?',
      a: `${main.value?.interest_rate_monthly ?? 1}% a month on what you still owe, not on the original amount. Each payment reduces what you owe, so the interest falls month by month. Paying off early saves you the interest for the months left.`,
    },
    {
      q: 'What do I earn?',
      a: `Every year a dividend of ${t.dividend_rate_percent}% on your share capital, and a share of the profit the SACCO makes on its investments, in proportion to your ${t.profit_basis === 'average_savings' ? 'average savings over the year' : 'savings'}. The more you save, the more you earn.`,
    },
    {
      q: 'Can I take out my savings?',
      a: `Yes. Ask online and pick up a cheque once it is approved. A ${t.withdrawal_fee_percent}% fee applies, at least ${money(Number(t.savings_min_balance))} stays in your account, and savings you have pledged for a colleague's loan stay until that loan is repaid.`,
    },
    {
      q: 'How do I sign in?',
      a: 'With your email or employee number. The SACCO office gives you a temporary password the first time, and you choose your own when you sign in.',
    },
  ]
})

const loginTo = computed(() => (auth.user ? (auth.isAdmin ? '/office' : '/') : '/login'))
</script>

<template>
  <div class="min-h-screen bg-paper" data-motion>
    <!-- Top bar -->
    <header class="absolute inset-x-0 top-0 z-30">
      <nav class="mx-auto flex max-w-7xl items-center gap-4 px-4 py-4 sm:px-6 lg:px-8" aria-label="Home">
        <a href="#top" class="flex items-center gap-3">
          <SaccoMark :sacco="auth.sacco" />
          <span class="text-lg font-bold text-white drop-shadow">{{ name }}</span>
        </a>
        <div class="ml-auto hidden items-center gap-1 text-sm font-medium text-white/90 md:flex">
          <a href="#benefits" class="rounded-lg px-3 py-2 hover:bg-white/10">Benefits</a>
          <a href="#how" class="rounded-lg px-3 py-2 hover:bg-white/10">How it works</a>
          <a href="#calculator" class="rounded-lg px-3 py-2 hover:bg-white/10">Loan calculator</a>
          <a href="#faq" class="rounded-lg px-3 py-2 hover:bg-white/10">Questions</a>
        </div>
        <RouterLink :to="loginTo" class="btn-accent ml-auto md:ml-3">{{ auth.user ? 'My account' : 'Sign in' }}</RouterLink>
      </nav>
    </header>

    <!-- Hero -->
    <section id="top" class="relative isolate overflow-hidden bg-navy-950">
      <img v-if="photo" :src="photo" alt="SACCO members celebrating together" class="absolute inset-0 -z-10 h-full w-full object-cover object-[60%_center]" />
      <div class="absolute inset-0 -z-10 bg-gradient-to-r from-navy-950 via-navy-950/85 to-navy-950/20" aria-hidden="true" />
      <div class="absolute inset-x-0 bottom-0 -z-10 h-40 bg-gradient-to-t from-navy-950/80 to-transparent" aria-hidden="true" />
      <div class="mx-auto max-w-7xl px-4 pb-32 pt-36 sm:px-6 sm:pt-44 lg:px-8 lg:pb-44">
        <div class="max-w-2xl text-white">
          <p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-sm font-semibold text-saffron-300 backdrop-blur">
            <SparklesIcon class="h-4 w-4" aria-hidden="true" />Savings &amp; credit cooperative
          </p>
          <h1 class="mt-5 text-4xl font-bold leading-[1.1] sm:text-6xl">{{ terms?.sacco.slogan ?? 'Save together. Grow together.' }}</h1>
          <p class="mt-5 max-w-xl text-lg text-navy-100/90">{{ terms?.sacco.about ?? 'Save a little every month, borrow at fair rates, and share in what we earn together.' }}</p>
          <div class="mt-8 flex flex-wrap gap-3">
            <RouterLink :to="loginTo" class="btn-accent px-6 py-3 text-base">{{ auth.user ? 'Go to my account' : 'Sign in to my account' }}<ArrowRightIcon class="h-5 w-5" /></RouterLink>
            <a href="#calculator" class="btn border border-white/30 bg-white/10 px-6 py-3 text-base text-white backdrop-blur hover:bg-white/20">See what I can borrow</a>
          </div>
        </div>
      </div>
    </section>

    <!-- The numbers that matter -->
    <section class="relative z-10 mx-auto -mt-20 max-w-7xl px-4 sm:px-6 lg:px-8" aria-label="At a glance">
      <div class="grid gap-px overflow-hidden rounded-3xl bg-line shadow-lift sm:grid-cols-2 lg:grid-cols-4">
        <div class="bg-surface p-6">
          <p class="text-4xl font-bold text-brand">{{ main?.interest_rate_monthly ?? 1 }}%</p>
          <p class="mt-1 font-semibold">a month on loans</p>
          <p class="mt-1 text-sm text-gray-600">On what you still owe, so it falls as you repay</p>
        </div>
        <div class="bg-surface p-6">
          <p class="text-4xl font-bold text-brand">{{ main?.savings_multiplier ?? 2 }}×</p>
          <p class="mt-1 font-semibold">your savings</p>
          <p class="mt-1 text-sm text-gray-600">The most you can borrow, guaranteed by colleagues</p>
        </div>
        <div class="bg-surface p-6">
          <p class="text-4xl font-bold text-saffron-600">{{ terms?.dividend_rate_percent ?? 40 }}%</p>
          <p class="mt-1 font-semibold">dividend every year</p>
          <p class="mt-1 text-sm text-gray-600">On your share capital</p>
        </div>
        <div class="bg-surface p-6">
          <p class="text-4xl font-bold text-saffron-600"><ChartPieIcon class="inline h-9 w-9 align-[-6px]" aria-hidden="true" /></p>
          <p class="mt-1 font-semibold">Investment profit shared</p>
          <p class="mt-1 text-sm text-gray-600">The more you save, the bigger your share</p>
        </div>
      </div>
    </section>

    <!-- Benefits -->
    <section id="benefits" class="mx-auto max-w-7xl scroll-mt-8 px-4 py-24 sm:px-6 lg:px-8">
      <div class="max-w-2xl">
        <p class="eyebrow !text-saffron-600">Why join</p>
        <h2 class="mt-2 text-3xl font-bold sm:text-4xl">Your money works for you, and for each other</h2>
        <p class="mt-3 text-lg text-gray-600">{{ name }} is owned by its members. What it earns comes back to you.</p>
      </div>
      <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <article v-for="b in [
          { icon: BuildingLibraryIcon, title: 'Save without thinking about it', text: 'Your savings and shares come straight from your payroll every month, and you can see every shilling online.' },
          { icon: BanknotesIcon, title: 'Borrow at a fair rate', text: `${main?.interest_rate_monthly ?? 1}% a month on a reducing balance, with equal monthly payments and the full schedule before you apply.` },
          { icon: SparklesIcon, title: 'A dividend every year', text: `${terms?.dividend_rate_percent ?? 40}% of your share capital, paid into your shares at the end of the year.` },
          { icon: ChartPieIcon, title: 'Share in our investments', text: 'The SACCO invests members\' money. The profit is shared every year in proportion to what each member saves.' },
          { icon: UserGroupIcon, title: 'Colleagues have your back', text: 'Ask colleagues to guarantee your loan in the app; they agree with a tap, for the amount they choose.' },
          { icon: DevicePhoneMobileIcon, title: 'Everything on your phone', text: 'Balances, statements, loan applications, withdrawals and messages from the office, any time.' },
        ]" :key="b.title" class="card group p-6 transition hover:-translate-y-0.5 hover:shadow-lift">
          <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-saffron-300 to-saffron-500 text-navy-950 shadow-sm">
            <component :is="b.icon" class="h-6 w-6" aria-hidden="true" />
          </span>
          <h3 class="mt-4 text-lg font-semibold">{{ b.title }}</h3>
          <p class="mt-2 text-gray-600">{{ b.text }}</p>
        </article>
      </div>
    </section>

    <!-- How it works -->
    <section id="how" class="scroll-mt-8 bg-navy-950 py-24 text-white">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <p class="text-xs font-semibold uppercase tracking-wider text-saffron-300">How it works</p>
        <h2 class="mt-2 max-w-2xl text-3xl font-bold sm:text-4xl">Four steps to a better tomorrow</h2>
        <ol class="mt-12 grid gap-8 md:grid-cols-4">
          <li v-for="(s, i) in [
            { t: 'Join', d: `Buy at least ${terms?.min_shares ?? 5} shares of ${money(Number(terms?.share_price ?? 40000))} each. That makes you an owner.` },
            { t: 'Save every month', d: 'Your savings come from your payroll, so they grow on their own.' },
            { t: 'Borrow when you need', d: `After ${terms?.min_member_months ?? 6} months of saving, borrow up to ${main?.savings_multiplier ?? 2}× your savings, guaranteed by colleagues.` },
            { t: 'Earn at year end', d: `A ${terms?.dividend_rate_percent ?? 40}% dividend on your shares and your share of the SACCO's investment profit.` },
          ]" :key="s.t" class="relative">
            <span class="inline-flex h-11 w-11 items-center justify-center rounded-full bg-saffron-400 text-lg font-bold text-navy-950">{{ i + 1 }}</span>
            <span v-if="i < 3" class="absolute left-14 top-5 hidden h-px w-[calc(100%-3rem)] bg-gradient-to-r from-saffron-400/60 to-transparent md:block" aria-hidden="true" />
            <h3 class="mt-4 text-lg font-semibold">{{ s.t }}</h3>
            <p class="mt-2 text-navy-100/80">{{ s.d }}</p>
          </li>
        </ol>
      </div>
    </section>

    <!-- Loan calculator and earnings -->
    <section id="calculator" class="mx-auto max-w-7xl scroll-mt-8 px-4 py-24 sm:px-6 lg:px-8">
      <div class="max-w-2xl">
        <p class="eyebrow !text-saffron-600">Try it</p>
        <h2 class="mt-2 text-3xl font-bold sm:text-4xl">What could you borrow, and what would it cost?</h2>
      </div>
      <div class="mt-10 grid gap-6 lg:grid-cols-5">
        <div class="card p-6 lg:col-span-3">
          <h3 class="flex items-center gap-2 font-semibold"><CalculatorIcon class="h-5 w-5 text-brand" aria-hidden="true" />Loan calculator</h3>
          <div v-if="terms && terms.loan_products.length > 1" class="mt-4 flex flex-wrap gap-2" role="radiogroup" aria-label="Type of loan">
            <button
              v-for="(p, i) in terms.loan_products"
              :key="p.name"
              type="button"
              role="radio"
              :aria-checked="product === i"
              class="rounded-full px-4 py-1.5 text-sm font-semibold transition"
              :class="product === i ? 'bg-brand text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
              @click="product = i"
            >{{ p.name }}</button>
          </div>
          <div class="mt-6 space-y-6">
            <div>
              <div class="flex justify-between text-sm"><label for="c-sav" class="font-medium text-gray-700">Your savings</label><span class="font-semibold tabular-nums">{{ money(savings) }}</span></div>
              <input id="c-sav" v-model.number="savings" type="range" min="100000" max="20000000" step="100000" class="mt-2 w-full accent-brand" />
            </div>
            <div>
              <div class="flex justify-between text-sm"><label for="c-amt" class="font-medium text-gray-700">Loan amount</label><span class="font-semibold tabular-nums">{{ money(Math.min(amount, limit)) }}</span></div>
              <input id="c-amt" v-model.number="amount" type="range" min="100000" :max="Math.max(100000, limit)" step="50000" class="mt-2 w-full accent-brand" />
              <p class="mt-1 text-xs text-gray-500">You could borrow up to {{ money(limit) }} ({{ chosen?.savings_multiplier ?? 2 }}× your savings)</p>
            </div>
            <div>
              <div class="flex justify-between text-sm"><label for="c-mon" class="font-medium text-gray-700">Repay over</label><span class="font-semibold">{{ months }} months</span></div>
              <input id="c-mon" v-model.number="months" type="range" min="1" :max="chosen?.max_months ?? 24" class="mt-2 w-full accent-brand" />
            </div>
          </div>
        </div>
        <div class="relative overflow-hidden rounded-2xl bg-brand p-6 text-white shadow-lift lg:col-span-2">
          <div class="absolute -right-16 -top-16 h-44 w-44 rounded-full border-2 border-saffron-400/80" aria-hidden="true" />
          <p class="text-sm text-navy-100/80">You'd pay about</p>
          <p class="mt-1 text-5xl font-bold tabular-nums">{{ plan ? money(plan.payment) : '—' }}</p>
          <p class="text-navy-100/80">a month for {{ months }} months</p>
          <dl v-if="plan" class="mt-8 space-y-2 border-t border-white/10 pt-5 text-sm">
            <div class="flex justify-between"><dt class="text-navy-100/80">Loan</dt><dd class="font-semibold tabular-nums">{{ money(Math.min(amount, limit)) }}</dd></div>
            <div class="flex justify-between"><dt class="text-navy-100/80">Total interest</dt><dd class="font-semibold tabular-nums">{{ money(plan.interest) }}</dd></div>
            <div v-if="plan.fee" class="flex justify-between"><dt class="text-navy-100/80">Processing fee</dt><dd class="font-semibold tabular-nums">{{ money(plan.fee) }}</dd></div>
            <div class="flex justify-between text-base"><dt>Total to repay</dt><dd class="font-bold tabular-nums text-saffron-300">{{ money(plan.total) }}</dd></div>
          </dl>
          <p class="mt-6 text-xs text-navy-100/60">An estimate. When you apply, the app shows your exact schedule.</p>
        </div>
      </div>

      <!-- What shares earn -->
      <div class="card mt-6 grid gap-6 p-6 md:grid-cols-2 md:items-center">
        <div>
          <h3 class="flex items-center gap-2 font-semibold"><SparklesIcon class="h-5 w-5 text-saffron-600" aria-hidden="true" />What your shares earn</h3>
          <div class="mt-4 flex justify-between text-sm"><label for="c-sh" class="font-medium text-gray-700">Shares you hold</label><span class="font-semibold">{{ shares }} × {{ money(Number(terms?.share_price ?? 40000)) }}</span></div>
          <input id="c-sh" v-model.number="shares" type="range" :min="terms?.min_shares ?? 5" max="100" class="mt-2 w-full accent-saffron-500" />
        </div>
        <div class="rounded-2xl bg-saffron-50 p-5">
          <p class="text-sm text-gray-700">A dividend of about</p>
          <p class="text-4xl font-bold tabular-nums text-ink">{{ money(dividend) }}</p>
          <p class="text-sm text-gray-700">every year at {{ terms?.dividend_rate_percent ?? 40 }}%, plus your share of the investment profit, which grows with your savings.</p>
        </div>
      </div>
    </section>

    <!-- Membership rules -->
    <section v-if="terms" class="mx-auto max-w-7xl px-4 pb-24 sm:px-6 lg:px-8">
      <div class="card grid gap-8 p-8 md:grid-cols-[1fr_1.4fr]">
        <div>
          <p class="eyebrow !text-saffron-600">Good to know</p>
          <h2 class="mt-2 text-2xl font-bold">The rules, in plain words</h2>
          <p class="mt-2 text-gray-600">Agreed by the members' committee, and the same for everyone.</p>
        </div>
        <ul class="grid gap-3 sm:grid-cols-2">
          <li v-for="r in [
            `Hold at least ${terms.min_shares} shares (${money(minShareCapital)})`,
            `Save for ${terms.min_member_months} months before your first loan`,
            `Borrow up to ${main?.savings_multiplier ?? 2}× your savings`,
            `${main?.interest_rate_monthly ?? 1}% a month on what you still owe`,
            `Keep at least ${money(Number(terms.savings_min_balance))} in savings`,
            `${terms.withdrawal_fee_percent}% fee on savings withdrawals`,
            ...(Number(terms.membership_fee_annual) > 0 ? [`A membership fee of ${money(Number(terms.membership_fee_annual))} a year`] : []),
          ]" :key="r" class="flex gap-3 text-sm">
            <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-700"><CheckIcon class="h-3.5 w-3.5" aria-hidden="true" /></span>{{ r }}
          </li>
        </ul>
      </div>
    </section>

    <!-- Questions -->
    <section id="faq" class="mx-auto max-w-3xl scroll-mt-8 px-4 pb-24 sm:px-6">
      <h2 class="text-center text-3xl font-bold">Questions members ask</h2>
      <div class="mt-10 space-y-3">
        <details v-for="f in faqs" :key="f.q" class="card group p-5 [&_summary::-webkit-details-marker]:hidden">
          <summary class="flex cursor-pointer items-center justify-between gap-4 font-semibold">
            {{ f.q }}
            <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-600 transition group-open:rotate-45" aria-hidden="true">+</span>
          </summary>
          <p class="mt-3 text-gray-600">{{ f.a }}</p>
        </details>
      </div>
    </section>

    <!-- Call to action -->
    <section class="px-4 pb-16 sm:px-6 lg:px-8">
      <div class="relative mx-auto max-w-7xl overflow-hidden rounded-3xl bg-brand px-8 py-14 text-center text-white shadow-lift">
        <div class="absolute -left-20 -top-20 h-64 w-64 rounded-full border-2 border-saffron-400/50" aria-hidden="true" />
        <div class="absolute -bottom-24 -right-16 h-72 w-72 rounded-full bg-saffron-400/10" aria-hidden="true" />
        <h2 class="relative text-3xl font-bold sm:text-4xl">{{ terms?.sacco.slogan ?? 'Save together. Grow together.' }}</h2>
        <p class="relative mx-auto mt-3 max-w-xl text-navy-100/90">Already a member? Sign in to see your savings, shares, loans and what you'll earn this year.</p>
        <RouterLink :to="loginTo" class="btn-accent relative mt-8 px-8 py-3 text-base">{{ auth.user ? 'Go to my account' : 'Sign in' }}<ArrowRightIcon class="h-5 w-5" /></RouterLink>
      </div>
    </section>

    <footer class="border-t border-line">
      <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-4 px-4 py-8 text-sm text-gray-500 sm:px-6 lg:px-8">
        <SaccoMark :sacco="auth.sacco" />
        <p>{{ name }} · {{ terms?.sacco.slogan }}</p>
        <p class="ml-auto">To join, visit the SACCO office.</p>
        <div class="w-56"><ThemeSwitch class="!bg-navy-950" /></div>
      </div>
    </footer>
  </div>
</template>
