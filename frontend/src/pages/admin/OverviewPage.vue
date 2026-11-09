<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  ArrowDownLeftIcon,
  ArrowUpRightIcon,
  ArrowUpTrayIcon,
  CheckCircleIcon,
  ChevronRightIcon,
  ExclamationTriangleIcon,
  MegaphoneIcon,
  ShieldCheckIcon,
  UserPlusIcon,
} from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import type { Waiting } from '@/stores/office'
import { formatMoney } from '@/money'
import { loanStatus } from '@/loans'
import type { LoanStatus } from '@/types'
import MonthChart from '@/components/MonthChart.vue'
import CountUp from '@/components/CountUp.vue'
import DonutChart from '@/components/DonutChart.vue'
import HeroParticles from '@/components/HeroParticles.vue'
import Sparkline from '@/components/Sparkline.vue'
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
  recent: { text: string; actor: string; actor_role: string | null; action: string; link: string | null; at: string }[]
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
const loanCount = computed(() => statusOrder.reduce((s, k) => s + (data.value?.loans_by_status[k]?.count ?? 0), 0))
const hoverStatus = ref<LoanStatus | null>(null)
// The same colours as each status's dot across the app; labels below name every slice
const pieColors: Partial<Record<LoanStatus, string>> = { active: '#059669', pending_guarantors: '#fbbf24', pending_approval: '#3a46c4', approved: '#eea90f' }
const slices = computed(() => (['active', 'pending_guarantors', 'pending_approval', 'approved'] as LoanStatus[]).map((k) => ({
  key: k,
  label: loanStatus[k].label,
  value: data.value?.loans_by_status[k]?.count ?? 0,
  color: pieColors[k]!,
})))
const openLoans = computed(() => slices.value.reduce((n, s) => n + s.value, 0))

