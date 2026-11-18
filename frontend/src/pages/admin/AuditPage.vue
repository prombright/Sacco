<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  ChevronDownIcon,
  DocumentArrowDownIcon,
  ExclamationTriangleIcon,
  MagnifyingGlassIcon,
  PrinterIcon,
  TableCellsIcon,
} from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { downloadExcel, downloadPdf } from '@/exporters'
import type { Report } from '@/reports'
import PageHeader from '@/components/PageHeader.vue'
import EmptyState from '@/components/EmptyState.vue'
import SkeletonRows from '@/components/SkeletonRows.vue'
import SaccoMark from '@/components/SaccoMark.vue'

/**
 * The audit report, organised for review: newest first, a day at a time; a summary of the period;
 * each entry under its area (Money, Loans...); "Needs a look" for what an auditor checks first;
 * everyday entries (switching accounts...) hidden unless asked for; a click opens the record.
 * Downloads and the printout hold what is shown, grouped by day, with lines to sign.
 */
interface Entry {
  id: number
  at: string
  actor_id: number | null
  who: string
  action: string
  area: string
  sensitive: boolean
  routine: boolean
  what: string
  on: string
  link: string | null
  details: string[]
  from: string
}

const auth = useAuth()
const router = useRouter()
const entries = ref<Entry[]>([])
const areaNames = ref<Record<string, string>>({})
const people = ref<{ id: number; name: string }[]>([])
const loading = ref(true)
const error = ref('')

// The period
const today = new Date().toLocaleDateString('en-CA')
const daysAgo = (n: number) => new Date(Date.now() - n * 864e5).toLocaleDateString('en-CA')
const PRESETS = [
  { key: '7', label: '7 days' },
  { key: '30', label: '30 days' },
  { key: '90', label: '3 months' },
  { key: 'year', label: 'This year' },
  { key: 'custom', label: 'Choose dates' },
] as const
const preset = ref<(typeof PRESETS)[number]['key']>('30')
const from = ref(daysAgo(30))
const to = ref(today)
function pickPreset(k: (typeof PRESETS)[number]['key']) {
  preset.value = k
  if (k === 'custom') return
  to.value = today
  from.value = k === 'year' ? `${today.slice(0, 4)}-01-01` : daysAgo(Number(k))
}

// What's shown
const area = ref('')
const needsLook = ref(false)
const showRoutine = ref(false)
const person = ref<number | ''>('')
const q = ref('')
const open = ref(new Set<number>())

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get<{ entries: Entry[]; areas: Record<string, string>; people: { id: number; name: string }[] }>('/audit', { from: from.value, to: to.value })
    entries.value = res.entries
    areaNames.value = res.areas
    people.value = res.people
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the audit report.'
  } finally {
    loading.value = false
  }
}
watch([from, to], () => {
  if (from.value && to.value) load()
})
onMounted(load)

/** Everything in the period, before the area and "needs a look" filters: the summary counts it */
const base = computed(() => {
  const words = q.value.toLowerCase().split(/\s+/).filter(Boolean)
  return entries.value.filter((e) => {
    if (e.routine && !showRoutine.value && area.value !== 'security') return false
    if (person.value !== '' && e.actor_id !== person.value) return false
    if (words.length) {
      const text = `${e.who} ${e.what} ${e.on} ${e.details.join(' ')}`.toLowerCase()
      if (!words.every((w) => text.includes(w))) return false
    }
    return true
  })
})
const shown = computed(() => base.value.filter((e) => (!area.value || e.area === area.value) && (!needsLook.value || e.sensitive)))

