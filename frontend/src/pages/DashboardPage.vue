<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import {
  ArrowRightIcon,
  ArrowUpTrayIcon,
  BanknotesIcon,
  CheckCircleIcon,
  DocumentTextIcon,
  EyeIcon,
  EyeSlashIcon,
  LockClosedIcon,
  UserCircleIcon,
  UserGroupIcon,
} from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { useTheme } from '@/stores/theme'
import type { Balances, Transaction } from '@/types'
import TransactionList from '@/components/TransactionList.vue'
import NoticeBoard from '@/components/NoticeBoard.vue'
import EarningsCard from '@/components/EarningsCard.vue'
import MonthChart from '@/components/MonthChart.vue'
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
const greeting = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening'
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

const savings = computed(() => Number(data.value?.balances.savings ?? 0))
const shares = computed(() => Number(data.value?.balances.shares ?? 0))
const total = computed(() => savings.value + shares.value)
const savingsShare = computed(() => (total.value > 0 ? (savings.value / total.value) * 100 : 50))

// Validated for colour-blind separation and contrast on each theme's surface
const colors = computed(() => (theme.dark ? { blue: '#6b78ec', amber: '#b8800c' } : { blue: '#3a46c4', amber: '#c98a08' }))
const series = ref<'savings' | 'shares'>('savings')
const range = ref<6 | 12>(12)
const points = computed(() => (data.value ? data.value.trend[series.value].slice(-range.value) : []))
const growth = computed(() => {
  const p = points.value
  if (p.length < 2) return 0
  return Number(p[p.length - 1].value) - Number(p[0].value)
})

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

const actions = [
  { label: 'Apply for a loan', to: { name: 'loan-apply' }, icon: BanknotesIcon },
  { label: 'Withdraw savings', to: { name: 'withdrawals' }, icon: ArrowUpTrayIcon },
  { label: 'My statement', to: { name: 'statement' }, icon: DocumentTextIcon },
  { label: 'My profile', to: { name: 'profile' }, icon: UserCircleIcon },
]
</script>

