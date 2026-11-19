<script setup lang="ts">
import WelcomeTour from '@/components/WelcomeTour.vue'
import { t } from '@/i18n'
import { computed, onMounted, ref, watch } from 'vue'
import {
  ArrowRightIcon,
  CalendarDaysIcon,
  ReceiptPercentIcon,
  UsersIcon,
  ArrowDownIcon,
  ArrowUpIcon,
  ArrowsRightLeftIcon,
  ChartBarIcon,
  ArrowTrendingUpIcon,
  ChevronRightIcon,
  CircleStackIcon,
  SparklesIcon,
  Square3Stack3DIcon,
  TrophyIcon,
  WalletIcon,
  ArrowUpTrayIcon,
  BanknotesIcon,
  CheckCircleIcon,
  DocumentTextIcon,
  EyeIcon,
  EyeSlashIcon,
  IdentificationIcon,
  LockClosedIcon,
  UserCircleIcon,
  UserGroupIcon,
} from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { useTheme } from '@/stores/theme'
import type { Balances, CreditStanding, Transaction } from '@/types'
import CreditBadge from '@/components/CreditBadge.vue'
import TransactionList from '@/components/TransactionList.vue'
import NoticeBoard from '@/components/NoticeBoard.vue'
import EarningsCard from '@/components/EarningsCard.vue'
import MonthChart from '@/components/MonthChart.vue'
import CountUp from '@/components/CountUp.vue'
import { formatDate, formatMoney } from '@/money'

interface ActiveLoan {
  id: number
  loan_no: string
  amount: string
  disbursed_on: string
  paid: string
  total_left: string
  overdue: string
  next_due_date: string | null
  next_due_amount: string
}
interface Summary {
  balances: Balances
  pledged: string
  free_to_guarantee: string
  credit: CreditStanding
  share_price: string
  share_position: { shares: number | null; min_shares: number; still_needed: string }
  withdrawable: string
  loan_owed: string
  next_repayment: { date: string; amount: string } | null
  guarantee_requests: number
  dividends_total: string
  last_dividend: { amount: string; reference: string; value_date: string } | null
  recent: Transaction[]
  loans: ActiveLoan[]
  loan_limit: string
  loan_wait_reason: string | null
  saved_this_year: string
  membership_fee: { year: number; amount: string; status: 'unpaid' | 'paid' | 'waived'; method: string | null; paid_on: string | null } | null
  joining_fee: { amount: string; status: 'unpaid' | 'paid' | 'waived' } | null
  trend: { savings: { month: string; value: string }[]; shares: { month: string; value: string }[] }
}

/** The member's home: what they have, what they can borrow, their loans, and how it's growing */
const auth = useAuth()
const theme = useTheme()
const data = ref<Summary | null>(null)
const error = ref('')

onMounted(async () => {
  try {
    data.value = await api.get<Summary>('/me/summary')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load your balances.'
  }
})

const hour = new Date().getHours()
const greeting = computed(() => t(hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening'))
const firstName = computed(() => auth.user?.full_name.split(' ')[0] ?? '')

// Hide the amounts on a shared screen; remembered on this device
const hidden = ref(false)
try {
  hidden.value = localStorage.getItem('sacco-hide-amounts') === '1'
} catch {
  // Private window: start shown
}
watch(hidden, (h) => {
  try {
    localStorage.setItem('sacco-hide-amounts', h ? '1' : '0')
  } catch {
    // Not saved; fine
  }
})
const show = (v: string | number) => (hidden.value ? '••••••' : formatMoney(String(v)))

/** How much an account grew over the chart's period, in percent (null when it can't be said) */
function change(key: 'savings' | 'shares'): number | null {
  const v = (data.value?.trend[key] ?? []).slice(-range.value).map((p) => Number(p.value))
  const first = v.find((n) => n > 0)
  const last = v[v.length - 1]
  if (!first || last === undefined) return null
  return Math.round(((last - first) / first) * 1000) / 10
}
/** Computers: the account at a glance */
const glance = computed(() => {
  const d = data.value
  const since = auth.user?.joined_on ?? auth.user?.created_at ?? null
  return [
    { label: 'Account Number', value: auth.user?.account_no ?? '-', icon: UsersIcon },
    { label: 'Member Since', value: since ? formatDate(since.slice(0, 10)) : '-', icon: CalendarDaysIcon },
    { label: 'Dividends so far', value: d ? show(d.dividends_total) : '-', icon: ReceiptPercentIcon },
    { label: 'Savings This Year', value: d ? show(d.saved_this_year) : '-', icon: ChartBarIcon },
  ]
})
/** Phones: the three latest entries, named the way a member reads them */
const recentPhone = computed(() => (data.value?.recent ?? []).filter((x) => !x.in_error).slice(0, 3))
const txKind: Record<string, string> = { deposit: 'Deposit', withdrawal: 'Withdrawal', dividend: 'Dividend', adjustment: 'Adjustment', reversal: 'Reversal', opening_balance: 'Opening balance' }
const txTitle = (x: Transaction) => `${x.account === 'shares' ? 'Share Capital' : 'Savings'} ${txKind[x.type] ?? ''}`.trim()
const txWhen = (x: Transaction) => new Date((x.created_at || x.value_date).replace(' ', 'T')).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true }).replace(' at ', ', ')