const summary = computed(() => {
  const byArea: Record<string, number> = {}
  for (const e of base.value) byArea[e.area] = (byArea[e.area] ?? 0) + 1
  const byPerson: Record<string, number> = {}
  for (const e of base.value) if (e.actor_id !== null) byPerson[e.who] = (byPerson[e.who] ?? 0) + 1
  const top = Object.entries(byPerson).sort((a, b) => b[1] - a[1]).slice(0, 3)
  return {
    total: base.value.length,
    sensitive: base.value.filter((e) => e.sensitive).length,
    people: Object.keys(byPerson).length,
    routineHidden: showRoutine.value ? 0 : entries.value.filter((e) => e.routine).length,
    byArea,
    top,
  }
})

/** A day at a time, newest first */
const days = computed(() => {
  const out: { day: string; label: string; items: Entry[] }[] = []
  for (const e of shown.value) {
    const day = e.at.slice(0, 10)
    if (out.at(-1)?.day !== day) out.push({ day, label: dayLabel(day), items: [] })
    out.at(-1)!.items.push(e)
  }
  return out
})
function dayLabel(day: string) {
  if (day === today) return 'Today'
  if (day === daysAgo(1)) return 'Yesterday'
  return new Date(day + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
}
const time = (at: string) => at.slice(11, 16)

/** Each area's colour: a dot and a soft label (never the status colours, which mean good and bad) */
const TONE: Record<string, { dot: string; chip: string }> = {
  money: { dot: 'bg-navy-600', chip: 'bg-navy-50 text-navy-800' },
  loans: { dot: 'bg-saffron-500', chip: 'bg-saffron-50 text-saffron-800' },
  members: { dot: 'bg-sky-500', chip: 'bg-sky-50 text-sky-800' },
  rules: { dot: 'bg-violet-500', chip: 'bg-violet-50 text-violet-800' },
  books: { dot: 'bg-slate-500', chip: 'bg-slate-100 text-slate-700' },
  imports: { dot: 'bg-gray-400', chip: 'bg-gray-100 text-gray-700' },
  security: { dot: 'bg-fuchsia-500', chip: 'bg-fuchsia-50 text-fuchsia-800' },
}
const tone = (a: string) => TONE[a] ?? TONE.imports

function toggle(id: number) {
  const s = new Set(open.value)
  if (s.has(id)) s.delete(id)
  else s.add(id)
  open.value = s
}
function go(e: Entry) {
  if (e.link) router.push(e.link)
}

// Downloads and the printout: what is shown, a day at a time, with lines to sign
const periodText = computed(() => {
  const f = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })
  const filters = [
    area.value && areaNames.value[area.value],
    needsLook.value && 'needs a look only',
    person.value !== '' && `by ${people.value.find((p) => p.id === person.value)?.name}`,
    q.value.trim() && `matching "${q.value.trim()}"`,
  ].filter(Boolean)
  return `${f(from.value)} to ${f(to.value)}${filters.length ? ' · ' + filters.join(' · ') : ''}`
})
const report = computed<Report>(() => ({
  title: 'Audit report',
  subtitle: `${periodText.value} · ${shown.value.length} entr${shown.value.length === 1 ? 'y' : 'ies'}`,
  columns: [
    { key: 'time', label: 'Time', type: 'text' },
    { key: 'who', label: 'Who', type: 'text' },
    { key: 'what', label: 'What', type: 'text' },
    { key: 'on', label: 'On', type: 'text' },
    { key: 'details', label: 'Details', type: 'text' },
    { key: 'flag', label: 'Needs a look', type: 'text' },
  ],
  rows: days.value.flatMap((d) => [
    { _kind: 'heading' as const, time: d.label },
    ...d.items.map((e) => ({ time: time(e.at), who: e.who, what: e.what, on: e.on, details: e.details.join(' · '), flag: e.sensitive ? 'Yes' : '' })),
  ]),
  totals: null,
  signoff: ['Reviewed by (name)', 'Signature', 'Date'],
}))
const making = ref<'' | 'excel' | 'pdf'>('')
async function download(kind: 'excel' | 'pdf') {
  making.value = kind
  try {
    const who = { sacco: auth.sacco, user: auth.user?.full_name ?? null }
    await (kind === 'excel' ? downloadExcel(report.value, who) : downloadPdf(report.value, who))
  } finally {
    making.value = ''
  }
}
const printing = ref(false)
async function print() {
  printing.value = true
  document.body.classList.add('printing-report')
  await nextTick()
  const done = () => {
    document.body.classList.remove('printing-report')
    printing.value = false
    window.removeEventListener('afterprint', done)
  }
  window.addEventListener('afterprint', done)
  window.print()
}
</script>

