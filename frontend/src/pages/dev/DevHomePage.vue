<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  BuildingLibraryIcon,
  ChevronRightIcon,
  ExclamationTriangleIcon,
  MagnifyingGlassIcon,
  PlusIcon,
  UsersIcon,
} from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { formatMoney } from '@/money'
import { useTheme } from '@/stores/theme'
import MonthChart from '@/components/MonthChart.vue'
import Modal from '@/components/Modal.vue'
import CountUp from '@/components/CountUp.vue'
import { ago, type DevSacco, type PortalLogRow, type SaccoStats } from './dev'

interface Row extends DevSacco {
  stats: SaccoStats | null
  pending_updates: number | null
  error: string | null
}
interface Overview {
  saccos: Row[]
  totals: { saccos: number; active_saccos: number; members: number; savings: string; shares: string; loans_outstanding: string; arrears: string; waiting: number }
  funds: { month: string; value: string }[]
  pending_platform_updates: number
  recent: PortalLogRow[]
}

/** Every SACCO on this installation at a glance, with what needs looking after */
const theme = useTheme()
const blue = computed(() => (theme.dark ? '#6b78ec' : '#3a46c4'))
const data = ref<Overview | null>(null)
const error = ref('')
async function load() {
  try {
    data.value = await api.get<Overview>('/dev/overview')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the SACCOs.'
  }
}
onMounted(load)

const q = ref('')
const status = ref<'all' | 'active' | 'paused' | 'attention'>('all')
const needsAttention = (s: Row) => !!s.error || !!s.pending_updates || Number(s.stats?.arrears ?? 0) > 0
const saccos = computed(() =>
  (data.value?.saccos ?? []).filter((s) => {
    const text = `${s.name} ${s.short_name ?? ''} ${s.code} ${s.domain ?? ''}`.toLowerCase()
    if (q.value && !text.includes(q.value.toLowerCase())) return false
    if (status.value === 'attention') return needsAttention(s)
    return status.value === 'all' || s.status === status.value
  }),
)
const funds = (s: { savings: string; shares: string }) => Number(s.savings) + Number(s.shares)
const money = (v: number) => formatMoney(String(v))
const compact = (v: number) => (v >= 1e9 ? `${+(v / 1e9).toFixed(1)}B` : v >= 1e6 ? `${+(v / 1e6).toFixed(1)}M` : v >= 1e3 ? `${Math.round(v / 1e3)}K` : String(v))