const savings = computed(() => Number(data.value?.balances.savings ?? 0))
const shares = computed(() => Number(data.value?.balances.shares ?? 0))
const total = computed(() => savings.value + shares.value)

// Validated for colour-blind separation and contrast on each theme's surface
const colors = computed(() => theme.chart)
const series = ref<'savings' | 'shares'>('savings')
const range = ref<6 | 12>(12)
const points = computed(() => (data.value ? data.value.trend[series.value].slice(-range.value) : []))
const growth = computed(() => {
  const p = points.value
  if (p.length < 2) return 0
  return Number(p[p.length - 1].value) - Number(p[0].value)
})

/** When the chart is shorter than the period chosen, its records start at the first month shown */
const sinceLabel = computed(() =>
  points.value.length ? new Date(points.value[0].month + '-01T00:00:00').toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }) : '',
)

function daysUntil(date: string) {
  const d = Math.round((new Date(date + 'T00:00:00').getTime() - new Date(new Date().toDateString()).getTime()) / 86400000)
  return d < 0 ? `${-d} day${d === -1 ? '' : 's'} late` : d === 0 ? 'due today' : d === 1 ? 'due tomorrow' : `in ${d} days`
}
const progress = (l: ActiveLoan) => {
  const paid = Number(l.paid)
  const all = paid + Number(l.total_left)
  return all > 0 ? Math.round((paid / all) * 100) : 0
}
const ring = 2 * Math.PI * 34

// A little fun with the numbers: a level for what's saved, and badges along the way
const LEVELS = [
  { name: 'Seedling', from: 0, tone: 'from-emerald-400 to-emerald-700' },
  { name: 'Bronze saver', from: 500_000, tone: 'from-amber-500 to-amber-800' },
  { name: 'Silver saver', from: 2_000_000, tone: 'from-slate-400 to-slate-600' },
  { name: 'Gold saver', from: 5_000_000, tone: 'from-saffron-400 to-saffron-600' },
  { name: 'Platinum saver', from: 10_000_000, tone: 'from-sky-400 to-sky-700' },
  { name: 'Diamond saver', from: 25_000_000, tone: 'from-violet-400 to-violet-700' },
]
const levelIndex = computed(() => LEVELS.reduce((best, l, i) => (total.value >= l.from ? i : best), 0))
const level = computed(() => LEVELS[levelIndex.value])
const nextLevel = computed(() => LEVELS[levelIndex.value + 1] ?? null)
const levelProgress = computed(() => (nextLevel.value ? Math.min(100, ((total.value - level.value.from) / (nextLevel.value.from - level.value.from)) * 100) : 100))
const badges = computed(() => {
  const d = data.value!
  return [
    { label: 'First savings', earned: savings.value > 0 },
    { label: 'Full shareholder', earned: d.share_position.min_shares > 0 && Number(d.share_position.still_needed) <= 0 },
    { label: 'Dividend earner', earned: Number(d.dividends_total) > 0 },
    { label: 'Colleague\'s guarantor', earned: Number(d.pledged) > 0 },
    { label: 'Loan on track', earned: d.loans.length > 0 && d.loans.every((l) => Number(l.overdue) <= 0) },
    { label: 'Fee paid', earned: d.membership_fee?.status === 'paid' },
  ]
})

const actions = computed(() => [
  { label: t('Apply for a loan'), short: t('Apply for Loan'), to: { name: 'loan-apply' }, icon: BanknotesIcon },
  { label: t('Withdraw savings'), short: t('Withdraw Savings'), to: { name: 'withdrawals' }, icon: ArrowUpTrayIcon },
  { label: t('My statement'), short: t('Statement'), to: { name: 'statement' }, icon: DocumentTextIcon },
  { label: t('My profile'), short: t('My Profile'), to: { name: 'profile' }, icon: UserCircleIcon },
])
/** The first login welcome, once */
const welcome = ref(true)
</script>