<template>
  <div>
    <PageHeader title="Audit report" class="print:hidden">
      Every change made in the system: who, what, on whom and when. Newest first; open "Needs a look" for what an auditor checks first.
    </PageHeader>

    <!-- The period, who, search, downloads -->
    <section class="card mt-5 space-y-4 p-4 sm:p-5 print:hidden">
      <div class="flex flex-wrap gap-1.5" role="radiogroup" aria-label="Period">
        <button
          v-for="p in PRESETS"
          :key="p.key"
          type="button"
          role="radio"
          :aria-checked="preset === p.key"
          class="tap rounded-full px-3.5 py-1.5 text-sm font-semibold transition"
          :class="preset === p.key ? 'bg-brand text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
          @click="pickPreset(p.key)"
        >{{ p.label }}</button>
      </div>
      <div class="flex flex-wrap items-end gap-3">
        <template v-if="preset === 'custom'">
          <div class="w-[calc(50%-0.375rem)] sm:w-40"><label for="a-from" class="label">From</label><input id="a-from" v-model="from" type="date" class="input" :max="to" /></div>
          <div class="w-[calc(50%-0.375rem)] sm:w-40"><label for="a-to" class="label">To</label><input id="a-to" v-model="to" type="date" class="input" :min="from" :max="today" /></div>
        </template>
        <div class="w-full sm:w-56">
          <label for="a-who" class="label">Who</label>
          <select id="a-who" v-model="person" class="input">
            <option value="">Everyone</option>
            <option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <div class="relative min-w-0 flex-1 basis-full sm:basis-56">
          <label for="a-q" class="label">Search</label>
          <MagnifyingGlassIcon class="pointer-events-none absolute bottom-3 left-3 h-4 w-4 text-gray-400" aria-hidden="true" />
          <input id="a-q" v-model="q" type="search" class="input pl-9" placeholder="A name, loan or word" autocomplete="off" />
        </div>
        <label class="flex h-10 cursor-pointer items-center gap-2 text-sm text-gray-600">
          <input v-model="showRoutine" type="checkbox" class="h-4 w-4 accent-brand" />Show routine entries
        </label>
        <div class="flex overflow-hidden rounded-lg border border-line bg-surface shadow-sm max-sm:w-full max-sm:[&>button]:flex-1 max-sm:[&>button]:justify-center sm:ml-auto" role="group" aria-label="Download or print">
          <button type="button" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50 disabled:opacity-40" :disabled="!shown.length || !!making" @click="download('excel')"><TableCellsIcon class="h-4 w-4" />Excel</button>
          <button type="button" class="inline-flex items-center gap-1.5 border-l border-line px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-40" :disabled="!shown.length || !!making" @click="download('pdf')"><DocumentArrowDownIcon class="h-4 w-4" />PDF</button>
          <button type="button" class="inline-flex items-center gap-1.5 border-l border-line px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-40" :disabled="!shown.length" @click="print"><PrinterIcon class="h-4 w-4" />Print</button>
        </div>
      </div>
    </section>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 print:hidden" role="alert">{{ error }}</div>

    <!-- The period at a glance -->
    <section v-if="!loading" class="mt-4 grid gap-3 sm:grid-cols-3 print:hidden" aria-label="Summary">
      <div class="card p-4">
        <p class="eyebrow">Changes</p>
        <p class="mt-1 text-2xl font-bold tabular-nums">{{ summary.total.toLocaleString() }}</p>
        <p class="mt-0.5 text-xs text-gray-500">by {{ summary.people }} {{ summary.people === 1 ? 'person' : 'people' }}<template v-if="summary.routineHidden"> · {{ summary.routineHidden }} routine hidden</template></p>
      </div>
      <button
        type="button"
        class="card p-4 text-left transition"
        :class="needsLook ? '!border-amber-300 !bg-amber-50' : summary.sensitive ? 'hover:border-amber-200' : ''"
        :aria-pressed="needsLook"
        @click="needsLook = !needsLook"
      >
        <p class="eyebrow flex items-center gap-1.5"><ExclamationTriangleIcon class="h-4 w-4 text-amber-600" aria-hidden="true" />Needs a look</p>
        <p class="mt-1 text-2xl font-bold tabular-nums" :class="summary.sensitive ? 'text-amber-800' : ''">{{ summary.sensitive }}</p>
        <p class="mt-0.5 text-xs text-gray-500">{{ needsLook ? 'Showing only these · tap to show all' : 'Reversals, rule and role changes… · tap to show only these' }}</p>
      </button>
      <div class="card p-4">
        <p class="eyebrow">Most changes by</p>
        <ul v-if="summary.top.length" class="mt-1.5 space-y-0.5 text-sm">
          <li v-for="[name, n] in summary.top" :key="name" class="flex justify-between gap-3"><span class="truncate">{{ name }}</span><span class="tabular-nums text-gray-500">{{ n }}</span></li>
        </ul>
        <p v-else class="mt-1.5 text-sm text-gray-500">Nobody yet</p>
      </div>
    </section>

    <!-- Areas: a filter each, with how many -->
    <div v-if="!loading" class="mt-4 flex flex-wrap gap-2 print:hidden" role="radiogroup" aria-label="Area">
      <button type="button" role="radio" :aria-checked="!area" class="tap shrink-0 rounded-full border px-3.5 py-1.5 text-sm font-semibold" :class="!area ? 'border-brand bg-brand text-white' : 'border-line bg-surface text-gray-600 hover:bg-gray-50'" @click="area = ''">
        All <span class="ml-1 text-xs opacity-75">{{ summary.total }}</span>
      </button>
      <button
        v-for="(label, key) in areaNames"
        :key="key"
        type="button"
        role="radio"
        :aria-checked="area === key"
        class="tap inline-flex shrink-0 items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm font-semibold"
        :class="area === key ? 'border-brand bg-navy-50 text-navy-800' : 'border-line bg-surface text-gray-600 hover:bg-gray-50'"
        @click="area = area === key ? '' : String(key)"
      >
        <span class="h-2 w-2 rounded-full" :class="tone(String(key)).dot" aria-hidden="true" />{{ label }}
        <span class="text-xs opacity-75">{{ summary.byArea[key] ?? 0 }}</span>
      </button>
    </div>

    <!-- The entries, a day at a time -->
    <div class="mt-4 print:hidden">
      <div v-if="loading" class="card"><SkeletonRows :rows="6" :amount="false" /></div>
      <div v-else-if="!days.length" class="card"><EmptyState title="Nothing here" :text="entries.length ? 'No entry matches what is chosen. Try All, or a longer period.' : 'No changes were made in this period.'" compact /></div>
      <section v-for="d in days" :key="d.day" class="mb-5">
        <h2 class="sticky top-16 z-10 -mx-1 flex items-baseline justify-between bg-paper/95 px-1 py-2 backdrop-blur">
          <span class="text-sm font-semibold">{{ d.label }}</span>
          <span class="text-xs text-gray-500">{{ d.items.length }} change{{ d.items.length === 1 ? '' : 's' }}</span>
        </h2>
        <ul class="card divide-y divide-line">
          <li v-for="e in d.items" :key="e.id" class="px-4 py-3" :class="{ 'bg-amber-50/50': e.sensitive }">
            <div class="flex items-start gap-3">
              <span class="mt-0.5 w-11 shrink-0 text-xs tabular-nums text-gray-500">{{ time(e.at) }}</span>
              <div class="min-w-0 flex-1">
                <p class="text-sm leading-snug">
                  <span class="font-semibold">{{ e.who }}</span> <span class="text-gray-700">{{ e.what.charAt(0).toLowerCase() + e.what.slice(1) }}</span>
                </p>
                <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                  <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold" :class="tone(e.area).chip"><span class="h-1.5 w-1.5 rounded-full" :class="tone(e.area).dot" aria-hidden="true" />{{ areaNames[e.area] }}</span>
                  <span v-if="e.sensitive" class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-800"><ExclamationTriangleIcon class="h-3 w-3" aria-hidden="true" />Needs a look</span>
                  <button v-if="e.on && e.link" type="button" class="font-semibold text-brand hover:underline" @click="go(e)">{{ e.on }} →</button>
                  <span v-else-if="e.on" class="text-gray-600">{{ e.on }}</span>
                </p>
                <!-- Details: the first line, the rest on a tap -->
                <template v-if="e.details.length">
                  <p class="mt-1.5 break-words text-xs text-gray-600">{{ e.details[0] }}</p>
                  <template v-if="e.details.length > 1">
                    <ul v-if="open.has(e.id)" class="mt-0.5 space-y-0.5 text-xs text-gray-600">
                      <li v-for="(l, i) in e.details.slice(1)" :key="i" class="break-words">{{ l }}</li>
                    </ul>
                    <button type="button" class="mt-1 inline-flex items-center gap-0.5 text-xs font-semibold text-gray-500 hover:text-ink" @click="toggle(e.id)">
                      {{ open.has(e.id) ? 'Less' : `${e.details.length - 1} more` }}<ChevronDownIcon class="h-3 w-3 transition" :class="{ 'rotate-180': open.has(e.id) }" />
                    </button>
                  </template>
                </template>
              </div>
              <span class="hidden shrink-0 text-right text-[11px] text-gray-400 sm:block">{{ e.from }}</span>
            </div>
          </li>
        </ul>
      </section>
    </div>

    <!-- Printed: the SACCO's heading, the days, lines to sign -->
    <article v-if="printing" class="print-area hidden text-[11px] text-black print:block">
      <header class="flex items-center gap-3 border-b-2 border-gray-800 pb-2">
        <SaccoMark :sacco="auth.sacco" />
        <div>
          <p class="text-base font-bold">{{ auth.sacco?.name }}</p>
          <p class="text-sm font-semibold">Audit report</p>
          <p class="text-gray-600">{{ periodText }} · {{ shown.length }} entries</p>
        </div>
      </header>
      <section v-for="d in days" :key="d.day" class="mt-3 break-inside-avoid-page">
        <p class="border-b border-gray-400 pb-0.5 font-bold uppercase">{{ d.label }}</p>
        <table class="w-full">
          <tr v-for="e in d.items" :key="e.id" class="border-b border-gray-200 align-top">
            <td class="w-10 py-1">{{ time(e.at) }}</td>
            <td class="py-1 pr-2"><strong>{{ e.who }}</strong> {{ e.what.charAt(0).toLowerCase() + e.what.slice(1) }}<template v-if="e.on"> · {{ e.on }}</template><template v-if="e.details.length"><br /><span class="text-gray-600">{{ e.details.join(' · ') }}</span></template></td>
            <td class="w-20 py-1 text-right">{{ e.sensitive ? 'Needs a look' : '' }}</td>
          </tr>
        </table>
      </section>
      <div class="mt-12 grid grid-cols-3 gap-8">
        <p class="border-t border-gray-700 pt-1">Reviewed by (name)</p>
        <p class="border-t border-gray-700 pt-1">Signature</p>
        <p class="border-t border-gray-700 pt-1">Date</p>
      </div>
    </article>
  </div>
</template>
