<script setup lang="ts">
import { reactive } from 'vue'
import { ArrowUpTrayIcon, CheckCircleIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { ask } from '@/confirm'
import { formatMoney } from '@/money'
import { readOldFile, type OldFile, type OldRow } from '@/oldSystemImport'

interface Checked {
  row: number
  label: string
  detail: string
  amount: string
  errors: string[]
}
interface Report {
  rows: Checked[]
  row_count: number
  error_count: number
  total: string
  problem: string
  warning?: string
}
interface Step {
  kind: OldFile
  title: string
  about: string
  rows: OldRow[]
  fileName: string
  report: Report | null
  done: string
  error: string
  busy: boolean
  progress: string
}

const step = (kind: OldFile, title: string, about: string): Step => ({ kind, title, about, rows: [], fileName: '', report: null, done: '', error: '', busy: false, progress: '' })
const steps = reactive<Step[]>([
  step('loans', 'Loans', 'What each borrower still owes, as one loan each, with a new repayment schedule from the balance date. No messages go to members.'),
  step('payable', 'Savings interest payable', 'What each member has earned and not been paid. It stays payable until the committee decides.'),
  step('journal', 'Opening journal', 'Bank, funds in transit, surplus and everything else in the books. Anything that doesn\'t balance goes to Suspense.'),
  step('history', 'Old transactions', 'Kept for reference on each member\'s statement. It doesn\'t change any balance.'),
])
const PART = 2000

async function pick(s: Step, e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  s.error = ''
  s.done = ''
  s.report = null
  s.busy = true
  try {
    s.fileName = file.name
    s.rows = await readOldFile(s.kind, file)
    if (!s.rows.length) throw new Error('The file has no rows.')
    if (s.kind === 'history') {
      s.report = { rows: [], row_count: s.rows.length, error_count: 0, total: '0', problem: '' }
    } else {
      s.report = await api.post<Report>(`/old-system/${s.kind}/preview`, { rows: s.rows })
    }
  } catch (err) {
    s.error = err instanceof Error ? err.message : 'Couldn\'t read that file.'
  } finally {
    s.busy = false
  }
}

async function save(s: Step) {
  if (!s.report || !await ask(`Bring in ${s.report.row_count.toLocaleString()} rows of "${s.title}"? This can only be done once.`)) return
  s.error = ''
  s.busy = true
  try {
    if (s.kind === 'history') {
      for (let i = 0; i < s.rows.length; i += PART) {
        s.progress = `${Math.min(i + PART, s.rows.length).toLocaleString()} of ${s.rows.length.toLocaleString()}`
        await api.post('/old-system/history', { rows: s.rows.slice(i, i + PART), first: i === 0 })
      }
      s.done = `${s.rows.length.toLocaleString()} old transactions kept.`
    } else {
      const res = await api.post<{ saved: number; total: string }>(`/old-system/${s.kind}`, { rows: s.rows })
      s.done = `${res.saved.toLocaleString()} rows brought in, totalling ${formatMoney(res.total)}.`
    }
    s.report = null
  } catch (err) {
    s.error = err instanceof ApiError ? err.message : 'Something went wrong. Nothing was saved.'
  } finally {
    s.busy = false
    s.progress = ''
  }
}
</script>

<template>
  <div class="max-w-5xl">
    <RouterLink :to="{ name: 'member-import' }" class="text-sm font-medium text-gray-600 hover:text-gray-900">← Import members</RouterLink>
    <h1 class="mt-3 text-2xl font-bold">Bring in the old system</h1>
    <p class="mt-1 text-gray-600">Do the members first, then these in order. Every file is checked before anything is saved.</p>

    <ol class="mt-6 space-y-4">
      <li v-for="(s, i) in steps" :key="s.kind" class="card p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0 flex-1">
            <p class="eyebrow">File {{ i + 2 }}</p>
            <p class="mt-1 font-semibold">{{ s.title }}</p>
            <p class="mt-1 text-sm text-gray-600">{{ s.about }}</p>
          </div>
          <label class="btn-secondary cursor-pointer" :class="{ 'pointer-events-none opacity-50': s.busy }">
            <ArrowUpTrayIcon class="h-4 w-4" />{{ s.busy && !s.report ? 'Reading…' : 'Choose file' }}
            <input type="file" accept=".xlsx,.xls" class="sr-only" @change="pick(s, $event)" />
          </label>
        </div>

        <p v-if="s.done" class="mt-3 flex items-center gap-2 text-sm text-emerald-700"><CheckCircleIcon class="h-5 w-5" aria-hidden="true" />{{ s.done }}</p>
        <div v-if="s.error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ s.error }}</div>

        <template v-if="s.report">
          <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1 text-sm">
            <span class="text-gray-600">{{ s.fileName }}</span>
            <span><strong class="tabular-nums">{{ s.report.row_count.toLocaleString() }}</strong> rows</span>
            <span v-if="s.kind !== 'history'"><strong class="tabular-nums">{{ formatMoney(s.report.total) }}</strong> total</span>
            <span v-if="s.report.error_count" class="font-medium text-red-700">{{ s.report.error_count }} with problems</span>
          </div>
          <div v-if="s.report.problem" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ s.report.problem }}</div>
          <div v-if="s.report.warning" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ s.report.warning }}</div>
          <div v-if="s.report.error_count" class="mt-3 max-h-64 overflow-auto rounded-xl border border-line text-sm">
            <p v-for="r in s.report.rows.filter((x) => x.errors.length)" :key="r.row" class="border-b border-line px-3 py-2 last:border-0">
              <span class="font-medium">Row {{ r.row }}, {{ r.label }}:</span> <span class="text-red-700">{{ r.errors.join(' ') }}</span>
            </p>
          </div>
          <div class="mt-4 flex justify-end">
            <button type="button" class="btn-primary" :disabled="s.busy || s.report.error_count > 0 || !!s.report.problem" @click="save(s)">
              {{ s.busy ? (s.progress ? `Saving ${s.progress}…` : 'Saving…') : `Bring in ${s.report.row_count.toLocaleString()} rows` }}
            </button>
          </div>
        </template>
      </li>
    </ol>
  </div>
</template>
