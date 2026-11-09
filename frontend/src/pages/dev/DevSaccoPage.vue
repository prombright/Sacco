<script setup lang="ts">
import { ask } from '@/confirm'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowLeftIcon,
  ArrowPathIcon,
  CheckCircleIcon,
  ExclamationTriangleIcon,
  KeyIcon,
  MagnifyingGlassIcon,
  NoSymbolIcon,
  PlusIcon,
} from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { formatMoney } from '@/money'
import { loanStatus } from '@/loans'
import type { LoanStatus } from '@/types'
import { useTheme } from '@/stores/theme'
import MonthChart from '@/components/MonthChart.vue'
import Modal from '@/components/Modal.vue'
import { ago, type DevSacco, type PortalLogRow, type SaccoStats } from './dev'

interface OfficeAccount {
  id: number
  full_name: string
  email: string | null
  employee_no: string | null
  role: 'super_admin' | 'admin'
  role_label: string
  status: string
  must_change_password: boolean
  last_login_at: string | null
}
interface Detail {
  sacco: DevSacco & { db_name: string }
  stats: SaccoStats
  office: OfficeAccount[]
  rules: { key: string; label: string; value: string; updated_at: string | null }[]
  loans_by_status: Partial<Record<LoanStatus, { count: number; amount: string }>>
  failed_sign_ins: { login: string; reason: string | null; ip: string | null; created_at: string }[]
  pending_updates: string[]
  portal_log: PortalLogRow[]
}
interface MemberRow {
  id: number
  full_name: string
  email: string | null
  employee_no: string | null
  account_no: string | null
  role: string
  status: string
  last_login_at: string | null
  savings: string | null
  shares: string | null
  active_loans: number
}
interface ActivityRow {
  id: number
  actor: string
  actor_role: string | null
  text: string
  details: string
  ip: string | null
  at: string
}

/** One SACCO in full: its figures, people, everything that happened, and looking after it */
const route = useRoute()
const router = useRouter()
const theme = useTheme()
const blue = computed(() => (theme.dark ? '#6b78ec' : '#3a46c4'))
const id = computed(() => Number(route.params.id))
const data = ref<Detail | null>(null)
const error = ref('')
const money = (v: number) => formatMoney(String(v))

async function load() {
  try {
    data.value = await api.get<Detail>(`/dev/saccos/${id.value}`)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load this SACCO.'
  }
}
onMounted(load)

const tabs = [
  { key: 'overview', label: 'Overview' },
  { key: 'members', label: 'Members' },
  { key: 'office', label: 'Office accounts' },
  { key: 'activity', label: 'Everything that happened' },
  { key: 'rules', label: 'Rules' },
  { key: 'details', label: 'Details & look' },
  { key: 'system', label: 'System' },
] as const
type Tab = (typeof tabs)[number]['key']
const tab = computed<Tab>(() => (tabs.some((t) => t.key === route.query.tab) ? (route.query.tab as Tab) : 'overview'))
const setTab = (t: Tab) => router.replace({ query: { ...route.query, tab: t } })

// --- Members ---------------------------------------------------------------------------------
const members = ref<{ members: MemberRow[]; total: number; page: number; per_page: number } | null>(null)
const q = ref('')
const page = ref(1)
let searchTimer: ReturnType<typeof setTimeout> | undefined
async function loadMembers() {
  members.value = await api.get(`/dev/saccos/${id.value}/members`, { q: q.value, page: page.value })
}
watch(q, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; loadMembers() }, 250)
})
watch(page, loadMembers)

// --- Activity --------------------------------------------------------------------------------
const activity = ref<ActivityRow[]>([])
const activityPage = ref(1)
const moreActivity = ref(false)
async function loadActivity(next = false) {
  if (next) activityPage.value++
  const res = await api.get<{ activity: ActivityRow[]; has_more: boolean }>(`/dev/saccos/${id.value}/activity`, { page: activityPage.value })
  activity.value = next ? [...activity.value, ...res.activity] : res.activity
  moreActivity.value = res.has_more
}

