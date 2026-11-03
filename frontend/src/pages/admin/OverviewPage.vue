<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  ArrowDownLeftIcon,
  ArrowUpRightIcon,
  ArrowUpTrayIcon,
  CheckCircleIcon,
  ChevronRightIcon,
  ExclamationTriangleIcon,
  UserPlusIcon,
} from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import type { Waiting } from '@/stores/office'
import { formatMoney } from '@/money'
import { loanStatus } from '@/loans'
import type { LoanStatus } from '@/types'
import MonthChart from '@/components/MonthChart.vue'
import { useTheme } from '@/stores/theme'

interface Overview {
  totals: {
    savings: string
    shares: string
    members: number
    new_members_this_month: number
    loans_outstanding: string
    active_loans: number
    arrears: string
    loans_in_arrears: number
  }
  this_month: { savings_in: string; shares_in: string; withdrawn: string; dividends: string; repaid: string; lent: string }
  waiting: Waiting
  funds: { month: string; value: string }[]
  collections: { month: string; value: string }[]
  loans_by_status: Partial<Record<LoanStatus, { count: number; amount: string }>>
  recent: { text: string; actor: string; at: string }[]
}

const auth = useAuth()
const theme = useTheme()
// Validated for colour-blind separation and contrast on each theme's surface
const chartColors = computed(() => (theme.dark ? { blue: '#6b78ec', amber: '#b8800c' } : { blue: '#3a46c4', amber: '#c98a08' }))
const data = ref<Overview | null>(null)
const error = ref('')

onMounted(async () => {
  try {
    data.value = await api.get<Overview>('/office/overview')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the overview.'
  }
})

const hour = new Date().getHours()
const greeting = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening'
const monthName = new Date().toLocaleDateString('en-GB', { month: 'long' })

const funds = computed(() => (data.value ? Number(data.value.totals.savings) + Number(data.value.totals.shares) : 0))

/** The to-do list: only what has something waiting */
const tasks = computed(() => {
  const w = data.value?.waiting
  if (!w) return []
  return [
    { n: w.loans_to_approve, text: 'loan application', verb: 'to approve', to: { name: 'admin-loans' } },
    { n: w.loans_to_pay_out, text: 'approved loan', verb: 'to pay out', to: { name: 'admin-loans' } },
    { n: w.withdrawals_to_approve, text: 'withdrawal request', verb: 'to approve', to: { name: 'admin-withdrawals' } },
    { n: w.withdrawals_to_pay, text: 'withdrawal cheque', verb: 'to issue', to: { name: 'admin-withdrawals' } },
    { n: w.loans_waiting_guarantors, text: 'loan', verb: 'waiting for guarantors', to: { name: 'admin-loans' }, quiet: true },
    { n: w.members_not_signed_in, text: 'member', verb: 'yet to sign in for the first time', to: { name: 'users' }, quiet: true },
  ].filter((t) => t.n > 0)
})
const urgent = computed(() => tasks.value.filter((t) => !t.quiet).length)

const moneyIn = computed(() => {
  const m = data.value?.this_month
  if (!m) return []
  return [
    { label: 'Savings deposits', value: m.savings_in },
    { label: 'Share purchases', value: m.shares_in },
    { label: 'Loan repayments', value: m.repaid },
  ]
})
const moneyOut = computed(() => {
  const m = data.value?.this_month
  if (!m) return []
  return [
    { label: 'Loans paid out', value: m.lent },
    { label: 'Savings withdrawn', value: m.withdrawn },
  ]
})
const totalIn = computed(() => moneyIn.value.reduce((s, r) => s + Number(r.value), 0))
const totalOut = computed(() => moneyOut.value.reduce((s, r) => s + Number(r.value), 0))

const statusOrder: LoanStatus[] = ['pending_guarantors', 'pending_approval', 'approved', 'active', 'closed']