// --- A new SACCO -----------------------------------------------------------------------------
const adding = ref(false)
const form = ref({ name: '', short_name: '', code: '', domain: '', db_name: '', currency: 'UGX', admin_name: '', admin_email: '' })
const made = ref<{ code: string; db_name: string; admin_email: string; temporary_password: string } | null>(null)
const busy = ref(false)
const problem = ref('')
function suggestCode() {
  if (!form.value.code) form.value.code = (form.value.short_name || form.value.name).toLowerCase().replace(/sacco/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 30)
}
async function create() {
  busy.value = true
  problem.value = ''
  try {
    made.value = await api.post('/dev/saccos', form.value)
    await load()
  } catch (e) {
    problem.value = e instanceof ApiError ? e.message : 'Couldn\'t set it up.'
  } finally {
    busy.value = false
  }
}
function closeAdding() {
  adding.value = false
  made.value = null
  form.value = { name: '', short_name: '', code: '', domain: '', db_name: '', currency: 'UGX', admin_name: '', admin_email: '' }
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <p class="eyebrow">Developer portal</p>
        <h1 class="mt-1 text-2xl font-bold sm:text-3xl">All SACCOs</h1>
        <p v-if="data" class="mt-1 text-gray-600">
          {{ data.totals.saccos }} SACCO{{ data.totals.saccos === 1 ? '' : 's' }} ({{ data.totals.active_saccos }} active) · {{ data.totals.members.toLocaleString() }} members
        </p>
      </div>
      <button type="button" class="btn-primary" @click="adding = true"><PlusIcon class="h-4 w-4" />New SACCO</button>
    </div>

    <div v-if="error" class="mt-6 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <template v-if="data">
      <p v-if="data.pending_platform_updates" class="mt-6 flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <ExclamationTriangleIcon class="h-5 w-5" aria-hidden="true" />
        The platform database has {{ data.pending_platform_updates }} update{{ data.pending_platform_updates === 1 ? '' : 's' }} to apply. Run <code class="font-mono">php backend/bin/migrate.php</code> on the server.
      </p>

      <!-- Across every SACCO -->
      <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Across every SACCO">
        <div class="relative overflow-hidden rounded-2xl bg-navy-900 p-5 text-white shadow-lift">
          <div class="absolute -right-14 -top-16 h-32 w-32 rounded-full border-2 border-saffron-400/90" aria-hidden="true" />
          <p class="text-xs font-semibold uppercase tracking-wider text-navy-200">Member funds, all SACCOs</p>
          <p class="mt-2 text-2xl font-bold"><CountUp :value="funds(data.totals)" :duration="1500" /></p>
          <p class="mt-1 text-xs text-navy-200">Savings {{ formatMoney(data.totals.savings) }} · Shares {{ formatMoney(data.totals.shares) }}</p>
        </div>
        <div class="card p-5">
          <p class="eyebrow">Loans outstanding</p>
          <p class="figure mt-2 text-2xl"><CountUp :value="data.totals.loans_outstanding" :delay="150" /></p>
          <p class="mt-1 text-xs" :class="Number(data.totals.arrears) > 0 ? 'font-semibold text-red-700' : 'text-gray-500'">{{ Number(data.totals.arrears) > 0 ? formatMoney(data.totals.arrears) + ' in arrears' : 'Nothing in arrears' }}</p>
        </div>
        <div class="card p-5">
          <p class="eyebrow">Members</p>
          <p class="figure mt-2 text-2xl"><CountUp :value="data.totals.members" :format="(n: number) => n.toLocaleString()" :delay="300" /></p>
          <p class="mt-1 text-xs text-gray-500">Active member accounts</p>
        </div>
        <div class="card p-5">
          <p class="eyebrow">Waiting for SACCO offices</p>
          <p class="figure mt-2 text-2xl"><CountUp :value="data.totals.waiting" :format="(n: number) => String(n)" :delay="450" /></p>
          <p class="mt-1 text-xs text-gray-500">Loans and withdrawals to approve or pay</p>
        </div>
      </section>

      <section class="mt-4 grid gap-4 xl:grid-cols-3">
        <div class="card min-w-0 p-5 xl:col-span-2">
          <div class="flex items-baseline justify-between gap-2">
            <h2 class="font-semibold">Member funds, all SACCOs</h2>
            <p class="text-xs text-gray-500">Savings + shares at each month end</p>
          </div>
          <MonthChart class="mt-4" :points="data.funds" kind="area" :color="blue" label="Member funds" :format="money" />
        </div>
        <div class="card p-5">
          <div class="flex items-center justify-between">
            <h2 class="font-semibold">Portal activity</h2>
            <RouterLink :to="{ name: 'dev-log' }" class="text-sm font-semibold text-brand hover:underline">All</RouterLink>
          </div>
          <ol class="mt-4 space-y-3 text-sm">
            <li v-for="(r, i) in data.recent" :key="i" class="flex gap-3">
              <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-saffron-500" aria-hidden="true" />
              <span class="flex-1">
                <span class="font-semibold">{{ r.developer }}</span> <span class="text-gray-700">{{ r.text }}</span><span v-if="r.sacco" class="text-gray-500">&#32;· {{ r.sacco }}</span>
                <span class="block text-xs text-gray-500">{{ ago(r.at) }}</span>
              </span>
            </li>
            <li v-if="!data.recent.length" class="text-gray-500">Nothing yet.</li>
          </ol>
        </div>
      </section>

      <!-- The SACCOs -->
      <section class="mt-8" aria-labelledby="list-title">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 id="list-title" class="text-lg font-semibold">SACCOs</h2>
          <div class="flex flex-wrap items-center gap-2">
            <div class="relative">
              <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" aria-hidden="true" />
              <input v-model="q" type="search" class="input !w-56 pl-9" placeholder="Find a SACCO" aria-label="Find a SACCO" />
            </div>
            <div class="flex rounded-lg bg-gray-200/60 p-0.5 text-xs font-semibold" role="radiogroup" aria-label="Show">
              <button v-for="f in (['all', 'active', 'paused', 'attention'] as const)" :key="f" type="button" role="radio" :aria-checked="status === f" class="rounded-md px-2.5 py-1.5" :class="status === f ? 'bg-surface text-ink shadow-sm' : 'text-gray-600'" @click="status = f">
                {{ f === 'attention' ? 'Needs attention' : f[0].toUpperCase() + f.slice(1) }}
              </button>
            </div>
          </div>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <RouterLink
            v-for="s in saccos"
            :key="s.id"
            :to="{ name: 'dev-sacco', params: { id: s.id } }"
            class="card group relative overflow-hidden p-5 transition hover:-translate-y-0.5 hover:shadow-lift"
          >
            <span class="absolute inset-x-0 top-0 h-1" :style="{ background: s.brand_color }" aria-hidden="true" />
            <div class="flex items-start gap-3">
              <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-sm font-semibold text-white" :style="{ background: s.brand_color }" aria-hidden="true">
                {{ (s.short_name || s.name).replace(/sacco/i, '').trim().slice(0, 2).toUpperCase() }}
              </span>
              <div class="min-w-0 flex-1">
                <p class="truncate font-semibold group-hover:text-brand">{{ s.name }}</p>
                <p class="truncate text-xs text-gray-500"><span class="font-mono">{{ s.code }}</span><template v-if="s.domain"> · {{ s.domain }}</template></p>
              </div>
              <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="s.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600'">{{ s.status === 'active' ? 'Active' : 'Paused' }}</span>
            </div>

            <p v-if="s.error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">Its database can't be read: {{ s.error }}</p>
            <template v-else-if="s.stats">
              <dl class="mt-4 grid grid-cols-3 gap-2 text-sm">
                <div><dt class="text-xs text-gray-500">Members</dt><dd class="font-semibold tabular-nums">{{ s.stats.members }}</dd></div>
                <div><dt class="text-xs text-gray-500">Funds</dt><dd class="font-semibold tabular-nums">{{ compact(funds(s.stats)) }}</dd></div>
                <div><dt class="text-xs text-gray-500">Loans out</dt><dd class="font-semibold tabular-nums">{{ compact(Number(s.stats.loans_outstanding)) }}</dd></div>
              </dl>
              <div class="mt-4 flex flex-wrap gap-1.5 text-xs">
                <span v-if="s.stats.waiting_total" class="rounded-full bg-saffron-100 px-2 py-0.5 font-semibold text-saffron-700">{{ s.stats.waiting_total }} waiting for the office</span>
                <span v-if="Number(s.stats.arrears) > 0" class="rounded-full bg-red-50 px-2 py-0.5 font-semibold text-red-700">{{ s.stats.loans_in_arrears }} loan{{ s.stats.loans_in_arrears === 1 ? '' : 's' }} in arrears</span>
                <span v-if="s.pending_updates" class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800">{{ s.pending_updates }} system update{{ s.pending_updates === 1 ? '' : 's' }} to apply</span>
                <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-gray-600"><UsersIcon class="h-3.5 w-3.5" aria-hidden="true" />{{ s.stats.signed_in_30_days }} signed in this month</span>
              </div>
              <p class="mt-3 flex items-center justify-between text-xs text-gray-500">
                Last activity {{ ago(s.stats.last_activity) }}
                <ChevronRightIcon class="h-4 w-4 text-gray-400 transition group-hover:translate-x-0.5 group-hover:text-gray-700" aria-hidden="true" />
              </p>
            </template>
          </RouterLink>
          <div v-if="!saccos.length" class="card col-span-full flex flex-col items-center p-10 text-center text-sm text-gray-500">
            <BuildingLibraryIcon class="h-8 w-8 text-gray-400" aria-hidden="true" />
            <p class="mt-2">No SACCOs match.</p>
          </div>
        </div>
      </section>
    </template>

    <div v-else-if="!error" class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-hidden="true">
      <div v-for="i in 4" :key="i" class="h-28 animate-pulse rounded-2xl bg-surface" />
    </div>

    <!-- New SACCO -->
    <Modal v-if="adding" title="Set up a new SACCO" @close="closeAdding">
      <div v-if="made" class="space-y-3 text-sm">
        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-800">The SACCO is ready, with its own database <span class="font-mono">{{ made.db_name }}</span>.</p>
        <p>Give its first super admin these details. The password is shown <strong>once</strong>, and they must change it when they first sign in.</p>
        <dl class="rounded-xl border border-line p-4">
          <div class="flex justify-between gap-3"><dt class="text-gray-500">SACCO code</dt><dd class="font-mono font-semibold">{{ made.code }}</dd></div>
          <div class="mt-2 flex justify-between gap-3"><dt class="text-gray-500">Sign in with</dt><dd class="font-semibold">{{ made.admin_email }}</dd></div>
          <div class="mt-2 flex justify-between gap-3"><dt class="text-gray-500">Temporary password</dt><dd class="font-mono text-base font-bold">{{ made.temporary_password }}</dd></div>
        </dl>
        <button type="button" class="btn-primary w-full" @click="closeAdding">Done</button>
      </div>
      <form v-else class="space-y-4" @submit.prevent="create">
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="sm:col-span-2"><label for="n-name" class="label">SACCO name</label><input id="n-name" v-model="form.name" class="input" placeholder="e.g. Kampala Teachers SACCO" @blur="suggestCode" /></div>
          <div><label for="n-short" class="label">Short name <span class="font-normal text-gray-500">(optional)</span></label><input id="n-short" v-model="form.short_name" class="input" placeholder="KTS" /></div>
          <div><label for="n-code" class="label">Code</label><input id="n-code" v-model="form.code" class="input font-mono" placeholder="kts" /></div>
          <div><label for="n-domain" class="label">Web address <span class="font-normal text-gray-500">(optional)</span></label><input id="n-domain" v-model="form.domain" class="input" placeholder="sacco.example.org" /></div>
          <div><label for="n-cur" class="label">Currency</label><input id="n-cur" v-model="form.currency" class="input uppercase" maxlength="3" /></div>
          <div class="sm:col-span-2">
            <label for="n-db" class="label">Database name <span class="font-normal text-gray-500">(optional)</span></label>
            <input id="n-db" v-model="form.db_name" class="input font-mono" :placeholder="'sacco_' + (form.code || 'code').replace(/-/g, '_')" />
            <p class="mt-1 text-xs text-gray-500">On cPanel, create the database in cPanel first and enter its full name (with the account prefix).</p>
          </div>
        </div>
        <fieldset class="rounded-xl border border-line p-4">
          <legend class="px-1 text-sm font-semibold">First super admin</legend>
          <div class="grid gap-4 sm:grid-cols-2">
            <div><label for="n-an" class="label">Name</label><input id="n-an" v-model="form.admin_name" class="input" /></div>
            <div><label for="n-ae" class="label">Email</label><input id="n-ae" v-model="form.admin_email" type="email" class="input" /></div>
          </div>
        </fieldset>
        <p v-if="problem" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ problem }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="busy || !form.name || !form.code || !form.admin_name || !form.admin_email">{{ busy ? 'Setting up…' : 'Set up the SACCO' }}</button>
      </form>
    </Modal>
  </div>
</template>