watch(tab, (t) => {
  if (t === 'members' && !members.value) loadMembers()
  if (t === 'activity' && !activity.value.length) loadActivity()
}, { immediate: true })

// --- Office accounts -------------------------------------------------------------------------
const adding = ref(false)
const newAccount = ref({ full_name: '', email: '', role: 'admin' as 'admin' | 'super_admin' })
const secret = ref<{ who: string; login: string; password: string } | null>(null)
const problem = ref('')
async function addAccount() {
  problem.value = ''
  try {
    const res = await api.post<{ email: string; temporary_password: string }>(`/dev/saccos/${id.value}/office-accounts`, newAccount.value)
    secret.value = { who: newAccount.value.full_name, login: res.email, password: res.temporary_password }
    adding.value = false
    newAccount.value = { full_name: '', email: '', role: 'admin' }
    await load()
  } catch (e) {
    problem.value = e instanceof ApiError ? e.message : 'Couldn\'t add the account.'
  }
}
async function resetPassword(u: { id: number; full_name: string }) {
  if (!await ask(`Give ${u.full_name} a new temporary password? Their current one stops working at once, and the SACCO's office is told.`)) return
  const res = await api.post<{ temporary_password: string; login: string }>(`/dev/saccos/${id.value}/users/${u.id}/reset-password`)
  secret.value = { who: u.full_name, login: res.login, password: res.temporary_password }
  await load()
}
async function setStatus(u: OfficeAccount, status: 'active' | 'suspended') {
  if (status === 'suspended' && !await ask(`Suspend ${u.full_name}? They are signed out and can't sign in until reactivated.`)) return
  data.value = await api.post<Detail>(`/dev/saccos/${id.value}/users/${u.id}/status`, { status })
}

// --- Details ---------------------------------------------------------------------------------
const form = ref<Record<string, string>>({})
const saved = ref(false)
const formError = ref('')
watch(data, (d) => {
  if (!d) return
  const s = d.sacco
  form.value = {
    name: s.name, short_name: s.short_name ?? '', domain: s.domain ?? '', slogan: s.slogan ?? '', about: s.about ?? '',
    brand_color: s.brand_color, currency: s.currency, login_image: s.login_image ?? '', logo_url: s.logo_url ?? '', status: s.status,
  }
}, { immediate: true })
async function saveDetails() {
  formError.value = ''
  saved.value = false
  try {
    data.value = await api.patch<Detail>(`/dev/saccos/${id.value}`, form.value)
    saved.value = true
  } catch (e) {
    formError.value = e instanceof ApiError ? e.message : 'Couldn\'t save.'
  }
}
async function togglePaused() {
  const pausing = data.value?.sacco.status === 'active'
  if (pausing && !await ask('Pause this SACCO? Nobody in it can sign in until it is made active again.')) return
  data.value = await api.patch<Detail>(`/dev/saccos/${id.value}`, { status: pausing ? 'paused' : 'active' })
}

// --- System ----------------------------------------------------------------------------------
const updating = ref(false)
const updateResult = ref('')
async function runUpdates() {
  updating.value = true
  updateResult.value = ''
  try {
    const res = await api.post<{ applied: string[] }>(`/dev/saccos/${id.value}/update-system`)
    updateResult.value = res.applied.length ? `Applied ${res.applied.join(', ')}.` : 'Already up to date.'
    await load()
  } catch (e) {
    updateResult.value = e instanceof ApiError ? e.message : 'The update failed.'
  } finally {
    updating.value = false
  }
}

const statusOrder: LoanStatus[] = ['pending_guarantors', 'pending_approval', 'approved', 'active', 'closed', 'rejected', 'cancelled']
const waitingList = computed(() => {
  const w = data.value?.stats.waiting
  if (!w) return []
  return [
    { n: w.loans_to_approve, t: 'Loan applications to approve' },
    { n: w.loans_to_pay_out, t: 'Approved loans to pay out' },
    { n: w.loans_waiting_guarantors, t: 'Loans waiting for guarantors' },
    { n: w.withdrawals_to_approve, t: 'Withdrawals to approve' },
    { n: w.withdrawals_to_pay, t: 'Withdrawal cheques to issue' },
    { n: w.members_not_signed_in, t: 'Members yet to sign in' },
  ]
})
</script>