function ago(at: string) {
  const mins = Math.round((Date.now() - new Date(at.replace(' ', 'T')).getTime()) / 60000)
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins} min ago`
  const hours = Math.round(mins / 60)
  if (hours < 24) return `${hours} h ago`
  const days = Math.round(hours / 24)
  return days === 1 ? 'yesterday' : `${days} days ago`
}

const money = (v: number) => formatMoney(String(v))
</script>

<template>
  <div>
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <p class="eyebrow">SACCO office</p>
        <h1 class="mt-1 text-2xl font-bold sm:text-3xl">{{ greeting }}, {{ auth.user?.full_name.split(' ')[0] }}</h1>
        <p class="mt-1 text-gray-600">
          <template v-if="data">
            <template v-if="urgent">{{ urgent }} kind{{ urgent === 1 ? '' : 's' }} of thing{{ urgent === 1 ? ' is' : 's are' }} waiting for you.</template>
            <template v-else>Everything is up to date.</template>
          </template>
        </p>
      </div>
      <div class="flex flex-wrap gap-2">
        <RouterLink :to="{ name: 'member-import' }" class="btn-secondary"><UserPlusIcon class="h-4 w-4" />Import members</RouterLink>
        <RouterLink :to="{ name: 'payroll' }" class="btn-primary"><ArrowUpTrayIcon class="h-4 w-4" />Post this month's payroll</RouterLink>
      </div>
    </div>

    <div v-if="error" class="mt-6 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <template v-if="data">
      <!-- Needs your attention -->
      <section class="mt-6" aria-labelledby="todo">
        <h2 id="todo" class="sr-only">Needs your attention</h2>
        <div v-if="tasks.length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <RouterLink
            v-for="t in tasks"
            :key="t.text + t.verb"
            :to="t.to"
            class="group flex items-center gap-4 rounded-2xl border p-4 transition hover:shadow-lift"
            :class="t.quiet ? 'border-line bg-surface' : 'border-saffron-300 bg-saffron-50'"
          >
            <span
              class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-lg font-bold tabular-nums"
              :class="t.quiet ? 'bg-gray-100 text-gray-700' : 'bg-saffron-400 text-navy-950'"
            >{{ t.n }}</span>
            <span class="flex-1 text-sm">
              <span class="font-semibold">{{ t.text }}{{ t.n === 1 ? '' : 's' }}</span>&#32;<span class="text-gray-600">{{ t.verb }}</span>
            </span>
            <ChevronRightIcon class="h-5 w-5 text-gray-400 transition group-hover:translate-x-0.5 group-hover:text-gray-700" aria-hidden="true" />
          </RouterLink>
        </div>
        <div v-else class="flex items-center gap-3 rounded-2xl border border-line bg-surface p-4 text-sm text-gray-700">
          <CheckCircleIcon class="h-6 w-6 text-emerald-600" aria-hidden="true" />
          Nothing is waiting: no loans or withdrawals to approve, and no cheques to issue.
        </div>
      </section>

      <!-- Headline figures -->
      <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Headline figures">
        <div class="relative overflow-hidden rounded-2xl bg-navy-900 p-5 text-white shadow-lift">
          <div class="absolute -right-14 -top-16 h-32 w-32 rounded-full border-2 border-saffron-400/90" aria-hidden="true" />
          <p class="text-xs font-semibold uppercase tracking-wider text-navy-200">Member funds</p>
          <p class="mt-2 text-2xl font-bold tabular-nums">{{ money(funds) }}</p>
          <p class="mt-1 text-xs text-navy-200">Savings {{ formatMoney(data.totals.savings) }} · Shares {{ formatMoney(data.totals.shares) }}</p>
        </div>
        <div class="card p-5">
          <p class="eyebrow">Loans outstanding</p>
          <p class="figure mt-2 text-2xl">{{ formatMoney(data.totals.loans_outstanding) }}</p>
          <p class="mt-1 text-xs text-gray-500">{{ data.totals.active_loans }} active loan{{ data.totals.active_loans === 1 ? '' : 's' }}</p>
        </div>
        <RouterLink :to="{ name: 'reports' }" class="card p-5 transition hover:shadow-lift" :class="{ '!border-red-200 !bg-red-50/60': data.totals.loans_in_arrears }">
          <p class="eyebrow flex items-center gap-1.5" :class="{ '!text-red-700': data.totals.loans_in_arrears }">
            <ExclamationTriangleIcon v-if="data.totals.loans_in_arrears" class="h-4 w-4" aria-hidden="true" />In arrears
          </p>
          <p class="figure mt-2 text-2xl" :class="{ '!text-red-700': data.totals.loans_in_arrears }">{{ formatMoney(data.totals.arrears) }}</p>
          <p class="mt-1 text-xs text-gray-500">
            {{ data.totals.loans_in_arrears ? `${data.totals.loans_in_arrears} loan${data.totals.loans_in_arrears === 1 ? '' : 's'} behind on repayments` : 'Every loan is up to date' }}
          </p>
        </RouterLink>
        <RouterLink :to="{ name: 'users' }" class="card p-5 transition hover:shadow-lift">
          <p class="eyebrow">Active members</p>
          <p class="figure mt-2 text-2xl">{{ data.totals.members }}</p>
          <p class="mt-1 text-xs text-gray-500">{{ data.totals.new_members_this_month }} joined this month</p>
        </RouterLink>
      </section>

      <!-- Trends -->
      <section class="mt-6 grid gap-4 xl:grid-cols-5">
        <div class="card p-5 xl:col-span-3">
          <div class="flex items-baseline justify-between gap-2">
            <h2 class="font-semibold">Member funds</h2>
            <p class="text-xs text-gray-500">Savings + shares at each month end</p>
          </div>
          <MonthChart class="mt-4" :points="data.funds" kind="area" :color="chartColors.blue" label="Member funds" :format="money" />
        </div>
        <div class="card p-5 xl:col-span-2">
          <div class="flex items-baseline justify-between gap-2">
            <h2 class="font-semibold">Loan repayments</h2>
            <p class="text-xs text-gray-500">Collected each month</p>
          </div>
          <MonthChart class="mt-4" :points="data.collections" kind="bar" :color="chartColors.amber" label="Loan repayments" :format="money" />
        </div>
      </section>

      <!-- This month, loans, activity -->
      <section class="mt-6 grid gap-4 xl:grid-cols-3">
        <div class="card p-5">
          <h2 class="font-semibold">{{ monthName }} so far</h2>
          <div class="mt-4 space-y-4 text-sm">
            <div>
              <p class="flex items-center justify-between font-semibold text-emerald-800">
                <span class="flex items-center gap-1.5"><ArrowDownLeftIcon class="h-4 w-4" aria-hidden="true" />Money in</span>
                <span class="tabular-nums">{{ money(totalIn) }}</span>
              </p>
              <ul class="mt-2 space-y-1.5 border-l-2 border-emerald-100 pl-3 text-gray-600">
                <li v-for="r in moneyIn" :key="r.label" class="flex justify-between"><span>{{ r.label }}</span><span class="tabular-nums text-ink">{{ formatMoney(r.value) }}</span></li>
              </ul>
            </div>
            <div>
              <p class="flex items-center justify-between font-semibold text-red-800">
                <span class="flex items-center gap-1.5"><ArrowUpRightIcon class="h-4 w-4" aria-hidden="true" />Money out</span>
                <span class="tabular-nums">{{ money(totalOut) }}</span>
              </p>
              <ul class="mt-2 space-y-1.5 border-l-2 border-red-100 pl-3 text-gray-600">
                <li v-for="r in moneyOut" :key="r.label" class="flex justify-between"><span>{{ r.label }}</span><span class="tabular-nums text-ink">{{ formatMoney(r.value) }}</span></li>
              </ul>
            </div>
            <p v-if="Number(data.this_month.dividends) > 0" class="flex justify-between border-t border-line pt-3 text-gray-600">
              <span>Dividends credited to shares</span><span class="tabular-nums text-ink">{{ formatMoney(data.this_month.dividends) }}</span>
            </p>
          </div>
        </div>

        <div class="card p-5">
          <div class="flex items-center justify-between">
            <h2 class="font-semibold">Loans</h2>
            <RouterLink :to="{ name: 'admin-loans' }" class="text-sm font-semibold text-brand hover:underline">All loans</RouterLink>
          </div>
          <ul class="mt-4 divide-y divide-line text-sm">
            <li v-for="s in statusOrder" :key="s" class="flex items-center justify-between py-2.5">
              <span class="flex items-center gap-2">
                <span class="inline-block h-2 w-2 rounded-full" :class="loanStatus[s].dot" aria-hidden="true" />
                {{ loanStatus[s].label }}
              </span>
              <span class="text-right">
                <span class="font-semibold tabular-nums">{{ data.loans_by_status[s]?.count ?? 0 }}</span>
                <span v-if="data.loans_by_status[s]" class="ml-2 text-xs tabular-nums text-gray-500">{{ formatMoney(data.loans_by_status[s]!.amount) }}</span>
              </span>
            </li>
          </ul>
        </div>

        <div class="card p-5">
          <div class="flex items-center justify-between">
            <h2 class="font-semibold">Latest activity</h2>
            <RouterLink :to="{ name: 'reports' }" class="text-sm font-semibold text-brand hover:underline">Audit log</RouterLink>
          </div>
          <ol class="mt-4 space-y-3 text-sm">
            <li v-for="(r, i) in data.recent" :key="i" class="flex gap-3">
              <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-navy-200" aria-hidden="true" />
              <span class="flex-1">
                <span class="font-semibold">{{ r.actor }}</span> <span class="text-gray-700">{{ r.text }}</span>
                <span class="block text-xs text-gray-500">{{ ago(r.at) }}</span>
              </span>
            </li>
            <li v-if="!data.recent.length" class="text-gray-500">Nothing yet.</li>
          </ol>
        </div>
      </section>
    </template>

    <!-- Loading -->
    <div v-else-if="!error" class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-hidden="true">
      <div v-for="i in 4" :key="i" class="h-28 animate-pulse rounded-2xl bg-surface" />
    </div>
  </div>
</template>
