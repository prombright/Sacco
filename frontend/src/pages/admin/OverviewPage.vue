<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  ArrowDownLeftIcon,
  ArrowUpRightIcon,
  ArrowUpTrayIcon,
  CalendarDaysIcon,
  ChartBarIcon,
  CircleStackIcon,
  ArrowTrendingUpIcon,
  ClipboardDocumentCheckIcon,
  InboxArrowDownIcon,
  Square3Stack3DIcon,
  UserGroupIcon,
  UserIcon,
  BanknotesIcon,
  ShieldCheckIcon,
  CheckCircleIcon,
  ChevronRightIcon,
  ExclamationTriangleIcon,
  MegaphoneIcon,
  UsersIcon,
} from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import type { Waiting } from '@/stores/office'
import { formatMoney } from '@/money'
import { loanStatus } from '@/loans'
import type { LoanStatus } from '@/types'
import MonthChart from '@/components/MonthChart.vue'
import CountUp from '@/components/CountUp.vue'
import GoLiveCard from '@/components/GoLiveCard.vue'
import DonutChart from '@/components/DonutChart.vue'
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
const chartColors = computed(() => theme.chart)
const data = ref<Overview | null>(null)
const error = ref('')

onMounted(async () => {
  try {
    data.value = await api.get<Overview>('/office/overview')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the overview.'
  }
})

const monthName = new Date().toLocaleDateString('en-GB', { month: 'long' })

const funds = computed(() => (data.value ? Number(data.value.totals.savings) + Number(data.value.totals.shares) : 0))