<template>
  <div>
    <RouterLink :to="{ name: 'dev-home' }" class="inline-flex items-center gap-1 text-sm font-semibold text-gray-600 hover:text-ink"><ArrowLeftIcon class="h-4 w-4" />All SACCOs</RouterLink>
    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <template v-if="data">
      <div class="mt-3 flex flex-wrap items-center gap-4">
        <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl text-lg font-bold text-white shadow-lift" :style="{ background: data.sacco.brand_color }" aria-hidden="true">
          {{ (data.sacco.short_name || data.sacco.name).replace(/sacco/i, '').trim().slice(0, 2).toUpperCase() }}
        </span>
        <div class="min-w-0 flex-1">
          <h1 class="text-2xl font-bold sm:text-3xl">{{ data.sacco.name }}</h1>
          <p class="text-sm text-gray-500">
            <span class="font-mono">{{ data.sacco.code }}</span> · database <span class="font-mono">{{ data.sacco.db_name }}</span><template v-if="data.sacco.domain"> · {{ data.sacco.domain }}</template>
          </p>
        </div>
        <span class="rounded-full px-3 py-1 text-sm font-semibold" :class="data.sacco.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600'">{{ data.sacco.status === 'active' ? 'Active' : 'Paused' }}</span>
        <button type="button" class="btn-secondary" @click="togglePaused">{{ data.sacco.status === 'active' ? 'Pause' : 'Make active' }}</button>
      </div>

      <!-- Tabs -->
      <div class="mt-6 overflow-x-auto border-b border-line [scrollbar-width:none]">
        <nav class="flex min-w-max gap-1" role="tablist">
          <button
            v-for="t in tabs"
            :key="t.key"
            type="button"
            role="tab"
            :aria-selected="tab === t.key"
            class="-mb-px border-b-2 px-3 py-2.5 text-sm font-semibold transition"
            :class="tab === t.key ? 'border-brand text-brand' : 'border-transparent text-gray-500 hover:text-ink'"
            @click="setTab(t.key)"
          >
            {{ t.label }}
            <span v-if="t.key === 'system' && data.pending_updates.length" class="ml-1 rounded-full bg-amber-100 px-1.5 text-xs text-amber-800">{{ data.pending_updates.length }}</span>
          </button>
        </nav>
      </div>

      <!-- Overview -->
      <section v-if="tab === 'overview'" class="mt-6 space-y-4">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <div class="relative overflow-hidden rounded-2xl p-5 text-white shadow-lift" :style="{ background: data.sacco.brand_color }">
            <div class="absolute -right-14 -top-16 h-32 w-32 rounded-full border-2 border-white/40" aria-hidden="true" />
            <p class="text-xs font-semibold uppercase tracking-wider text-white/80">Member funds</p>
            <p class="mt-2 text-2xl font-bold tabular-nums">{{ money(Number(data.stats.savings) + Number(data.stats.shares)) }}</p>
            <p class="mt-1 text-xs text-white/80">Savings {{ formatMoney(data.stats.savings) }} · Shares {{ formatMoney(data.stats.shares) }}</p>
          </div>
          <div class="card p-5">
            <p class="eyebrow">Loans outstanding</p>
            <p class="figure mt-2 text-2xl">{{ formatMoney(data.stats.loans_outstanding) }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ data.stats.active_loans }} active</p>
          </div>
          <div class="card p-5" :class="{ '!border-red-200 !bg-red-50/60': Number(data.stats.arrears) > 0 }">
            <p class="eyebrow">In arrears</p>
            <p class="figure mt-2 text-2xl" :class="{ '!text-red-700': Number(data.stats.arrears) > 0 }">{{ formatMoney(data.stats.arrears) }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ data.stats.loans_in_arrears }} loan{{ data.stats.loans_in_arrears === 1 ? '' : 's' }} behind</p>
          </div>
          <div class="card p-5">
            <p class="eyebrow">People</p>
            <p class="figure mt-2 text-2xl">{{ data.stats.members }}</p>
            <p class="mt-1 text-xs text-gray-500">members · {{ data.stats.office_accounts }} office · {{ data.stats.signed_in_30_days }} signed in this month</p>
          </div>
        </div>
        <div class="grid gap-4 xl:grid-cols-3">
          <div class="card min-w-0 p-5 xl:col-span-2">
            <h2 class="font-semibold">Member funds</h2>
            <MonthChart class="mt-4" :points="data.stats.funds" kind="area" :color="blue" label="Member funds" :format="money" />
          </div>
          <div class="card p-5">
            <h2 class="font-semibold">Waiting for the office</h2>
            <ul class="mt-3 divide-y divide-line text-sm">
              <li v-for="w in waitingList" :key="w.t" class="flex items-center justify-between py-2">
                <span class="text-gray-700">{{ w.t }}</span>
                <span class="font-semibold tabular-nums" :class="{ 'text-gray-300': !w.n }">{{ w.n }}</span>
              </li>
            </ul>
          </div>
        </div>
        <div class="grid gap-4 xl:grid-cols-2">
          <div class="card p-5">
            <h2 class="font-semibold">Loans by status</h2>
            <ul class="mt-3 divide-y divide-line text-sm">
              <li v-for="s in statusOrder.filter((k) => data!.loans_by_status[k])" :key="s" class="flex items-center justify-between py-2">
                <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full" :class="loanStatus[s].dot" aria-hidden="true" />{{ loanStatus[s].label }}</span>
                <span><span class="font-semibold tabular-nums">{{ data.loans_by_status[s]!.count }}</span> <span class="ml-2 text-xs tabular-nums text-gray-500">{{ formatMoney(data.loans_by_status[s]!.amount) }}</span></span>
              </li>
              <li v-if="!Object.keys(data.loans_by_status).length" class="py-2 text-gray-500">No loans yet.</li>
            </ul>
          </div>
          <div class="card p-5">
            <h2 class="font-semibold">Failed sign-ins</h2>
            <ul class="mt-3 divide-y divide-line text-sm">
              <li v-for="(f, i) in data.failed_sign_ins" :key="i" class="flex items-center justify-between gap-3 py-2">
                <span class="min-w-0 truncate"><span class="font-medium">{{ f.login }}</span> <span class="text-gray-500">· {{ (f.reason ?? '').replace('_', ' ') }}</span></span>
                <span class="shrink-0 text-xs text-gray-500">{{ ago(f.created_at) }}<template v-if="f.ip"> · {{ f.ip }}</template></span>
              </li>
              <li v-if="!data.failed_sign_ins.length" class="py-2 text-gray-500">None.</li>
            </ul>
          </div>
        </div>
      </section>

      <!-- Members -->
      <section v-else-if="tab === 'members'" class="mt-6">
        <div class="relative max-w-sm">
          <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" aria-hidden="true" />
          <input v-model="q" type="search" class="input pl-9" placeholder="Name, email, employee or account number" aria-label="Find a person" />
        </div>
        <div class="card mt-4 overflow-x-auto">
          <table class="w-full min-w-[720px] text-sm">
            <thead class="border-b border-line text-left text-xs uppercase tracking-wider text-gray-500">
              <tr><th class="px-4 py-3 font-semibold">Name</th><th class="px-4 py-3 font-semibold">Account</th><th class="px-4 py-3 text-right font-semibold">Savings</th><th class="px-4 py-3 text-right font-semibold">Shares</th><th class="px-4 py-3 text-right font-semibold">Loans</th><th class="px-4 py-3 font-semibold">Last signed in</th></tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="m in members?.members ?? []" :key="m.id">
                <td class="px-4 py-3">
                  <p class="font-semibold">{{ m.full_name }}</p>
                  <p class="text-xs text-gray-500">{{ m.email || m.employee_no }}<template v-if="m.role !== 'member'"> · <span class="font-semibold text-saffron-700">{{ m.role === 'super_admin' ? 'Super admin' : 'Admin' }}</span></template><template v-if="m.status !== 'active'"> · {{ m.status }}</template></p>
                </td>
                <td class="px-4 py-3 font-mono text-xs">{{ m.account_no }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ m.savings ? formatMoney(m.savings) : '—' }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ m.shares ? formatMoney(m.shares) : '—' }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ m.active_loans || '—' }}</td>
                <td class="px-4 py-3 text-gray-600">{{ ago(m.last_login_at) }}</td>
              </tr>
              <tr v-if="members && !members.members.length"><td colspan="6" class="px-4 py-8 text-center text-gray-500">Nobody matches.</td></tr>
            </tbody>
          </table>
        </div>
        <div v-if="members && members.total > members.per_page" class="mt-3 flex items-center justify-between text-sm">
          <span class="text-gray-500">{{ (page - 1) * members.per_page + 1 }}–{{ Math.min(page * members.per_page, members.total) }} of {{ members.total }}</span>
          <span class="flex gap-2">
            <button type="button" class="btn-secondary" :disabled="page === 1" @click="page--">Previous</button>
            <button type="button" class="btn-secondary" :disabled="page * members.per_page >= members.total" @click="page++">Next</button>
          </span>
        </div>
      </section>

      <!-- Office accounts -->
      <section v-else-if="tab === 'office'" class="mt-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <p class="max-w-2xl text-sm text-gray-600">Super admins set the rules and can do everything in the SACCO; admins run it day to day. Every change you make here is logged and sent to the SACCO's office accounts.</p>
          <button type="button" class="btn-primary" @click="adding = true"><PlusIcon class="h-4 w-4" />Add an office account</button>
        </div>
        <div class="card mt-4 divide-y divide-line">
          <div v-for="u in data.office" :key="u.id" class="flex flex-wrap items-center gap-3 p-4">
            <span class="inline-flex h-10 w-10 items-center justify-center rounded-full text-sm font-bold" :class="u.role === 'super_admin' ? 'bg-saffron-100 text-saffron-700' : 'bg-navy-50 text-navy-800'">{{ u.full_name.split(' ').map((w) => w[0]).slice(0, 2).join('') }}</span>
            <div class="min-w-0 flex-1">
              <p class="font-semibold">{{ u.full_name }} <span class="ml-1 rounded px-1.5 py-0.5 text-xs font-semibold" :class="u.role === 'super_admin' ? 'bg-saffron-100 text-saffron-700' : 'bg-navy-50 text-navy-800'">{{ u.role_label }}</span></p>
              <p class="text-sm text-gray-500">
                {{ u.email || u.employee_no }} · {{ u.status === 'active' ? (u.must_change_password ? 'yet to sign in' : 'last signed in ' + ago(u.last_login_at)) : 'suspended' }}
              </p>
            </div>
            <button type="button" class="btn-ghost" @click="resetPassword(u)"><KeyIcon class="h-4 w-4" />Reset password</button>
            <button v-if="u.status === 'active'" type="button" class="btn-ghost !text-red-700" @click="setStatus(u, 'suspended')"><NoSymbolIcon class="h-4 w-4" />Suspend</button>
            <button v-else type="button" class="btn-ghost" @click="setStatus(u, 'active')"><CheckCircleIcon class="h-4 w-4" />Reactivate</button>
          </div>
          <p v-if="!data.office.length" class="p-6 text-center text-sm text-gray-500">No office accounts.</p>
        </div>
      </section>

      <!-- Activity -->
      <section v-else-if="tab === 'activity'" class="mt-6">
        <p class="text-sm text-gray-600">The SACCO's full audit log: every action by its members, its office and the system provider.</p>
        <ol class="card mt-4 divide-y divide-line">
          <li v-for="a in activity" :key="a.id" class="flex gap-3 p-4 text-sm">
            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="a.actor_role === 'super_admin' ? 'bg-saffron-500' : a.actor === 'System provider' ? 'bg-red-500' : 'bg-navy-200'" aria-hidden="true" />
            <div class="min-w-0 flex-1">
              <p><span class="font-semibold">{{ a.actor }}</span> <span class="text-gray-700">{{ a.text }}</span></p>
              <p v-if="a.details" class="mt-1 whitespace-pre-line text-xs text-gray-500">{{ a.details }}</p>
            </div>
            <span class="shrink-0 text-right text-xs text-gray-500">{{ ago(a.at) }}<span v-if="a.ip" class="block">{{ a.ip }}</span></span>
          </li>
          <li v-if="!activity.length" class="p-6 text-center text-sm text-gray-500">Nothing yet.</li>
        </ol>
        <button v-if="moreActivity" type="button" class="btn-secondary mt-3" @click="loadActivity(true)">Show more</button>
      </section>

      <!-- Rules -->
      <section v-else-if="tab === 'rules'" class="mt-6">
        <p class="text-sm text-gray-600">Set by the SACCO's super admin on its Rules page. Shown here to help with questions.</p>
        <dl class="card mt-4 divide-y divide-line">
          <div v-for="r in data.rules" :key="r.key" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
            <dt class="text-gray-700">{{ r.label }}</dt>
            <dd class="text-right"><span class="font-semibold tabular-nums">{{ r.value }}</span> <span v-if="r.updated_at" class="ml-2 text-xs text-gray-500">changed {{ ago(r.updated_at) }}</span></dd>
          </div>
        </dl>
      </section>

      <!-- Details -->
      <section v-else-if="tab === 'details'" class="mt-6">
        <form class="card grid gap-4 p-6 sm:grid-cols-2" @submit.prevent="saveDetails">
          <div class="sm:col-span-2"><label for="s-name" class="label">Name</label><input id="s-name" v-model="form.name" class="input" /></div>
          <div><label for="s-short" class="label">Short name</label><input id="s-short" v-model="form.short_name" class="input" /></div>
          <div><label for="s-domain" class="label">Web address</label><input id="s-domain" v-model="form.domain" class="input" placeholder="sacco.example.org" /></div>
          <div class="sm:col-span-2"><label for="s-slogan" class="label">Slogan</label><input id="s-slogan" v-model="form.slogan" class="input" /></div>
          <div class="sm:col-span-2"><label for="s-about" class="label">About (on the homepage)</label><textarea id="s-about" v-model="form.about" rows="2" maxlength="255" class="input" /></div>
          <div>
            <label for="s-color" class="label">Brand colour</label>
            <div class="flex gap-2"><input id="s-color" v-model="form.brand_color" type="color" class="h-10 w-14 cursor-pointer rounded-lg border border-line bg-surface p-1" /><input v-model="form.brand_color" class="input font-mono" aria-label="Brand colour code" /></div>
          </div>
          <div><label for="s-cur" class="label">Currency</label><input id="s-cur" v-model="form.currency" maxlength="3" class="input uppercase" /></div>
          <div><label for="s-img" class="label">Sign-in photo</label><input id="s-img" v-model="form.login_image" class="input font-mono" placeholder="brand/photo.jpg" /></div>
          <div><label for="s-logo" class="label">Logo address</label><input id="s-logo" v-model="form.logo_url" class="input font-mono" /></div>
          <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
            <button type="submit" class="btn-primary">Save changes</button>
            <span v-if="saved" class="flex items-center gap-1 text-sm text-emerald-700"><CheckCircleIcon class="h-4 w-4" />Saved. The SACCO's office has been told.</span>
            <span v-if="formError" class="text-sm text-red-700" role="alert">{{ formError }}</span>
          </div>
        </form>
      </section>

      <!-- System -->
      <section v-else-if="tab === 'system'" class="mt-6 grid gap-4 xl:grid-cols-2">
        <div class="card p-5">
          <h2 class="font-semibold">System updates</h2>
          <template v-if="data.pending_updates.length">
            <p class="mt-2 flex items-center gap-2 text-sm text-amber-800"><ExclamationTriangleIcon class="h-5 w-5" />{{ data.pending_updates.length }} update{{ data.pending_updates.length === 1 ? '' : 's' }} to apply to this SACCO's database:</p>
            <ul class="mt-2 space-y-1 font-mono text-xs text-gray-600"><li v-for="f in data.pending_updates" :key="f">{{ f }}</li></ul>
            <button type="button" class="btn-primary mt-4" :disabled="updating" @click="runUpdates"><ArrowPathIcon class="h-4 w-4" :class="{ 'animate-spin': updating }" />{{ updating ? 'Applying…' : 'Apply updates' }}</button>
          </template>
          <p v-else class="mt-2 flex items-center gap-2 text-sm text-emerald-700"><CheckCircleIcon class="h-5 w-5" />Up to date.</p>
          <p v-if="updateResult" class="mt-3 text-sm text-gray-700">{{ updateResult }}</p>
          <p class="mt-4 text-xs text-gray-500">Take a backup first (<code class="font-mono">php backend/bin/backup.php</code>). Updates only add to the database; nothing is deleted.</p>
        </div>
        <div class="card p-5">
          <h2 class="font-semibold">Changes from the developer portal</h2>
          <ol class="mt-3 space-y-3 text-sm">
            <li v-for="(r, i) in data.portal_log" :key="i">
              <p><span class="font-semibold">{{ r.developer }}</span> <span class="text-gray-700">{{ r.text }}</span> <span class="text-xs text-gray-500">· {{ ago(r.at) }}</span></p>
              <p v-if="r.details" class="whitespace-pre-line text-xs text-gray-500">{{ r.details }}</p>
            </li>
            <li v-if="!data.portal_log.length" class="text-gray-500">None yet.</li>
          </ol>
        </div>
      </section>
    </template>
    <div v-else-if="!error" class="mt-6 h-40 animate-pulse rounded-2xl bg-surface" aria-hidden="true" />

    <!-- Add an office account -->
    <Modal v-if="adding" title="Add an office account" @close="adding = false">
      <form class="space-y-4" @submit.prevent="addAccount">
        <div><label for="o-name" class="label">Full name</label><input id="o-name" v-model="newAccount.full_name" class="input" /></div>
        <div><label for="o-email" class="label">Email (they sign in with it)</label><input id="o-email" v-model="newAccount.email" type="email" class="input" /></div>
        <fieldset>
          <legend class="label">Role</legend>
          <div class="grid gap-2 sm:grid-cols-2">
            <label v-for="r in [{ v: 'admin', t: 'Admin', d: 'Runs the SACCO day to day; is told of every super admin change.' }, { v: 'super_admin', t: 'Super admin', d: 'Sets the rules and can do everything.' }]" :key="r.v" class="flex cursor-pointer gap-2 rounded-xl border p-3 text-sm" :class="newAccount.role === r.v ? 'border-brand bg-navy-50/50' : 'border-line'">
              <input v-model="newAccount.role" type="radio" :value="r.v" class="mt-0.5 accent-brand" />
              <span><span class="font-semibold">{{ r.t }}</span><span class="block text-xs text-gray-600">{{ r.d }}</span></span>
            </label>
          </div>
        </fieldset>
        <p v-if="problem" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ problem }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="!newAccount.full_name || !newAccount.email">Add account</button>
      </form>
    </Modal>

    <!-- A temporary password, shown once -->
    <Modal v-if="secret" title="Temporary password" @close="secret = null">
      <p class="text-sm">Give {{ secret.who }} these details. The password is shown <strong>once</strong>; they choose their own when they sign in.</p>
      <dl class="mt-4 rounded-xl border border-line p-4 text-sm">
        <div class="flex justify-between gap-3"><dt class="text-gray-500">Sign in with</dt><dd class="font-semibold">{{ secret.login }}</dd></div>
        <div class="mt-2 flex justify-between gap-3"><dt class="text-gray-500">Temporary password</dt><dd class="font-mono text-base font-bold">{{ secret.password }}</dd></div>
      </dl>
      <button type="button" class="btn-primary mt-4 w-full" @click="secret = null">Done</button>
    </Modal>
  </div>
</template>