// The hero
const clock = ref('')
const tick = () => (clock.value = new Date().toLocaleString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit' }))
tick()
const clockTimer = setInterval(tick, 1000)
onBeforeUnmount(() => clearInterval(clockTimer))
const fundsChange = computed(() => {
  const f = data.value?.funds
  if (!f || f.length < 2 || !Number(f[f.length - 2].value)) return null
  return Math.round(((Number(f[f.length - 1].value) - Number(f[f.length - 2].value)) / Number(f[f.length - 2].value)) * 100)
})
const ringLen = 2 * Math.PI * 42
const onTime = computed(() => {
  const t = data.value?.totals
  if (!t || !Number(t.loans_outstanding)) return 100
  return Math.max(0, Math.round((1 - Number(t.arrears) / Number(t.loans_outstanding)) * 100))
})

// The charts: the last 6 or 12 months
const range = ref<6 | 12>(12)
const funds6 = computed(() => data.value?.funds.slice(-range.value) ?? [])
const collections6 = computed(() => data.value?.collections.slice(-range.value) ?? [])

// The activity feed, by kind
const filters = [
  { key: 'all', label: 'All' },
  { key: 'money', label: 'Money' },
  { key: 'loans', label: 'Loans' },
  { key: 'people', label: 'Members' },
  { key: 'rules', label: 'Rules & setup' },
] as const
type Filter = (typeof filters)[number]['key']
const filter = ref<Filter>('all')
function kindOf(action: string): Filter {
  if (action.startsWith('loan.') || action.startsWith('withdrawal.')) return 'loans'
  if (/^(transaction|payroll|dividend|profit_share|cashbook|investment)\./.test(action)) return 'money'
  if (/^(user|users|next_of_kin|member|password)\./.test(action)) return 'people'
  return 'rules'
}
const activity = computed(() => (data.value?.recent ?? []).filter((r) => filter.value === 'all' || kindOf(r.action) === filter.value))

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
    <div class="bg-gradient-to-br from-navy-800 via-navy-900 to-navy-950 relative overflow-hidden rounded-3xl p-6 text-white shadow-lift sm:p-8">
      <HeroParticles />
      <div class="relative grid gap-6 lg:grid-cols-[1.25fr_1fr] lg:items-center">
        <div class="min-w-0">
          <p class="text-xs font-semibold uppercase tracking-wider text-saffron-300">SACCO office · <span class="tabular-nums">{{ clock }}</span></p>
          <h1 class="mt-1 text-3xl font-bold sm:text-4xl">{{ greeting }}, {{ auth.user?.full_name.split(' ')[0] }}</h1>
          <p class="mt-2 text-navy-100/85">
            <template v-if="data">
              <template v-if="urgent">{{ urgent }} kind{{ urgent === 1 ? '' : 's' }} of thing{{ urgent === 1 ? ' is' : 's are' }} waiting for you.</template>
              <template v-else>Everything is up to date.</template>
            </template>
          </p>
          <p v-if="auth.isSuperAdmin" class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-navy-100 backdrop-blur">
            <ShieldCheckIcon class="h-4 w-4 text-saffron-300" aria-hidden="true" />Every change you make is sent to the other office accounts
          </p>
          <div class="mt-5 flex flex-wrap gap-2">
            <RouterLink v-can="'money'" :to="{ name: 'payroll' }" class="btn-accent"><ArrowUpTrayIcon class="h-4 w-4" />Post this month's payroll</RouterLink>
            <RouterLink v-can="'members'" :to="{ name: 'member-import' }" class="btn border border-white/25 bg-white/10 text-white backdrop-blur hover:bg-white/20"><UserPlusIcon class="h-4 w-4" />Import members</RouterLink>
            <RouterLink v-can="'notices'" :to="{ name: 'notices' }" class="btn border border-white/25 bg-white/10 text-white backdrop-blur hover:bg-white/20"><MegaphoneIcon class="h-4 w-4" />Post a notice</RouterLink>
          </div>
        </div>
        <div v-if="data" class="rounded-2xl border border-white/15 bg-white/10 p-5 backdrop-blur">
          <div class="flex items-start justify-between gap-3">
            <p class="text-xs font-semibold uppercase tracking-wider text-navy-100/80">Member funds</p>
            <span v-if="fundsChange !== null" class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="fundsChange >= 0 ? 'bg-emerald-400/20 text-emerald-200' : 'bg-red-400/25 text-red-100'">{{ fundsChange >= 0 ? '+' : '' }}{{ fundsChange }}% this month</span>
          </div>
          <p class="mt-1 text-3xl font-bold sm:text-4xl"><CountUp :value="funds" :duration="1500" /></p>
          <Sparkline class="mt-3 w-full" :values="data.funds.map((p) => Number(p.value))" color="#f2b934" label="Member funds" :width="320" :height="48" />
          <p class="mt-2 text-xs text-navy-100/80">Savings {{ formatMoney(data.totals.savings) }} · Shares {{ formatMoney(data.totals.shares) }}</p>
        </div>
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
        <div class="reveal card flex items-center gap-4 p-5" data-tilt>
          <span class="relative inline-flex h-20 w-20 shrink-0 items-center justify-center">
            <svg viewBox="0 0 100 100" class="h-20 w-20 -rotate-90" aria-hidden="true">
              <circle cx="50" cy="50" r="42" fill="none" stroke-width="10" class="stroke-gray-100" />
              <circle cx="50" cy="50" r="42" fill="none" stroke-width="10" stroke-linecap="round" class="ring-fill stroke-emerald-600" :style="{ '--ring': ringLen }" :stroke-dasharray="ringLen" :stroke-dashoffset="ringLen * (1 - onTime / 100)" />
            </svg>
            <span class="absolute text-base font-bold"><CountUp :value="onTime" :format="(n: number) => `${n}%`" /></span>
          </span>
          <div>
            <p class="eyebrow">Loans on time</p>
            <p class="mt-1 text-sm text-gray-600">How much of what is owed isn't late</p>
          </div>
        </div>
        <div class="reveal card p-5" style="--i: 1">
          <div class="float-right"><Sparkline :values="data.collections.map((p) => Number(p.value))" :color="chartColors.amber" label="Repayments collected" :width="84" :height="34" /></div>
          <p class="eyebrow">Loans outstanding</p>
          <p class="figure mt-2 text-2xl"><CountUp :value="data.totals.loans_outstanding" :delay="150" /></p>
          <p class="mt-1 text-xs text-gray-500">{{ data.totals.active_loans }} active loan{{ data.totals.active_loans === 1 ? '' : 's' }}</p>
        </div>
        <RouterLink :to="{ name: 'reports' }" class="reveal card p-5 transition hover:shadow-lift" style="--i: 2" :class="{ '!border-red-200 !bg-red-50/60': data.totals.loans_in_arrears }">
          <p class="eyebrow flex items-center gap-1.5" :class="{ '!text-red-700': data.totals.loans_in_arrears }">
            <ExclamationTriangleIcon v-if="data.totals.loans_in_arrears" class="h-4 w-4" aria-hidden="true" />In arrears
          </p>
          <p class="figure mt-2 text-2xl" :class="{ '!text-red-700': data.totals.loans_in_arrears }"><CountUp :value="data.totals.arrears" :delay="300" /></p>
          <p class="mt-1 text-xs text-gray-500">
            {{ data.totals.loans_in_arrears ? `${data.totals.loans_in_arrears} loan${data.totals.loans_in_arrears === 1 ? '' : 's'} behind on repayments` : 'Every loan is up to date' }}
          </p>
        </RouterLink>
        <RouterLink :to="{ name: 'users' }" class="reveal card p-5 transition hover:shadow-lift" style="--i: 3">
          <p class="eyebrow">Active members</p>
          <p class="figure mt-2 text-2xl"><CountUp :value="data.totals.members" :format="(n: number) => n.toLocaleString()" :delay="450" /></p>
          <p class="mt-1 text-xs text-gray-500">{{ data.totals.new_members_this_month }} joined this month</p>
        </RouterLink>
      </section>

      <!-- Trends -->
      <div class="mt-8 flex items-center justify-between gap-3">
        <h2 class="text-lg font-semibold">Trends</h2>
        <div class="flex rounded-lg bg-gray-200/60 p-0.5 text-xs font-semibold" role="radiogroup" aria-label="Period">
          <button v-for="r in ([6, 12] as const)" :key="r" type="button" role="radio" :aria-checked="range === r" class="rounded-md px-3 py-1" :class="range === r ? 'bg-surface text-ink shadow-sm' : 'text-gray-600'" @click="range = r">Last {{ r }} months</button>
        </div>
      </div>
      <section class="mt-3 grid gap-4 xl:grid-cols-5">
        <div class="reveal card p-5 xl:col-span-3 min-w-0" style="--i: 4">
          <div class="flex items-baseline justify-between gap-2">
            <h2 class="font-semibold">Member funds</h2>
            <p class="text-xs text-gray-500">Savings + shares at each month end</p>
          </div>
          <MonthChart :key="'f' + range" class="mt-4" :points="funds6" kind="area" :color="chartColors.blue" label="Member funds" :format="money" />
        </div>
        <div class="reveal card p-5 xl:col-span-2 min-w-0" style="--i: 5">
          <div class="flex items-baseline justify-between gap-2">
            <h2 class="font-semibold">Loan repayments</h2>
            <p class="text-xs text-gray-500">Collected each month</p>
          </div>
          <MonthChart :key="'c' + range" class="mt-4" :points="collections6" kind="bar" :color="chartColors.amber" label="Loan repayments" :format="money" />
        </div>
      </section>

      <!-- This month, loans, activity -->
      <section class="mt-6 grid gap-4 xl:grid-cols-3">
        <div class="reveal card p-5" style="--i: 6">
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

        <div class="reveal card p-5" style="--i: 7">
          <div class="flex items-center justify-between">
            <h2 class="font-semibold">Loans</h2>
            <RouterLink :to="{ name: 'admin-loans' }" class="text-sm font-semibold text-brand hover:underline">All loans</RouterLink>
          </div>
          <DonutChart v-if="loanCount" class="mt-4" :slices="slices" :active="hoverStatus" :center="String(openLoans)" caption="open loans" @hover="(k) => (hoverStatus = k as LoanStatus | null)" />
          <ul class="mt-3 divide-y divide-line text-sm">
            <li
              v-for="s in statusOrder"
              :key="s"
              class="-mx-2 flex items-center justify-between rounded-lg px-2 py-2.5 transition-colors"
              :class="{ 'bg-gray-50': hoverStatus === s }"
              @pointerenter="hoverStatus = s"
              @pointerleave="hoverStatus = null"
            >
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

        <div class="reveal card p-5" style="--i: 8">
          <div class="flex items-center justify-between">
            <h2 class="font-semibold">Latest activity</h2>
            <RouterLink :to="{ name: 'reports' }" class="text-sm font-semibold text-brand hover:underline">Audit log</RouterLink>
          </div>
          <div class="mt-3 flex flex-wrap gap-1.5" role="radiogroup" aria-label="Show">
            <button
              v-for="f in filters"
              :key="f.key"
              type="button"
              role="radio"
              :aria-checked="filter === f.key"
              class="rounded-full px-2.5 py-1 text-xs font-semibold transition"
              :class="filter === f.key ? 'bg-brand text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
              @click="filter = f.key"
            >{{ f.label }}</button>
          </div>
          <ol class="mt-3 max-h-80 space-y-1 overflow-y-auto overflow-x-hidden pl-2 pr-1 text-sm [scrollbar-width:thin]">
            <li v-for="(r, i) in activity" :key="i">
              <component :is="r.link ? 'RouterLink' : 'div'" :to="r.link ?? undefined" class="-mx-2 flex gap-3 rounded-lg px-2 py-1.5" :class="{ 'hover:bg-gray-50': r.link }">
                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="r.actor_role === 'super_admin' ? 'bg-saffron-500' : 'bg-navy-200'" aria-hidden="true" />
                <span class="flex-1">
                  <span class="font-semibold">{{ r.actor }}</span>
                  <span v-if="r.actor_role === 'super_admin'" class="ml-1 whitespace-nowrap rounded bg-saffron-100 px-1 py-px text-[10px] font-semibold uppercase tracking-wide text-saffron-700">Super admin</span>&#32;<span class="text-gray-700">{{ r.text }}</span>
                  <span class="block text-xs text-gray-500">{{ ago(r.at) }}</span>
                </span>
              </component>
            </li>
            <li v-if="!activity.length" class="py-2 text-gray-500">Nothing here yet.</li>
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