<template>
  <div>
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <p class="eyebrow">My account · <span class="font-mono normal-case tracking-normal">{{ auth.user?.account_no }}</span></p>
        <h1 class="mt-1 text-2xl font-bold sm:text-3xl">{{ greeting }}, {{ firstName }}</h1>
      </div>
      <nav class="flex flex-wrap gap-2" aria-label="Quick actions">
        <RouterLink v-for="a in actions" :key="a.label" :to="a.to" class="btn-secondary !rounded-full !px-3.5">
          <component :is="a.icon" class="h-4 w-4 text-brand" aria-hidden="true" />{{ a.label }}
        </RouterLink>
      </nav>
    </div>

    <NoticeBoard class="mt-5" />
    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <RouterLink
      v-if="data?.guarantee_requests"
      :to="{ name: 'loans' }"
      class="mt-5 flex items-center justify-between gap-3 rounded-2xl border border-saffron-300 bg-saffron-50 px-4 py-3 text-sm hover:shadow-lift"
    >
      <span class="flex items-center gap-3">
        <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-saffron-400 text-navy-950"><UserGroupIcon class="h-5 w-5" aria-hidden="true" /></span>
        <span><strong class="font-semibold">{{ data.guarantee_requests }} colleague{{ data.guarantee_requests === 1 ? '' : 's' }}</strong> {{ data.guarantee_requests === 1 ? 'has' : 'have' }} asked you to guarantee a loan.</span>
      </span>
      <span class="font-semibold text-brand">Answer →</span>
    </RouterLink>

    <template v-if="data">
      <!-- What I have, and what I can borrow -->
      <section class="mt-6 grid gap-4 lg:grid-cols-3" aria-label="Balances">
        <div class="relative min-w-0 overflow-hidden rounded-3xl bg-gradient-to-br from-navy-800 via-navy-900 to-navy-950 p-6 text-white shadow-lift lg:col-span-2">
          <div class="absolute -right-20 -top-24 h-64 w-64 rounded-full border-2 border-saffron-400/70" aria-hidden="true" />
          <div class="absolute -bottom-28 right-24 h-56 w-56 rounded-full bg-saffron-400/10" aria-hidden="true" />
          <div class="relative flex items-start justify-between gap-4">
            <div>
              <p class="text-sm text-navy-100/80">My money in the SACCO</p>
              <p class="mt-1 text-4xl font-bold tabular-nums sm:text-5xl">{{ show(total) }}</p>
            </div>
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

          <!-- Savings and shares, side by side -->
          <div class="relative mt-6 flex h-3 overflow-hidden rounded-full bg-white/10" role="img" :aria-label="`Savings ${Math.round(savingsShare)}%, shares ${100 - Math.round(savingsShare)}%`">
            <div class="h-full bg-navy-200 transition-all duration-700" :style="{ width: savingsShare + '%' }" />
            <div class="ml-0.5 h-full flex-1 bg-saffron-400" />
          </div>
          <div class="relative mt-3 grid gap-4 sm:grid-cols-2">
            <div class="flex items-start gap-2.5">
              <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-navy-200" aria-hidden="true" />
              <div>
                <p class="text-sm text-navy-100/80">Savings</p>
                <p class="text-xl font-semibold tabular-nums">{{ show(data.balances.savings) }}</p>
              </div>
            </div>
            <div class="flex items-start gap-2.5">
              <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-saffron-400" aria-hidden="true" />
              <div>
                <p class="text-sm text-navy-100/80">Share capital</p>
                <p class="text-xl font-semibold tabular-nums">{{ show(data.balances.shares) }}</p>
                <p v-if="data.share_position.min_shares > 0 && data.share_position.shares !== null" class="mt-0.5 flex items-center gap-1 text-xs" :class="Number(data.share_position.still_needed) > 0 ? 'text-saffron-300' : 'text-navy-100/80'">
                  <CheckCircleIcon v-if="Number(data.share_position.still_needed) <= 0" class="h-3.5 w-3.5" aria-hidden="true" />
                  {{ data.share_position.shares }} shares<template v-if="Number(data.share_position.still_needed) > 0"> · {{ show(data.share_position.still_needed) }} more to reach {{ data.share_position.min_shares }}</template><template v-else> · minimum of {{ data.share_position.min_shares }} reached</template>
                </p>
              </div>
            </div>
          </div>

          <dl class="relative mt-6 grid grid-cols-2 gap-4 border-t border-white/10 pt-4 text-sm sm:grid-cols-3">
            <div>
              <dt class="text-navy-100/70">You can withdraw</dt>
              <dd class="mt-0.5 font-semibold tabular-nums">{{ show(data.withdrawable) }}</dd>
            </div>
            <div>
              <dt class="text-navy-100/70">Saved this year</dt>
              <dd class="mt-0.5 font-semibold tabular-nums">{{ show(data.saved_this_year) }}</dd>
            </div>
            <div v-if="Number(data.pledged) > 0">
              <dt class="flex items-center gap-1 text-navy-100/70"><LockClosedIcon class="h-3.5 w-3.5" aria-hidden="true" />Pledged for colleagues</dt>
              <dd class="mt-0.5 font-semibold tabular-nums">{{ show(data.pledged) }}</dd>
            </div>
            <div v-else>
              <dt class="text-navy-100/70">Dividends &amp; profit so far</dt>
              <dd class="mt-0.5 font-semibold tabular-nums">{{ show(data.dividends_total) }}</dd>
            </div>
          </dl>
        </div>

        <!-- Borrowing power -->
        <div class="card flex min-w-0 flex-col p-6">
          <p class="eyebrow">Borrowing power</p>
          <template v-if="!data.loan_wait_reason">
            <p class="mt-3 text-sm text-gray-600">You can borrow up to</p>
            <p class="figure text-3xl">{{ show(data.loan_limit) }}</p>
            <div class="mt-4 h-2 overflow-hidden rounded-full bg-gray-100" aria-hidden="true">
              <div class="h-full rounded-full bg-brand transition-all duration-700" :style="{ width: Math.min(100, (Number(data.loan_owed) / (Number(data.loan_owed) + Number(data.loan_limit) || 1)) * 100) + '%' }" />
            </div>
            <p class="mt-2 text-xs text-gray-500">
              <template v-if="Number(data.loan_owed) > 0">{{ show(data.loan_owed) }} already owed on your loan{{ data.loans.length === 1 ? '' : 's' }}.</template>
              <template v-else>Based on your savings, with colleagues as guarantors.</template>
            </p>
            <RouterLink :to="{ name: 'loan-apply' }" class="btn-primary mt-auto w-full !mt-6">Apply for a loan<ArrowRightIcon class="h-4 w-4" /></RouterLink>
          </template>
          <template v-else>
            <p class="mt-3 text-sm text-gray-700">{{ data.loan_wait_reason }}</p>
            <p class="mt-3 text-sm text-gray-500">Keep saving: the more you save, the more you'll be able to borrow.</p>
            <RouterLink :to="{ name: 'loans' }" class="btn-secondary mt-auto w-full !mt-6">See my loans</RouterLink>
          </template>
        </div>
      </section>

      <!-- Growth, and loans -->
      <section class="mt-4 grid gap-4 lg:grid-cols-3">
        <div class="card min-w-0 p-5 lg:col-span-2">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 class="font-semibold">How my {{ series === 'savings' ? 'savings' : 'shares' }} have grown</h2>
              <p class="text-sm text-gray-500">
                <template v-if="growth > 0"><span class="font-semibold text-emerald-700">+{{ show(growth) }}</span> in the last {{ range }} months</template>
                <template v-else>Balance at the end of each month</template>
              </p>
            </div>
            <div class="flex gap-2">
              <div class="flex rounded-lg bg-gray-100 p-0.5 text-xs font-semibold" role="radiogroup" aria-label="Account">
                <button v-for="s in (['savings', 'shares'] as const)" :key="s" type="button" role="radio" :aria-checked="series === s" class="rounded-md px-2.5 py-1 capitalize" :class="series === s ? 'bg-surface text-ink shadow-sm' : 'text-gray-600'" @click="series = s">{{ s }}</button>
              </div>
              <div class="flex rounded-lg bg-gray-100 p-0.5 text-xs font-semibold" role="radiogroup" aria-label="Period">
                <button v-for="r in ([6, 12] as const)" :key="r" type="button" role="radio" :aria-checked="range === r" class="rounded-md px-2.5 py-1" :class="range === r ? 'bg-surface text-ink shadow-sm' : 'text-gray-600'" @click="range = r">{{ r }}M</button>
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

        <div class="card min-w-0 p-5">
          <div class="flex items-center justify-between">
            <h2 class="font-semibold">My loan{{ data.loans.length > 1 ? 's' : '' }}</h2>
            <RouterLink :to="{ name: 'loans' }" class="text-sm font-semibold text-brand hover:underline">All loans</RouterLink>
          </div>
          <ul v-if="data.loans.length" class="mt-4 space-y-5">
            <li v-for="l in data.loans" :key="l.id">
              <RouterLink :to="{ name: 'loan', params: { id: l.id } }" class="group flex items-center gap-4">
                <span class="relative inline-flex h-20 w-20 shrink-0 items-center justify-center">
                  <svg viewBox="0 0 80 80" class="h-20 w-20 -rotate-90" aria-hidden="true">
                    <circle cx="40" cy="40" r="34" fill="none" stroke-width="7" class="stroke-gray-100" />
                    <circle cx="40" cy="40" r="34" fill="none" stroke-width="7" stroke-linecap="round" class="stroke-brand transition-all duration-700" :stroke-dasharray="ring" :stroke-dashoffset="ring * (1 - progress(l) / 100)" />
                  </svg>
                  <span class="absolute text-sm font-bold tabular-nums">{{ progress(l) }}%</span>
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
          <div v-else class="mt-6 text-center">
            <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700"><CheckCircleIcon class="h-7 w-7" aria-hidden="true" /></span>
            <p class="mt-3 font-semibold">No loan to repay</p>
            <p class="mt-1 text-sm text-gray-600">Repayments come off your pay automatically when you have one.</p>
          </div>
          <p v-if="data.next_repayment" class="mt-5 border-t border-line pt-3 text-xs text-gray-500">
            Next repayment {{ formatDate(data.next_repayment.date) }}; it comes off your payroll.
          </p>
        </div>
      </section>

      <EarningsCard class="mt-4" />

      <section class="mt-4">
        <div class="flex items-center justify-between">
          <h2 class="font-semibold">Recent activity</h2>
          <RouterLink :to="{ name: 'statement' }" class="text-sm font-semibold text-brand hover:underline">Full statement</RouterLink>
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
