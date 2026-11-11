<script setup lang="ts">
import { ask } from '@/confirm'
import { computed, ref } from 'vue'
import { ArrowDownTrayIcon, ArrowUpTrayIcon, CheckCircleIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { formatDate, formatMoney } from '@/money'
import { downloadLoginSlips, downloadMemberTemplate, readMemberFile, type ImportRow } from '@/memberImport'

interface Checked {
  row: number
  full_name: string | null
  email: string | null
  employee_no: string | null
  account_no: string | null
  joined_on: string | null
  savings: string
  shares: string
  errors: string[]
}
interface Report {
  rows: Checked[]
  row_count: number
  error_count: number
  totals: { savings: string; shares: string }
}
interface Created {
  full_name: string
  account_no: string
  login: string
  temporary_password: string
}

const auth = useAuth()
const fileName = ref('')
const rows = ref<ImportRow[]>([])
const report = ref<Report | null>(null)
const created = ref<Created[] | null>(null)
const show = ref<'problems' | 'all'>('problems')
const error = ref('')
const busy = ref(false)

const visible = computed(() => (report.value?.rows ?? []).filter((r) => show.value === 'all' || r.errors.length))

async function pick(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  error.value = ''
  report.value = null
  busy.value = true
  try {
    fileName.value = file.name
    rows.value = await readMemberFile(file)
    report.value = await api.post<Report>('/members/import/preview', { rows: rows.value })
    show.value = report.value.error_count ? 'problems' : 'all'
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Couldn\'t read that file.'
  } finally {
    busy.value = false
  }
}

async function importAll() {
  if (!report.value || !await ask(`Add ${report.value.row_count} members with their opening balances?`)) return
  error.value = ''
  busy.value = true
  try {
    const res = await api.post<{ created: Created[] }>('/members/import', { rows: rows.value })
    created.value = res.created
    report.value = null
    downloadLoginSlips(res.created, auth.sacco?.name ?? 'SACCO')
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Something went wrong. Nobody was added.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="max-w-5xl">
    <RouterLink :to="{ name: 'users' }" class="text-sm font-medium text-gray-600 hover:text-gray-900">← Members</RouterLink>
    <h1 class="mt-3 text-2xl font-bold">Import members</h1>
    <p class="mt-1 text-gray-600">Bring in everyone at once from Excel, with the savings and shares they already have in the old records.</p>
    <p v-if="auth.isSuperAdmin" class="mt-1 text-sm"><RouterLink :to="{ name: 'old-system-import' }" class="font-medium text-navy-700 hover:underline">After the members: loans, opening journal and old transactions →</RouterLink></p>

    <!-- Done -->
    <section v-if="created" class="card mt-6 p-6">
      <div class="flex items-start gap-3">
        <CheckCircleIcon class="h-7 w-7 shrink-0 text-emerald-600" aria-hidden="true" />
        <div>
          <h2 class="text-lg font-semibold">{{ created.length }} members added</h2>
          <p class="mt-1 text-sm text-gray-700">
            Their sign-in details have been downloaded as an Excel file. Give each member their own line privately;
            they choose their own password the first time they sign in. These temporary passwords can't be shown again.
          </p>
          <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" class="btn-secondary" @click="downloadLoginSlips(created, auth.sacco?.name ?? 'SACCO')"><ArrowDownTrayIcon class="h-4 w-4" />Download again</button>
            <RouterLink :to="{ name: 'users' }" class="btn-primary">See members</RouterLink>
          </div>
        </div>
      </div>
    </section>

    <template v-else>
      <ol class="mt-6 grid gap-4 md:grid-cols-2">
        <li class="card p-5">
          <p class="eyebrow">Step 1</p>
          <p class="mt-1 font-semibold">Fill in the template</p>
          <p class="mt-1 text-sm text-gray-600">
            One row per member: full name, email and/or employee number (they sign in with either), account number (or leave blank to give one),
            phone, the date they joined, and their savings and shares from the old records.
          </p>
          <button type="button" class="btn-secondary mt-4" @click="downloadMemberTemplate"><ArrowDownTrayIcon class="h-4 w-4" />Download template</button>
        </li>
        <li class="card p-5">
          <p class="eyebrow">Step 2</p>
          <p class="mt-1 font-semibold">Upload it and check</p>
          <p class="mt-1 text-sm text-gray-600">Every row is checked first. Nobody is added until you confirm, and then everyone is added together.</p>
          <label class="btn-primary mt-4 cursor-pointer" :class="{ 'pointer-events-none opacity-50': busy }">
            <ArrowUpTrayIcon class="h-4 w-4" />{{ busy ? 'Reading…' : 'Upload file' }}
            <input type="file" accept=".xlsx,.xls,.csv" class="sr-only" @change="pick" />
          </label>
        </li>
      </ol>

      <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

      <section v-if="report" class="card mt-6 p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <p class="font-semibold">{{ fileName }} · {{ report.row_count }} members</p>
          <div class="flex gap-1 text-sm">
            <button type="button" class="rounded-md px-3 py-1" :class="show === 'problems' ? 'bg-ink text-white' : 'text-gray-600 hover:bg-gray-100'" @click="show = 'problems'">Problems only</button>
            <button type="button" class="rounded-md px-3 py-1" :class="show === 'all' ? 'bg-ink text-white' : 'text-gray-600 hover:bg-gray-100'" @click="show = 'all'">All rows</button>
          </div>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-3">
          <div class="rounded-xl bg-gray-50 p-3"><p class="text-xs text-gray-600">Opening savings</p><p class="font-semibold tabular-nums">{{ formatMoney(report.totals.savings) }}</p></div>
          <div class="rounded-xl bg-gray-50 p-3"><p class="text-xs text-gray-600">Opening shares</p><p class="font-semibold tabular-nums">{{ formatMoney(report.totals.shares) }}</p></div>
          <div class="rounded-xl p-3" :class="report.error_count ? 'bg-red-50' : 'bg-emerald-50'">
            <p class="text-xs" :class="report.error_count ? 'text-red-700' : 'text-emerald-800'">{{ report.error_count ? 'Must fix' : 'Ready' }}</p>
            <p class="font-semibold" :class="report.error_count ? 'text-red-700' : 'text-emerald-800'">
              {{ report.error_count ? `${report.error_count} row${report.error_count === 1 ? '' : 's'} with problems` : `${report.row_count} members to add` }}
            </p>
          </div>
        </div>

        <div class="mt-4 max-h-[28rem] overflow-auto rounded-xl border border-line">
          <table class="w-full text-sm">
            <thead class="sticky top-0 bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
              <tr><th class="px-3 py-2">Row</th><th class="px-3 py-2">Name</th><th class="px-3 py-2">Signs in with</th><th class="px-3 py-2">Member since</th><th class="px-3 py-2 text-right">Savings</th><th class="px-3 py-2 text-right">Shares</th></tr>
            </thead>
            <tbody class="divide-y divide-line">
              <template v-for="r in visible" :key="r.row">
                <tr :class="{ 'bg-red-50/60': r.errors.length }">
                  <td class="px-3 py-2 text-gray-500">{{ r.row }}</td>
                  <td class="px-3 py-2 font-medium">{{ r.full_name }}</td>
                  <td class="px-3 py-2 text-gray-600">{{ r.email ?? r.employee_no }}</td>
                  <td class="px-3 py-2 text-gray-600">{{ r.joined_on ? formatDate(r.joined_on) : '' }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ Number(r.savings) ? formatMoney(r.savings) : '' }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ Number(r.shares) ? formatMoney(r.shares) : '' }}</td>
                </tr>
                <tr v-if="r.errors.length" class="bg-red-50/60">
                  <td></td>
                  <td colspan="5" class="px-3 pb-2 text-xs text-red-700"><p v-for="m in r.errors" :key="m">✕ {{ m }}</p></td>
                </tr>
              </template>
              <tr v-if="!visible.length"><td colspan="6" class="px-3 py-6 text-center text-gray-500">No problems found.</td></tr>
            </tbody>
          </table>
        </div>

        <div class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-end">
          <p v-if="report.error_count" class="text-sm text-red-700 sm:mr-auto">Fix the rows marked ✕ in the file and upload it again. Nobody has been added.</p>
          <button type="button" class="btn-primary" :disabled="busy || report.error_count > 0" @click="importAll">
            {{ busy ? 'Adding…' : `Add ${report.row_count} members` }}
          </button>
        </div>
      </section>
    </template>
  </div>
</template>