<template>
  <div>
    <!-- The top. Phones: a violet header (it carries on from the top bar) with the greeting in white,
         and the quick actions on a white panel over its lower edge. Wider screens: plain. -->
    <section class="member-hero relative">
      <svg class="member-hero-wave sm:hidden" viewBox="0 0 400 120" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0 80C110 40 220 120 400 30V120H0Z" fill="#7a5cf7" opacity="0.45" />
        <path d="M120 120C220 70 300 90 400 60V120Z" fill="#8f74fa" opacity="0.35" />
      </svg>
      <svg class="pointer-events-none absolute -right-6 -top-8 hidden h-48 w-[36rem] lg:block" viewBox="0 0 540 180" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0 180C120 150 200 60 330 70C420 78 480 30 540 0V180Z" fill="#7c5cf5" opacity="0.08" />
        <path d="M120 180C230 150 300 110 400 120C470 126 510 100 540 80V180Z" fill="#7c5cf5" opacity="0.07" />
      </svg>
      <div class="relative">
        <p class="text-[0.95rem] font-medium text-[#6246ea] max-sm:!text-white/90 dark:text-[#b9a8ff] sm:text-lg">{{ t('My account') }}: <span class="font-bold">{{ auth.user?.account_no }}</span></p>
        <h1 class="mt-0.5 text-[1.55rem] font-extrabold leading-tight tracking-[-0.015em] text-[#16133a] max-sm:!text-white dark:text-white sm:text-[2.2rem]">{{ greeting }}, {{ firstName }}</h1>
        <p class="mt-1 text-sm text-white/85 sm:text-[0.95rem] sm:text-gray-600 sm:dark:text-gray-400">Manage your savings, loans and SACCO services<span class="max-sm:hidden"> in one place</span>.</p>
      </div>
    </section>
    <nav class="quick-actions mt-3 max-sm:relative max-sm:z-[1] max-sm:-mt-16 max-sm:rounded-[1.6rem] max-sm:bg-surface max-sm:p-3 max-sm:shadow-lift" aria-label="Quick actions">
      <RouterLink v-for="(a, i) in actions" :key="a.label" :to="a.to" class="pill-action" :class="{ 'pill-action-main': i === 0 }">
        <component :is="a.icon" aria-hidden="true" /><span><span class="sm:hidden">{{ a.short }}</span><span class="hidden sm:inline">{{ a.label }}</span></span>
      </RouterLink>
    </nav>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <WelcomeTour
      v-if="data && welcome && auth.user && !auth.isAdmin && !auth.user.welcomed && !auth.user.must_change_password"
      :savings="data.balances.savings"
      :shares="data.balances.shares"
      :withdrawable="data.withdrawable"
      :loan-limit="data.loan_limit"
      :wait-reason="data.loan_wait_reason"
      @done="welcome = false"
    />

    <template v-if="data">
      <!-- What I have, and what I can borrow -->
      <section class="mt-6 grid gap-4 lg:grid-cols-[1.3fr_1fr_1fr]" aria-label="Balances">
        <div class="hero-glass reveal relative min-w-0 overflow-hidden rounded-3xl p-5 text-white shadow-lift sm:p-8 lg:p-6">
          <div class="relative flex items-start justify-between gap-4">
            <div>
              <p class="text-sm text-white/85 sm:text-base">{{ t('My money in the SACCO') }}</p>
              <p class="mt-1 text-[2.4rem] font-extrabold leading-tight tracking-[-0.01em] sm:text-[3.4rem] lg:text-[2.6rem] 2xl:text-[3rem]"><CountUp :value="total" :format="show" :duration="1500" /></p>
            </div>
            <div class="flex items-start gap-2">
              <WalletIcon class="hidden h-16 w-16 text-white/25 sm:block" aria-hidden="true" />
              <button
                type="button"
                class="rounded-full bg-white/10 p-2 text-white/90 backdrop-blur transition hover:bg-white/20"
                :aria-label="hidden ? 'Show amounts' : 'Hide amounts'"
                :aria-pressed="hidden"
                @click="hidden = !hidden"
              >
                <component :is="hidden ? EyeIcon : EyeSlashIcon" class="h-5 w-5" />
              </button>
            </div>
          </div>

          <!-- phones: a stack of coins beside the figure -->
          <svg class="pointer-events-none absolute right-5 top-[4.4rem] h-16 w-24 sm:hidden" viewBox="0 0 96 64" aria-hidden="true">
            <g v-for="(c, i) in [[18, 46], [18, 38], [18, 30], [52, 50], [52, 42], [52, 34], [52, 26], [52, 18]]" :key="i">
              <ellipse :cx="c[0] + 13" :cy="c[1] + 4" rx="14" ry="5" fill="#5b3fd6" />
              <rect :x="c[0] - 1" :y="c[1] - 4" width="28" height="8" fill="#6d52e8" />
              <ellipse :cx="c[0] + 13" :cy="c[1] - 4" rx="14" ry="5" fill="#a28dff" />
            </g>
          </svg>

          <!-- Savings and share capital, side by side -->
          <div class="relative mt-5 grid grid-cols-2 border-t border-white/15 pt-5">
            <div class="flex min-w-0 items-start gap-3 pr-3">
              <WalletIcon class="h-8 w-8 shrink-0 text-[#38e1f5] sm:h-10 sm:w-10" aria-hidden="true" />
              <div class="min-w-0">
                <p class="text-sm text-white/80">{{ t('Savings') }}</p>
                <p class="whitespace-nowrap text-lg font-extrabold sm:text-2xl lg:text-xl"><CountUp :value="data.balances.savings" :format="show" :delay="200" /></p>
                <p v-if="Number(data.pledged) > 0" class="mt-1 text-xs text-white/75">
                  <span class="font-semibold text-white">−{{ show(data.pledged) }}</span> {{ t('held for loans and guarantees') }}<br />
                  <span class="font-semibold text-white">{{ show(data.free_to_guarantee) }}</span> {{ t('free to guarantee') }}
                </p>
              </div>
            </div>
            <div class="flex min-w-0 items-start gap-3 border-l border-white/15 pl-3 sm:pl-8">
              <CircleStackIcon class="h-7 w-7 shrink-0 text-saffron-400 sm:h-10 sm:w-10" aria-hidden="true" />
              <div class="min-w-0">
                <p class="text-sm text-white/80">{{ t('Share capital') }}</p>
                <p class="whitespace-nowrap text-lg font-extrabold sm:text-2xl lg:text-xl"><CountUp :value="data.balances.shares" :format="show" :delay="350" /></p>
                <p v-if="data.share_position.min_shares > 0 && data.share_position.shares !== null" class="mt-0.5 hidden items-center gap-1 text-xs sm:flex" :class="Number(data.share_position.still_needed) > 0 ? 'text-saffron-300' : 'text-white/75'">
                  <CheckCircleIcon v-if="Number(data.share_position.still_needed) <= 0" class="h-3.5 w-3.5" aria-hidden="true" />
                  {{ data.share_position.shares }} share{{ data.share_position.shares === 1 ? '' : 's' }}<template v-if="Number(data.share_position.still_needed) > 0"> · {{ show(data.share_position.still_needed) }} more to reach {{ data.share_position.min_shares }}</template><template v-else> · minimum of {{ data.share_position.min_shares }} reached</template>
                </p>
              </div>
            </div>
          </div>

        </div>

        <!-- Phones: what to pay next, straight after the money -->
        <RouterLink
          v-for="l in data.loans.slice(0, 2)"
          :key="'next' + l.id"
          :to="{ name: 'loan', params: { id: l.id } }"
          class="card flex items-center gap-4 p-4 sm:hidden"
        >
          <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl" :class="Number(l.overdue) > 0 ? 'bg-red-50 text-red-700' : 'bg-navy-50 text-navy-800'"><BanknotesIcon class="h-5 w-5" aria-hidden="true" /></span>
          <span class="min-w-0 flex-1">
            <span class="block text-xs text-gray-600">{{ l.loan_no }} · next payment</span>
            <span class="block font-semibold tabular-nums">{{ l.next_due_date ? show(l.next_due_amount) : 'Fully repaid' }}</span>
            <span v-if="l.next_due_date" class="block text-xs" :class="Number(l.overdue) > 0 ? 'font-semibold text-red-700' : 'text-gray-600'">{{ daysUntil(l.next_due_date) }} · {{ show(l.total_left) }} left</span>
          </span>
          <ArrowRightIcon class="h-4 w-4 text-gray-400" aria-hidden="true" />
        </RouterLink>

        <!-- Borrowing power on phones: the amount, big, with a money bag -->
        <RouterLink :to="data.loan_wait_reason ? { name: 'loans' } : { name: 'loan-apply' }" class="card relative flex items-center gap-3 overflow-hidden p-4 sm:hidden">
          <span class="icon-gold inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl"><Square3Stack3DIcon class="h-6 w-6" aria-hidden="true" /></span>
          <span class="min-w-0 flex-1 pr-16">
            <span class="block text-[1.05rem] font-bold">Borrowing Power</span>
            <template v-if="!data.loan_wait_reason">
              <span class="block text-[13px] text-gray-600">You can apply for a loan of up to</span>
              <span class="block text-[1.35rem] font-extrabold tabular-nums text-[#5b3ff0] dark:text-[#b9a8ff]">{{ show(data.loan_limit) }}</span>
            </template>
            <span v-else class="block text-[13px] text-gray-600">{{ data.loan_wait_reason }}</span>
          </span>
          <svg class="pointer-events-none absolute bottom-2 right-9 h-14 w-14" viewBox="0 0 56 56" aria-hidden="true">
            <path d="M20 10h16l-3 7H23z" fill="#9b84fb" />
            <path d="M23 17h10c9 6 15 14 15 23 0 8-8 12-20 12S8 48 8 40c0-9 6-17 15-23z" fill="#8a70f7" />
            <text x="28" y="40" text-anchor="middle" font-size="10" font-weight="700" fill="#fff">UGX</text>
          </svg>
          <ChevronRightIcon class="absolute right-3 top-3 h-5 w-5 text-gray-400" aria-hidden="true" />
        </RouterLink>
        <div class="reveal card hidden min-w-0 flex-col p-6 sm:flex lg:p-5" style="--i: 1">
          <h2 class="flex items-center gap-3 text-[1.15rem] font-bold"><span class="icon-gold inline-flex h-10 w-10 items-center justify-center rounded-xl"><Square3Stack3DIcon class="h-5 w-5" aria-hidden="true" /></span>{{ t('Borrowing power') }}</h2>
          <template v-if="!data.loan_wait_reason">
            <p class="mt-3 text-sm text-gray-600">You can apply for a loan of up to</p>
            <p class="text-[2rem] font-extrabold leading-tight tabular-nums text-[#5b3ff0] dark:text-[#b9a8ff]"><CountUp :value="data.loan_limit" :format="show" :delay="300" /></p>
            <div class="mt-4 h-2 overflow-hidden rounded-full bg-gray-100" aria-hidden="true">
              <div class="fill-bar h-full rounded-full bg-brand" :style="{ width: Math.min(100, (Number(data.loan_owed) / (Number(data.loan_owed) + Number(data.loan_limit) || 1)) * 100) + '%' }" />
            </div>
            <p class="mt-2 text-xs text-gray-500">
              <template v-if="Number(data.loan_owed) > 0">{{ show(data.loan_owed) }} already owed on your loan{{ data.loans.length === 1 ? '' : 's' }}.</template>
              <template v-else>Based on your savings, with colleagues as guarantors.</template>
            </p>
            <div class="mt-4 rounded-xl border border-line p-3">
              <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-medium text-gray-600">{{ t('Credit standing') }}</span>
                <CreditBadge :credit="data.credit" />
              </div>
              <p v-if="data.credit.grade !== 'good'" class="mt-1.5 text-xs max-sm:line-clamp-3" :class="data.credit.grade === 'poor' || data.credit.overdue_now ? 'text-red-700' : 'text-gray-600'">{{ data.credit.advice }}</p>
              <p v-else class="mt-1.5 text-xs text-gray-600">{{ data.credit.on_time }} of your last {{ data.credit.due }} instalments paid on time.</p>
            </div>
            <RouterLink :to="{ name: 'loan-apply' }" class="btn-primary mt-auto w-full !mt-5 !rounded-full !py-3">Apply for a Loan<ArrowRightIcon class="h-4 w-4" /></RouterLink>
          </template>
          <template v-else>
            <p class="mt-3 text-sm text-gray-700">{{ data.loan_wait_reason }}</p>
            <p class="mt-3 text-sm text-gray-500 phone-hide">Keep saving: the more you save, the more you'll be able to borrow.</p>
            <RouterLink :to="{ name: 'loans' }" class="mt-auto !mt-6 flex w-full items-center justify-center gap-2 rounded-xl border border-[#d9cffd] bg-[#f3f0ff] px-4 py-2.5 text-sm font-bold text-[#5b3fd6] transition hover:bg-[#ebe5ff] dark:border-white/15 dark:bg-white/5 dark:text-[#b9a8ff]">{{ t('See my loans') }}<ChevronRightIcon class="h-4 w-4" aria-hidden="true" /></RouterLink>
          </template>
        </div>

        <!-- Saver level and achievements -->
        <section class="reveal card p-4 sm:p-5" style="--i: 2">
          <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
            <span class="tile-green inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl sm:h-12 sm:w-12"><SparklesIcon class="h-6 w-6" aria-hidden="true" /></span>
            <div class="min-w-[8rem]">
              <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">{{ t('My saver level') }}</p>
              <p class="text-xl font-extrabold sm:text-2xl">{{ level.name }}</p>
            </div>
            <div class="order-last w-full sm:order-none sm:w-auto sm:min-w-[12rem] sm:flex-1 lg:order-last lg:w-full lg:flex-none">
              <template v-if="nextLevel">
                <div class="h-2.5 overflow-hidden rounded-full bg-gray-100"><div class="fill-bar h-full rounded-full bg-emerald-500" :style="{ width: levelProgress + '%' }" /></div>
                <p class="mt-1.5 text-xs text-gray-500">{{ hidden ? 'Keep saving' : `${formatMoney(String(nextLevel.from - total))} more` }} to reach {{ nextLevel.name }}</p>
              </template>
              <p v-else class="text-sm text-gray-500">The top level. Well done!</p>
            </div>
            <span v-if="nextLevel" class="ml-auto inline-flex items-center gap-1.5 rounded-xl bg-saffron-50 sm:ml-0 lg:ml-auto px-3.5 py-2 text-sm font-bold text-saffron-700"><TrophyIcon class="h-4 w-4" aria-hidden="true" />{{ nextLevel.name }}</span>
          </div>
          <ul class="mt-4 hidden flex-wrap gap-2 border-t border-line pt-3 sm:flex" aria-label="Achievements">
            <li v-for="(b, n) in badges" :key="b.label" class="badge-pop inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold" :style="{ '--i': n }" :class="b.earned ? 'bg-[#f1edff] text-[#5b3fd6] dark:bg-white/10 dark:text-[#b9a8ff]' : 'bg-gray-100 text-gray-400'" :title="b.earned ? 'Earned' : 'Not yet'">
              {{ b.label }}
            </li>
          </ul>
        </section>
      </section>

      <!-- Growth, and loans -->
      <section class="mt-4 grid gap-4 lg:grid-cols-[1.4fr_0.85fr_1.15fr]">
        <div class="reveal card min-w-0 p-5" style="--i: 2">
          <div class="flex items-center justify-between gap-3 sm:flex-wrap">
            <div class="flex items-start gap-3">
              <span class="icon-gold inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"><ChartBarIcon class="h-5 w-5" aria-hidden="true" /></span>
              <div>
              <h2 class="text-[1.05rem] font-bold"><span class="sm:hidden">Savings Growth</span><span class="hidden sm:inline">{{ series === 'savings' ? t('How my savings have grown') : 'How my shares have grown' }}</span></h2>
              <p class="hidden text-sm text-gray-500 sm:block">
                <template v-if="growth > 0"><span class="font-semibold text-emerald-700">+{{ show(growth) }}</span> {{ points.length < range ? `since ${sinceLabel}` : `in the last ${range} months` }}</template>
                <template v-else>Balance at the end of each month</template>
              </p>
              </div>
            </div>
            <select v-model.number="range" class="shrink-0 rounded-full border border-line bg-surface py-1.5 pl-3 pr-8 text-xs font-semibold text-ink sm:hidden" aria-label="Period">
              <option :value="12">Last 12 Months</option>
              <option :value="6">Last 6 Months</option>
            </select>
            <div class="hidden gap-2 sm:flex">
              <div class="flex gap-1 rounded-xl bg-[#f3f1fb] p-1 text-xs font-semibold dark:bg-white/5" role="radiogroup" aria-label="Account">
                <button v-for="s in (['savings', 'shares'] as const)" :key="s" type="button" role="radio" :aria-checked="series === s" class="rounded-md px-2.5 py-1 capitalize" :class="series === s ? 'bg-surface text-[#6246ea] shadow-sm ring-1 ring-[#6246ea]/30' : 'text-gray-600'" @click="series = s">{{ s }}</button>
              </div>
              <div class="flex gap-1 rounded-xl bg-[#f3f1fb] p-1 text-xs font-semibold dark:bg-white/5" role="radiogroup" aria-label="Period">
                <button v-for="r in ([6, 12] as const)" :key="r" type="button" role="radio" :aria-checked="range === r" class="rounded-md px-2.5 py-1" :class="range === r ? 'bg-surface text-[#6246ea] shadow-sm ring-1 ring-[#6246ea]/30' : 'text-gray-600'" @click="range = r">{{ r }}M</button>
              </div>
            </div>
          </div>
          <MonthChart
            :key="series + range + (hidden ? 'h' : '')"
            class="mt-4"
            :points="points"
            kind="area"
            :color="series === 'savings' ? colors.blue : colors.amber"
            :label="series === 'savings' ? 'Savings' : 'Share capital'"
            :format="(v: number) => show(v)"
          />
        </div>

        <!-- phones: savings and share capital, with how much each grew over the period -->
        <div class="grid grid-cols-2 gap-3 sm:hidden lg:grid lg:grid-cols-1">
          <div class="money-tile money-tile-green lg:flex lg:items-center lg:gap-4">
            <span class="tile-green inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl lg:h-12 lg:w-12"><ArrowTrendingUpIcon class="h-5 w-5" aria-hidden="true" /></span>
            <div>
            <p class="mt-3 text-sm text-gray-600 lg:mt-0">Total Savings</p>
            <p class="text-lg font-extrabold tabular-nums">{{ show(data.balances.savings) }}</p>
            <span v-if="change('savings') !== null" class="mt-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-bold" :class="change('savings')! >= 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-red-50 text-red-700'">{{ change('savings')! >= 0 ? '↑' : '↓' }} {{ change('savings')! >= 0 ? '+' : '' }}{{ change('savings') }}%</span>
            </div>
          </div>
          <div class="money-tile money-tile-blue lg:flex lg:items-center lg:gap-4">
            <span class="tile-blue inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl lg:h-12 lg:w-12"><CircleStackIcon class="h-5 w-5" aria-hidden="true" /></span>
            <div>
            <p class="mt-3 text-sm text-gray-600 lg:mt-0">Share Capital</p>
            <p class="text-lg font-extrabold tabular-nums">{{ show(data.balances.shares) }}</p>
            <span v-if="change('shares') !== null" class="mt-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-bold" :class="change('shares')! >= 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-red-50 text-red-700'">{{ change('shares')! >= 0 ? '↑' : '↓' }} {{ change('shares')! >= 0 ? '+' : '' }}{{ change('shares') }}%</span>
            </div>
          </div>
        </div>

        <div class="reveal card min-w-0 p-5 lg:order-last lg:col-span-3" style="--i: 3">
          <div class="flex items-center justify-between">
            <h2 class="flex items-center gap-3 text-[1.05rem] font-bold"><span class="icon-gold inline-flex h-10 w-10 items-center justify-center rounded-xl"><Square3Stack3DIcon class="h-5 w-5" aria-hidden="true" /></span>{{ data.loans.length > 1 ? t('Loans') : t('My loan') }}</h2>
            <RouterLink :to="{ name: 'loans' }" class="inline-flex items-center gap-0.5 rounded-full border border-[#e4dcff] px-3 py-1 text-xs font-semibold text-[#5b3ff0] hover:bg-[#f3f0ff] dark:border-white/15 dark:text-[#b9a8ff]">{{ t('All loans') }}<ChevronRightIcon class="h-3.5 w-3.5" aria-hidden="true" /></RouterLink>
          </div>
          <ul v-if="data.loans.length" class="mt-4 space-y-5">
            <li v-for="l in data.loans" :key="l.id">
              <RouterLink :to="{ name: 'loan', params: { id: l.id } }" class="group flex items-center gap-4">
                <span class="relative inline-flex h-20 w-20 shrink-0 items-center justify-center">
                  <svg viewBox="0 0 80 80" class="h-20 w-20 -rotate-90" aria-hidden="true">
                    <circle cx="40" cy="40" r="34" fill="none" stroke-width="7" class="stroke-gray-100" />
                    <circle cx="40" cy="40" r="34" fill="none" stroke-width="7" stroke-linecap="round" class="ring-fill stroke-brand" :style="{ '--ring': ring }" :stroke-dasharray="ring" :stroke-dashoffset="ring * (1 - progress(l) / 100)" />
                  </svg>
                  <span class="absolute text-sm font-bold"><CountUp :value="progress(l)" :format="(n: number) => `${n}%`" :delay="250" /></span>
                </span>
                <span class="min-w-0 flex-1">
                  <span class="block text-sm font-semibold group-hover:text-brand">{{ l.loan_no }} · {{ show(l.amount) }}</span>
                  <span class="block text-sm text-gray-600">{{ show(l.total_left) }} left to pay</span>
                  <span v-if="l.next_due_date" class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="Number(l.overdue) > 0 ? 'bg-red-50 text-red-700' : 'bg-navy-50 text-navy-800'">
                    {{ show(l.next_due_amount) }} {{ daysUntil(l.next_due_date) }}
                  </span>
                </span>
              </RouterLink>
            </li>
          </ul>
          <div v-else class="mt-4 sm:mt-6 sm:text-center lg:mt-4 lg:flex lg:items-center lg:justify-between lg:gap-6 lg:text-left">
            <div class="flex items-start gap-4 sm:block lg:flex lg:items-center">
              <span class="tile-green inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl"><CheckCircleIcon class="h-7 w-7" aria-hidden="true" /></span>
              <div>
                <p class="font-bold sm:mt-3 lg:mt-0">You don't have an active loan</p>
                <p class="mt-1 text-sm text-gray-600">Apply for a loan today and grow your goals with {{ auth.sacco?.name || 'the SACCO' }}. Repayments come off your pay.</p>
              </div>
            </div>
            <RouterLink :to="{ name: 'loan-apply' }" class="btn-primary mt-5 w-full !rounded-full !py-3 lg:mt-0 lg:w-auto lg:shrink-0 lg:!px-8">Apply for a Loan<ArrowRightIcon class="h-4 w-4" aria-hidden="true" /></RouterLink>
          </div>
          <p v-if="data.next_repayment" class="mt-5 border-t border-line pt-3 text-xs text-gray-500">
            Next repayment {{ formatDate(data.next_repayment.date) }}; it comes off your payroll.
          </p>
        </div>

        <!-- the latest entries on my account (phones and computers) -->
        <section class="card min-w-0 p-4 sm:max-lg:hidden lg:p-5" aria-labelledby="recent-phone">
          <div class="flex items-center justify-between gap-2">
            <h2 id="recent-phone" class="flex items-center gap-3 text-[1.05rem] font-bold"><span class="icon-gold inline-flex h-10 w-10 items-center justify-center rounded-xl"><ArrowsRightLeftIcon class="h-5 w-5" aria-hidden="true" /></span>Recent Transactions</h2>
            <RouterLink :to="{ name: 'statement' }" class="inline-flex items-center gap-0.5 rounded-full border border-[#e4dcff] px-3 py-1 text-xs font-semibold text-[#5b3ff0] dark:border-white/15 dark:text-[#b9a8ff]">View All<ChevronRightIcon class="h-3.5 w-3.5" aria-hidden="true" /></RouterLink>
          </div>
          <ul class="mt-2 divide-y divide-line">
            <li v-for="tx in recentPhone" :key="tx.id" class="flex items-center gap-3 py-3">
              <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" :class="Number(tx.amount) < 0 ? 'tile-red' : 'tile-green'">
                <component :is="Number(tx.amount) < 0 ? ArrowUpIcon : ArrowDownIcon" class="h-5 w-5" aria-hidden="true" />
              </span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-bold">{{ txTitle(tx) }}</span>
                <span class="block text-xs text-gray-500">{{ txWhen(tx) }}</span>
              </span>
              <span class="shrink-0 text-sm font-extrabold tabular-nums" :class="Number(tx.amount) < 0 ? 'text-red-600' : 'text-emerald-600'">{{ Number(tx.amount) < 0 ? '- ' : '+ ' }}{{ show(Math.abs(Number(tx.amount))) }}</span>
            </li>
            <li v-if="!recentPhone.length" class="py-4 text-sm text-gray-500">Nothing yet.</li>
          </ul>
        </section>
      </section>


      <!-- computers: my account at a glance -->
      <section class="mt-4 hidden gap-4 lg:grid lg:grid-cols-4" aria-label="My account at a glance">
        <div v-for="c in glance" :key="c.label" class="stat-card !flex-row items-center gap-4">
          <span class="icon-gold inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl"><component :is="c.icon" class="h-7 w-7" aria-hidden="true" /></span>
          <span class="min-w-0">
            <span class="block text-sm text-gray-500">{{ c.label }}</span>
            <span class="block truncate text-[1.45rem] font-extrabold leading-tight tabular-nums">{{ c.value }}</span>
          </span>
        </div>
      </section>

      <!-- Notices from the office and things to answer -->
      <NoticeBoard class="mt-5" />

      <RouterLink
        v-if="data?.guarantee_requests"
        :to="{ name: 'loans' }"
        class="mt-5 flex items-center justify-between gap-3 rounded-2xl border border-saffron-300/70 bg-surface px-4 py-3 text-sm hover:shadow-lift"
      >
        <span class="flex items-center gap-3">
          <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl icon-gold"><UserGroupIcon class="h-5 w-5" aria-hidden="true" /></span>
          <span><strong class="font-semibold">{{ data.guarantee_requests }} colleague{{ data.guarantee_requests === 1 ? '' : 's' }}</strong> {{ data.guarantee_requests === 1 ? 'has' : 'have' }} asked you to guarantee a loan.</span>
        </span>
        <span class="font-semibold text-brand">Answer →</span>
      </RouterLink>

      <div
        v-if="data?.membership_fee && data.membership_fee.status === 'unpaid'"
        class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-navy-100 bg-navy-50/60 px-4 py-3 text-sm"
      >
        <span class="flex items-center gap-3">
          <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl icon-gold"><IdentificationIcon class="h-5 w-5" aria-hidden="true" /></span>
          <span>Your <strong class="font-semibold">{{ data.membership_fee.year }} subscription</strong> of {{ formatMoney(data.membership_fee.amount) }} is due. The office can take it from your savings, or pay it at the office.</span>
        </span>
      </div>

      <div v-if="data?.joining_fee?.status === 'unpaid'" class="mt-3 rounded-2xl border border-navy-100 bg-navy-50/60 px-4 py-3 text-sm">
        Your <strong class="font-semibold">membership fee</strong> of {{ formatMoney(data.joining_fee.amount) }} is due. It is paid once. The office can take it from your savings, or pay it at the office.
      </div>

      <!-- More about my money (kept from the money card) -->
      <!-- four cards: two pairs on phones, one row on wider screens -->
      <section class="mt-4 grid grid-cols-2 gap-3" aria-label="More about my money">
        <div class="stat-card">
          <p class="text-sm text-gray-500">{{ t('You can withdraw') }}</p>
          <p class="mt-1 text-lg font-extrabold tabular-nums"><CountUp :value="data.withdrawable" :format="show" /></p>
          <p class="text-xs text-gray-500">from savings · shares stay</p>
        </div>
        <div class="stat-card lg:hidden">
          <p class="text-sm text-gray-500">{{ t('Saved this year') }}</p>
          <p class="mt-1 text-lg font-extrabold tabular-nums"><CountUp :value="data.saved_this_year" :format="show" /></p>
        </div>
        <div class="stat-card lg:hidden">
          <p class="text-sm text-gray-500">Dividends &amp; profit so far</p>
          <p class="mt-1 text-lg font-extrabold tabular-nums"><CountUp :value="data.dividends_total" :format="show" /></p>
        </div>
        <div v-if="Number(data.pledged) > 0" class="stat-card">
          <p class="flex items-center gap-1 text-sm text-gray-500"><LockClosedIcon class="h-3.5 w-3.5" aria-hidden="true" />{{ t('Held for loans and guarantees') }}</p>
          <p class="mt-1 text-lg font-extrabold tabular-nums"><CountUp :value="data.pledged" :format="show" /></p>
        </div>
        <div v-else class="stat-card">
          <p class="text-sm text-gray-500">{{ t('Free to guarantee') }}</p>
          <p class="mt-1 text-lg font-extrabold tabular-nums"><CountUp :value="data.free_to_guarantee" :format="show" /></p>
          <p class="text-xs text-gray-500">savings you can pledge for a colleague</p>
        </div>
      </section>

      <EarningsCard class="reveal mt-4" style="--i: 4" />

      <section class="reveal mt-4 hidden sm:block lg:hidden" style="--i: 5">
        <div class="flex items-center justify-between">
          <h2 class="font-semibold">{{ t('Recent activity') }}</h2>
          <RouterLink :to="{ name: 'statement' }" class="text-sm font-semibold text-brand hover:underline">{{ t('Full statement') }}</RouterLink>
        </div>
        <div class="card mt-3 overflow-hidden">
          <TransactionList :transactions="data.recent" show-account />
        </div>
      </section>
    </template>

    <!-- Loading -->
    <div v-else-if="!error" class="mt-6 grid gap-4 lg:grid-cols-3" aria-hidden="true">
      <div class="h-64 animate-pulse rounded-3xl bg-surface lg:col-span-2" />
      <div class="h-64 animate-pulse rounded-2xl bg-surface" />
    </div>
  </div>
</template>