/** The to-do list: only what has something waiting */
const lastMonth = new Date(new Date().getFullYear(), new Date().getMonth() - 1, 1).toLocaleDateString('en-GB', { month: 'long' })
const tasks = computed(() => {
  const w = data.value?.waiting
  if (!w) return []
  return [
    { n: w.loans_to_approve, icon: ClipboardDocumentCheckIcon, tone: 'blue', text: 'loan application', verb: 'to approve', to: { name: 'admin-loans' } },
    { n: w.loans_to_pay_out, icon: BanknotesIcon, tone: 'blue', text: 'approved loan', verb: 'to pay out', to: { name: 'admin-loans' } },
    { n: w.withdrawals_to_approve, icon: InboxArrowDownIcon, tone: 'violet', text: 'withdrawal request', verb: 'to approve', to: { name: 'admin-withdrawals' } },
    { n: w.withdrawals_to_pay, icon: BanknotesIcon, tone: 'violet', text: 'withdrawal cheque', verb: 'to issue', to: { name: 'admin-withdrawals' } },
    { n: w.payroll_missing, icon: CalendarDaysIcon, tone: 'amber', text: `${lastMonth} payroll`, verb: 'not posted yet', to: { name: 'payroll' }, single: true },
    { n: w.loans_behind, icon: UsersIcon, tone: 'blue', text: 'borrower', verb: 'behind on repayments', to: { name: 'loan-portfolio' } },
    { n: w.securities_to_return, icon: ShieldCheckIcon, tone: 'green', text: 'loan security', verb: 'to hand back (loan repaid)', to: { name: 'admin-loans' } },
    { n: w.loans_waiting_guarantors, icon: UserGroupIcon, tone: 'violet', text: 'loan', verb: 'waiting for guarantors', to: { name: 'admin-loans' }, quiet: true },
    { n: w.subscriptions_unpaid, icon: Square3Stack3DIcon, tone: 'green', text: 'subscription', verb: 'unpaid this year', to: { name: 'membership-fees' }, quiet: true },
    { n: w.members_not_signed_in, icon: UserIcon, tone: 'violet', text: 'member', verb: 'yet to log in for the first time', to: { name: 'users' }, quiet: true },
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

// The banner: a live clock beside the greeting
// a live clock, as on the old banner
const clock = ref('')
const tick = () => (clock.value = new Date().toLocaleString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit', second: '2-digit' }).replace(' at ', ', '))
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

/** The last six months of repayments as bar heights (percent of the biggest; a sliver for none) */
const bars = computed(() => {
  const v = (data.value?.collections ?? []).slice(-6).map((p) => Number(p.value))
  const max = Math.max(...v, 0)
  return v.map((n) => (max ? Math.max(8, Math.round((n / max) * 100)) : 8))
})
</script>

<template>
  <div>
    <!-- The page's top: title, welcome and the clock, the quick actions on the right -->
    <header class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
      <div class="min-w-0">
        <h1 class="text-[1.6rem] font-extrabold leading-tight tracking-[-0.015em] text-[#16133a] dark:text-white sm:text-[2rem]">Dashboard</h1>
        <p class="mt-0.5 text-[0.95rem] text-gray-500">Welcome back, {{ auth.user?.full_name.split(' ')[0] }} · <span class="tabular-nums">{{ clock }}</span></p>
      </div>
      <div class="quick-actions max-sm:w-full">
        <RouterLink v-can="'money'" :to="{ name: 'payroll' }" class="pill-action pill-action-main"><CalendarDaysIcon /><span>Post <span class="hidden sm:inline">this month's </span>payroll</span></RouterLink>
        <RouterLink v-can="'members'" :to="{ name: 'member-import' }" class="pill-action"><ArrowUpTrayIcon /><span>Import members</span></RouterLink>
        <RouterLink v-can="'notices'" :to="{ name: 'notices' }" class="pill-action"><MegaphoneIcon /><span>Post a notice</span></RouterLink>
      </div>
    </header>

    <div v-if="error" class="mt-6 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <GoLiveCard v-if="auth.isSuperAdmin" class="mt-6" />

    <template v-if="data">
      <div class="mt-5 grid gap-4 xl:grid-cols-[minmax(0,1fr)_26rem]">
      <!-- Needs your attention -->
      <section class="card" aria-labelledby="todo">
        <div class="card-head">
          <h2 id="todo">Today</h2>
          <span v-if="urgent" class="icon-gold rounded-full px-2.5 py-0.5 text-sm font-bold">{{ urgent }}</span>
          <p class="ml-auto text-sm font-medium text-[#6246ea] dark:text-navy-200">{{ urgent ? `${urgent} thing${urgent === 1 ? '' : 's'} to act on` : 'Nothing urgent' }}</p>
        </div>
        <div class="p-4">
        <div v-if="tasks.length" class="grid grid-cols-2 gap-2.5 sm:gap-3">
          <RouterLink
            v-for="t in tasks"
            :key="t.text + t.verb"
            :to="t.to"
            class="group flex flex-col items-start gap-2.5 rounded-2xl border p-3 transition hover:-translate-y-0.5 hover:shadow-lift active:scale-[0.99] sm:flex-row sm:items-center sm:gap-4 sm:p-4"
            :class="`task-${t.tone}`"
          >
            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl" :class="`tile-${t.tone}`">
              <span v-if="t.single" class="text-xl font-extrabold" aria-hidden="true">!</span>
              <component :is="t.icon" v-else class="h-[22px] w-[22px]" aria-hidden="true" />
            </span>
            <span class="min-w-0 flex-1 leading-snug">
              <template v-if="t.single"><span class="block font-bold">{{ t.text }}</span><span class="block text-[13px] text-gray-600 sm:text-sm">{{ t.verb }}</span></template>
              <template v-else><span class="block text-[1.35rem] font-extrabold leading-tight tabular-nums">{{ t.n.toLocaleString() }}</span><span class="block text-[13px] text-gray-600 sm:text-sm">{{ t.text }}{{ t.n === 1 ? '' : 's' }} {{ t.verb }}</span></template>
            </span>
            <ChevronRightIcon class="hidden h-5 w-5 shrink-0 text-gray-400 transition group-hover:translate-x-0.5 group-hover:text-gray-700 sm:block" aria-hidden="true" />
          </RouterLink>
        </div>
        <div v-else class="flex items-center gap-3 rounded-2xl border border-line bg-surface p-4 text-sm text-gray-700">
          <CheckCircleIcon class="h-6 w-6 text-emerald-600" aria-hidden="true" />
          Nothing is waiting: no loans or withdrawals to approve, and no cheques to issue.
        </div>
        </div>
      </section>

      <!-- Member funds: the total, its change on last month, its trend, and what it is made of -->
      <section class="card flex flex-col" aria-labelledby="funds">
        <div class="card-head">
          <span class="icon-gold inline-flex h-8 w-8 items-center justify-center rounded-lg"><CircleStackIcon class="h-[18px] w-[18px]" aria-hidden="true" /></span><h2 id="funds">Member funds</h2>
          <span v-if="fundsChange !== null" class="ml-auto rounded-full px-2 py-0.5 text-xs font-semibold" :class="fundsChange > 0 ? 'bg-emerald-50 text-emerald-700' : fundsChange < 0 ? 'bg-red-50 text-red-700' : 'bg-white text-gray-600 dark:bg-white/10'">{{ fundsChange === 0 ? 'Same as last month' : `${fundsChange > 0 ? '+' : ''}${fundsChange}% this month` }}</span>
        </div>
        <div class="flex flex-1 flex-col p-5">
          <p class="figure text-3xl"><CountUp :value="funds" :duration="1500" /></p>
          <Sparkline class="mt-3 w-full" :values="data.funds.map((p) => Number(p.value))" :color="chartColors.blue" label="Member funds" :width="360" :height="72" />
          <dl class="mt-auto grid grid-cols-2 border-t border-line pt-4 text-sm">
            <div><dt class="text-gray-500">Savings</dt><dd class="mt-0.5 text-[0.95rem] font-bold tabular-nums">{{ formatMoney(data.totals.savings) }}</dd></div>
            <div class="border-l border-line pl-5"><dt class="text-gray-500">Shares</dt><dd class="mt-0.5 text-[0.95rem] font-bold tabular-nums">{{ formatMoney(data.totals.shares) }}</dd></div>
          </dl>
        </div>
      </section>
      </div>

      <!-- Headline figures: the same card as everywhere (gold tile, label, figure, detail), each with
           one accent colour that means something: green healthy, blue money out, red late, navy people -->
      <section class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Headline figures">
        <div class="reveal stat-card">
          <div class="stat-card-top">
            <span class="tile-green stat-card-icon"><ShieldCheckIcon class="h-5 w-5" aria-hidden="true" /></span>
            <p class="stat-card-label">Loans on time</p>
          </div>
          <div class="mt-3 flex items-center gap-4">
            <span class="relative inline-flex h-16 w-16 shrink-0 items-center justify-center">
              <svg viewBox="0 0 100 100" class="h-16 w-16 -rotate-90" aria-hidden="true">
                <circle cx="50" cy="50" r="42" fill="none" stroke-width="11" class="stroke-gray-100" />
                <circle cx="50" cy="50" r="42" fill="none" stroke-width="11" stroke-linecap="round" class="ring-fill stroke-emerald-500" :style="{ '--ring': ringLen }" :stroke-dasharray="ringLen" :stroke-dashoffset="ringLen * (1 - onTime / 100)" />
              </svg>
              <span class="absolute text-sm font-extrabold text-emerald-700"><CountUp :value="onTime" :format="(n: number) => `${n}%`" /></span>
            </span>
            <p class="text-sm text-gray-600">Of what members owe, this much isn't late</p>
          </div>
        </div>
        <RouterLink :to="{ name: 'loan-portfolio' }" class="reveal stat-card" style="--i: 1">
          <div class="stat-card-top">
            <span class="tile-blue stat-card-icon"><BanknotesIcon class="h-5 w-5" aria-hidden="true" /></span>
            <p class="stat-card-label">Loans outstanding</p>
          </div>
          <p class="stat-card-figure text-[#2f6bff] dark:text-[#8fb0ff]"><CountUp :value="data.totals.loans_outstanding" :delay="150" /></p>
          <div class="mt-auto flex items-end justify-between gap-2 pt-2">
            <p class="stat-card-note !mt-0">{{ data.totals.active_loans }} active loan{{ data.totals.active_loans === 1 ? '' : 's' }}</p>
            <span class="flex h-10 items-end gap-1" role="img" :aria-label="`Repayments collected, the last ${bars.length} months`">
              <span v-for="(v, i) in bars" :key="i" class="w-2 rounded-sm bg-[#2f6bff]" :style="{ height: v + '%', opacity: 0.35 + (0.65 * (i + 1)) / bars.length }" />
            </span>
          </div>
        </RouterLink>
        <RouterLink :to="{ name: 'reports' }" class="reveal stat-card" style="--i: 2" :class="{ 'stat-card-alert': data.totals.loans_in_arrears }">
          <div class="stat-card-top">
            <span class="tile-red stat-card-icon"><ExclamationTriangleIcon class="h-5 w-5" aria-hidden="true" /></span>
            <p class="stat-card-label">In arrears</p>
            <span v-if="data.totals.loans_in_arrears && Number(data.totals.loans_outstanding)" class="ml-auto rounded-full bg-red-50 px-2 py-0.5 text-xs font-bold tabular-nums text-red-700">{{ Math.round((Number(data.totals.arrears) / Number(data.totals.loans_outstanding)) * 100) }}%</span>
          </div>
          <p class="stat-card-figure" :class="data.totals.loans_in_arrears ? 'text-red-600' : 'text-emerald-700'"><CountUp :value="data.totals.arrears" :delay="300" /></p>
          <p class="stat-card-note">
            {{ data.totals.loans_in_arrears ? `${data.totals.loans_in_arrears} loan${data.totals.loans_in_arrears === 1 ? '' : 's'} behind on repayments` : 'Every loan is up to date' }}
          </p>
        </RouterLink>
        <RouterLink :to="{ name: 'users' }" class="reveal stat-card" style="--i: 3">
          <div class="stat-card-top">
            <span class="icon-gold stat-card-icon"><UsersIcon class="h-5 w-5" aria-hidden="true" /></span>
            <p class="stat-card-label">Active members</p>
          </div>
          <p class="stat-card-figure text-[#16133a] dark:text-white"><CountUp :value="data.totals.members" :format="(n: number) => n.toLocaleString()" :delay="450" /></p>
          <p class="stat-card-note"><span v-if="data.totals.new_members_this_month" class="mr-1 rounded-full bg-[#f1edff] px-1.5 py-0.5 font-bold text-[#6246ea] dark:bg-white/10 dark:text-navy-200">+{{ data.totals.new_members_this_month }}</span>joined this month</p>
        </RouterLink>
      </section>

      <!-- Trends -->
      <div class="mt-8 flex items-center justify-between gap-3">
        <h2 class="text-lg font-semibold">Trends</h2>
        <div class="flex gap-1 rounded-xl border border-line bg-surface/70 p-1 text-xs font-semibold" role="radiogroup" aria-label="Period">
          <button v-for="r in ([6, 12] as const)" :key="r" type="button" role="radio" :aria-checked="range === r" class="rounded-md px-3 py-1" :class="range === r ? 'bg-surface text-[#6246ea] shadow-sm ring-1 ring-[#6246ea]/40' : 'text-gray-600'" @click="range = r">Last {{ r }} months</button>
        </div>
      </div>
      <section class="mt-3 grid gap-4 xl:grid-cols-5">
        <div class="reveal card xl:col-span-3 min-w-0" style="--i: 4">
          <div class="card-head">
            <span class="icon-gold inline-flex h-8 w-8 items-center justify-center rounded-lg"><ArrowTrendingUpIcon class="h-[18px] w-[18px]" aria-hidden="true" /></span><h2>Member funds</h2>
            <p class="ml-auto text-xs text-gray-600">Savings + shares at each month end</p>
          </div>
          <MonthChart :key="'f' + range" class="p-5" :points="funds6" kind="area" :color="chartColors.blue" label="Member funds" :format="money" />
        </div>
        <div class="reveal card xl:col-span-2 min-w-0" style="--i: 5">
          <div class="card-head">
            <span class="icon-gold inline-flex h-8 w-8 items-center justify-center rounded-lg"><ChartBarIcon class="h-[18px] w-[18px]" aria-hidden="true" /></span><h2>Loan repayments</h2>
            <p class="ml-auto text-xs text-gray-600">Collected each month</p>
          </div>
          <MonthChart :key="'c' + range" class="p-5" :points="collections6" kind="bar" :color="chartColors.amber" label="Loan repayments" :format="money" />
        </div>
      </section>

      <!-- This month, loans, activity -->
      <section class="mt-6 grid gap-4 xl:grid-cols-3">
        <div class="reveal card" style="--i: 6">
          <div class="card-head"><h2>{{ monthName }} so far</h2></div>
          <div class="space-y-4 p-5 text-sm">
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

        <div class="reveal card" style="--i: 7">
          <div class="card-head">
            <h2>Loans</h2>
            <RouterLink :to="{ name: 'admin-loans' }" class="card-head-link">All loans</RouterLink>
          </div>
          <div class="p-5 pt-1">
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
        </div>

        <div class="reveal card" style="--i: 8">
          <div class="card-head">
            <h2>Latest activity</h2>
            <RouterLink :to="{ name: 'reports' }" class="card-head-link">Audit log</RouterLink>
          </div>
          <div class="p-5 pt-3">
          <div class="flex flex-wrap gap-1.5" role="radiogroup" aria-label="Show">
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
                  <span v-if="r.actor_role === 'super_admin'" class="ml-1 whitespace-nowrap rounded bg-saffron-100 px-1 py-px text-[11px] font-semibold uppercase tracking-wide text-saffron-700">Super admin</span>&#32;<span class="text-gray-700">{{ r.text }}</span>
                  <span class="block text-xs text-gray-500">{{ ago(r.at) }}</span>
                </span>
              </component>
            </li>
            <li v-if="!activity.length" class="py-2 text-gray-500">Nothing here yet.</li>
          </ol>
          </div>
        </div>
      </section>
    </template>

    <!-- Loading -->
    <div v-else-if="!error" class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-hidden="true">
      <div v-for="i in 4" :key="i" class="h-28 animate-pulse rounded-2xl bg-surface" />
    </div>
  </div>
</template>
