<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { CheckCircleIcon, ComputerDesktopIcon, DevicePhoneMobileIcon, DeviceTabletIcon, ExclamationTriangleIcon, LockClosedIcon, ShieldCheckIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { useTheme } from '@/stores/theme'
import { formatDate } from '@/money'
import CountUp from '@/components/CountUp.vue'
import HeroParticles from '@/components/HeroParticles.vue'
import TwoStepCard from '@/components/TwoStepCard.vue'

interface Usage {
  days: number
  totals: { members: number; active_members: number; never_signed_in: number; sign_ins: number; failed: number; locked: number; actions: number }
  daily: { date: string; ok: number; failed: number }[]
  hours: number[]
  devices: Record<string, number>
  areas: { area: string; count: number }[]
  recent: { full_name: string; role: string; ip: string | null; user_agent: string | null; created_at: string }[]
  failures: { login: string; ip: string | null; reason: string | null; n: number; last_at: string }[]
  locked: { id: number; full_name: string; locked_until: string }[]
  office: { full_name: string; email: string | null; role: string; status: string; last_login_at: string | null; two_step: boolean }[]
  checks: { label: string; ok: boolean; note: string }[]
}

/** Who uses the system, when and on what, what went wrong at the door, and how well it is protected */
const auth = useAuth()
const theme = useTheme()
// Validated for colour-blind separation and contrast on each theme's surface
const colors = computed(() => (theme.dark ? { ok: '#6b78ec', failed: '#e0695a' } : { ok: '#3a46c4', failed: '#c0392b' }))
const days = ref<7 | 30 | 90>(30)
const data = ref<Usage | null>(null)
const error = ref('')
async function load() {
  try {
    data.value = await api.get<Usage>('/office/usage', { days: days.value })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load this.'
  }
}
onMounted(load)
watch(days, load)

const adoption = computed(() => (data.value?.totals.members ? Math.round((data.value.totals.active_members / data.value.totals.members) * 100) : 0))
const ring = 2 * Math.PI * 44
const score = computed(() => (data.value ? data.value.checks.filter((c) => c.ok).length : 0))

// Days chart
const H = 170
const hover = ref<number | null>(null)
const maxDay = computed(() => Math.max(1, ...(data.value?.daily.map((d) => d.ok + d.failed) ?? [1])))
const barH = (n: number) => (n / maxDay.value) * (H - 26)
const dayLabel = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
const maxHour = computed(() => Math.max(1, ...(data.value?.hours ?? [1])))
const hourLabel = (h: number) => `${String(h).padStart(2, '0')}:00`
const deviceIcons: Record<string, unknown> = { Phone: DevicePhoneMobileIcon, Tablet: DeviceTabletIcon, Computer: ComputerDesktopIcon }
const deviceTotal = computed(() => Object.values(data.value?.devices ?? {}).reduce((a, b) => a + b, 0) || 1)
const maxArea = computed(() => Math.max(1, ...(data.value?.areas.map((a) => a.count) ?? [1])))

function ago(at: string | null) {
  if (!at) return 'never'
  const mins = Math.round((Date.now() - new Date(at.replace(' ', 'T')).getTime()) / 60000)
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins} min ago`
  const h = Math.round(mins / 60)
  if (h < 24) return `${h} h ago`
  const d = Math.round(h / 24)
  return d === 1 ? 'yesterday' : d < 45 ? `${d} days ago` : formatDate(at.slice(0, 10))
}
const device = (ua: string | null) => (/iPad|Tablet/i.test(ua ?? '') ? 'Tablet' : /Mobile|Android|iPhone/i.test(ua ?? '') ? 'Phone' : 'Computer')
const why: Record<string, string> = { wrong_password: 'Wrong password', unknown_login: 'No such account', locked: 'Account locked', inactive: 'Account not active', wrong_two_step_code: 'Wrong two-step code' }
async function unlock(u: { id: number }) {
  await api.post(`/users/${u.id}/unlock`)
  await load()
}
</script>

<template>
  <div>
    <div class="hero-panel relative overflow-hidden rounded-3xl p-6 text-white shadow-lift sm:p-8">
      <HeroParticles />
      <div class="relative flex flex-wrap items-center justify-between gap-6">
        <div class="min-w-0">
          <p class="text-xs font-semibold uppercase tracking-wider text-saffron-300">Settings</p>
          <h1 class="mt-1 text-3xl font-bold">Usage &amp; security</h1>
          <p class="mt-1 max-w-xl text-navy-100/80">Who is using the system, when and on what, and how well it is protected.</p>
          <div class="mt-4 flex rounded-lg bg-white/10 p-0.5 text-sm font-semibold backdrop-blur sm:w-max" role="radiogroup" aria-label="Period">
            <button v-for="d in ([7, 30, 90] as const)" :key="d" type="button" role="radio" :aria-checked="days === d" class="flex-1 whitespace-nowrap rounded-md px-3 py-1.5" :class="days === d ? 'bg-white text-navy-900' : 'text-white/80 hover:text-white'" @click="days = d">{{ d }} days</button>
          </div>
        </div>
        <div v-if="data" class="flex items-center gap-4">
          <span class="relative inline-flex h-28 w-28 items-center justify-center">
            <svg viewBox="0 0 100 100" class="h-28 w-28 -rotate-90" aria-hidden="true">
              <circle cx="50" cy="50" r="44" fill="none" stroke-width="9" stroke="rgba(255,255,255,0.14)" />
              <circle cx="50" cy="50" r="44" fill="none" stroke-width="9" stroke-linecap="round" stroke="#f2b934" class="ring-fill" :style="{ '--ring': ring }" :stroke-dasharray="ring" :stroke-dashoffset="ring * (1 - adoption / 100)" />
            </svg>
            <span class="absolute text-2xl font-bold"><CountUp :value="adoption" :format="(n: number) => `${n}%`" /></span>
          </span>
          <p class="max-w-[9rem] text-sm text-navy-100/85">of members signed in during the last {{ days }} days</p>
        </div>
      </div>
    </div>
    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <template v-if="data">
      <section class="mt-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="card p-5"><p class="eyebrow">Sign-ins</p><p class="figure mt-2 text-3xl"><CountUp :value="data.totals.sign_ins" :format="(n: number) => n.toLocaleString()" /></p><p class="mt-1 text-xs text-gray-500">{{ data.totals.active_members }} of {{ data.totals.members }} members</p></div>
        <div class="card p-5"><p class="eyebrow">Actions recorded</p><p class="figure mt-2 text-3xl"><CountUp :value="data.totals.actions" :format="(n: number) => n.toLocaleString()" :delay="120" /></p><p class="mt-1 text-xs text-gray-500">In the audit log</p></div>
        <div class="card p-5"><p class="eyebrow">Never signed in</p><p class="figure mt-2 text-3xl"><CountUp :value="data.totals.never_signed_in" :format="(n: number) => n.toLocaleString()" :delay="240" /></p><p class="mt-1 text-xs text-gray-500">Members yet to start</p></div>
        <div class="card p-5" :class="{ '!border-red-200 !bg-red-50/60': data.totals.failed }"><p class="eyebrow">Failed sign-ins</p><p class="figure mt-2 text-3xl"><CountUp :value="data.totals.failed" :format="(n: number) => n.toLocaleString()" :delay="360" /></p><p class="mt-1 text-xs text-gray-500">{{ data.totals.locked }} account{{ data.totals.locked === 1 ? '' : 's' }} locked now</p></div>
      </section>

      <section class="mt-4 grid gap-4 xl:grid-cols-3">
        <div class="card min-w-0 p-5 xl:col-span-2">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold">Sign-ins each day</h2>
            <p class="flex items-center gap-3 text-xs text-gray-600">
              <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: colors.ok }" />Signed in</span>
              <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: colors.failed }" />Failed</span>
            </p>
          </div>
          <div class="relative mt-4 flex items-end gap-px sm:gap-0.5" :style="{ height: H + 'px' }" role="img" :aria-label="`Sign-ins over the last ${days} days`" @pointerleave="hover = null">
            <div v-for="(d, i) in data.daily" :key="d.date" class="flex h-full min-w-0 flex-1 flex-col justify-end" :class="{ 'rounded bg-navy-50/70': hover === i }" @pointerenter="hover = i">
              <div class="chart-bar mx-auto flex w-full max-w-[18px] flex-col justify-end gap-0.5" :style="{ '--i': Math.min(i, 30) }">
                <div v-if="d.failed" class="w-full rounded-t-[3px]" :style="{ height: barH(d.failed) + 'px', background: colors.failed }" />
                <div v-if="d.ok" class="w-full" :class="d.failed ? '' : 'rounded-t-[3px]'" :style="{ height: barH(d.ok) + 'px', background: colors.ok }" />
              </div>
            </div>
            <div v-if="hover !== null" class="pointer-events-none absolute -top-2 z-10 -translate-x-1/2 whitespace-nowrap rounded-lg bg-navy-950 px-3 py-2 text-xs text-white shadow-lift" :style="{ left: Math.min(Math.max(((hover + 0.5) / data.daily.length) * 100, 16), 84) + '%' }">
              <p class="text-gray-300">{{ dayLabel(data.daily[hover].date) }}</p>
              <p class="font-semibold">{{ data.daily[hover].ok }} signed in · {{ data.daily[hover].failed }} failed</p>
            </div>
          </div>
          <div class="mt-1 flex justify-between border-t border-line pt-1.5 text-[11px] text-gray-500"><span>{{ dayLabel(data.daily[0].date) }}</span><span>Today</span></div>

          <h3 class="mt-6 text-sm font-semibold">Busiest hours</h3>
          <div class="mt-2 grid gap-0.5" style="grid-template-columns: repeat(24, minmax(0, 1fr))">
            <div v-for="(n, h) in data.hours" :key="h" class="chart-area h-9 rounded-[3px]" :style="{ background: colors.ok, opacity: n ? 0.18 + (n / maxHour) * 0.82 : 0.06 }" :title="`${hourLabel(h)}: ${n} sign-in${n === 1 ? '' : 's'}`" />
          </div>
          <div class="mt-1 flex justify-between text-[11px] text-gray-500"><span>Midnight</span><span>Noon</span><span>11 pm</span></div>
        </div>

        <div class="space-y-4">
          <div class="card p-5">
            <h2 class="font-semibold">What people use</h2>
            <ul class="mt-3 space-y-3">
              <li v-for="(n, name) in data.devices" :key="name" class="flex items-center gap-3 text-sm">
                <component :is="deviceIcons[name]" class="h-5 w-5 text-gray-500" aria-hidden="true" />
                <span class="w-16">{{ name }}</span>
                <span class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100"><span class="fill-bar block h-full rounded-full" :style="{ width: (n / deviceTotal) * 100 + '%', background: colors.ok }" /></span>
                <span class="w-10 text-right font-semibold tabular-nums">{{ Math.round((n / deviceTotal) * 100) }}%</span>
              </li>
            </ul>
          </div>
          <div class="card p-5">
            <h2 class="font-semibold">Busiest areas</h2>
            <ul class="mt-3 space-y-2.5">
              <li v-for="a in data.areas.slice(0, 6)" :key="a.area" class="text-sm">
                <p class="flex justify-between"><span>{{ a.area }}</span><span class="font-semibold tabular-nums">{{ a.count }}</span></p>
                <span class="mt-1 block h-1.5 overflow-hidden rounded-full bg-gray-100"><span class="fill-bar block h-full rounded-full" :style="{ width: (a.count / maxArea) * 100 + '%', background: colors.ok }" /></span>
              </li>
              <li v-if="!data.areas.length" class="text-sm text-gray-500">Nothing yet.</li>
            </ul>
          </div>
        </div>
      </section>

      <TwoStepCard class="mt-4" />

      <section class="mt-4 grid gap-4 xl:grid-cols-2">
        <div class="card p-5">
          <div class="flex items-center justify-between"><h2 class="flex items-center gap-2 font-semibold"><ShieldCheckIcon class="h-5 w-5 text-brand" aria-hidden="true" />Security check</h2><span class="rounded-full bg-navy-50 px-2.5 py-0.5 text-xs font-semibold text-navy-800">{{ score }} of {{ data.checks.length }}</span></div>
          <ul class="mt-3 divide-y divide-line">
            <li v-for="c in data.checks" :key="c.label" class="flex gap-3 py-2.5 text-sm">
              <component :is="c.ok ? CheckCircleIcon : ExclamationTriangleIcon" class="h-5 w-5 shrink-0" :class="c.ok ? 'text-emerald-600' : 'text-amber-600'" aria-hidden="true" />
              <span><span class="font-medium">{{ c.label }}</span><span class="block text-xs text-gray-500">{{ c.note }}</span></span>
            </li>
          </ul>
        </div>
        <div class="space-y-4">
          <div v-if="data.locked.length" class="card p-5">
            <h2 class="flex items-center gap-2 font-semibold"><LockClosedIcon class="h-5 w-5 text-red-700" aria-hidden="true" />Locked accounts</h2>
            <ul class="mt-3 divide-y divide-line">
              <li v-for="u in data.locked" :key="u.id" class="flex items-center justify-between py-2 text-sm"><span>{{ u.full_name }}</span><button v-can="'members'" type="button" class="btn-secondary" @click="unlock(u)">Unlock</button></li>
            </ul>
          </div>
          <div class="card p-5">
            <h2 class="font-semibold">Office accounts</h2>
            <ul class="mt-3 divide-y divide-line">
              <li v-for="u in data.office" :key="u.full_name + u.email" class="flex items-center justify-between gap-3 py-2.5 text-sm">
                <span class="min-w-0"><span class="block truncate font-medium">{{ u.full_name }}</span><span class="block text-xs text-gray-500">{{ u.role }} · last signed in {{ ago(u.last_login_at) }}</span></span>
                <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold" :class="u.two_step ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600'">{{ u.two_step ? 'Two-step on' : 'Two-step off' }}</span>
              </li>
            </ul>
          </div>
        </div>
      </section>

      <section class="mt-4 grid gap-4 xl:grid-cols-2">
        <div class="card p-5">
          <h2 class="font-semibold">Latest sign-ins</h2>
          <ul class="mt-3 divide-y divide-line">
            <li v-for="(r, i) in data.recent" :key="i" class="flex items-center justify-between gap-3 py-2 text-sm">
              <span class="min-w-0 truncate"><span class="font-medium">{{ r.full_name }}</span> <span class="text-gray-500">· {{ device(r.user_agent) }}</span></span>
              <span class="shrink-0 text-xs text-gray-500">{{ ago(r.created_at) }}</span>
            </li>
            <li v-if="!data.recent.length" class="py-2 text-sm text-gray-500">None yet.</li>
          </ul>
        </div>
        <div class="card p-5">
          <h2 class="font-semibold">Failed sign-ins</h2>
          <ul class="mt-3 divide-y divide-line">
            <li v-for="(f, i) in data.failures" :key="i" class="flex items-center justify-between gap-3 py-2 text-sm">
              <span class="min-w-0"><span class="block truncate font-medium">{{ f.login }}</span><span class="block truncate text-xs text-gray-500">{{ why[f.reason ?? ''] ?? f.reason }}<template v-if="f.ip"> · {{ f.ip }}</template></span></span>
              <span class="shrink-0 text-right text-xs text-gray-500"><span class="block font-semibold text-ink">{{ f.n }}×</span>{{ ago(f.last_at) }}</span>
            </li>
            <li v-if="!data.failures.length" class="py-2 text-sm text-gray-500">None in this period.</li>
          </ul>
        </div>
      </section>
      <p v-if="auth.viewOnly" class="mt-4 text-xs text-gray-500">Your account can see this page; changes are made by the super admin.</p>
    </template>
    <div v-else-if="!error" class="mt-5 h-40 animate-pulse rounded-2xl bg-surface" aria-hidden="true" />
  </div>
</template>
