<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/api'
import { formatDate, formatMoney } from '@/money'
import StatusBadge from '@/components/StatusBadge.vue'

interface PreviewRow {
  user_id: number
  full_name: string
  account_no: string
  status: string
  shares: string
  dividend: string
}
interface Preview {
  rate_percent: string
  record_date: string
  rows: PreviewRow[]
  member_count: number
  total_shares: string
  total_dividend: string
}
interface DividendRun {
  id: number
  label: string
  rate_percent: string
  record_date: string
  value_date: string
  member_count: number
  total_shares: string
  total_dividend: string
  status: 'posted' | 'reversed'
  created_by_name: string | null
  created_at: string
}

const router = useRouter()
const today = new Date().toLocaleDateString('en-CA')
const form = reactive({ label: String(new Date().getFullYear()), rate_percent: '', record_date: today, value_date: today })
const preview = ref<Preview | null>(null)
const runs = ref<DividendRun[]>([])
const error = ref('')
const busy = ref(false)

async function loadRuns() {
  runs.value = (await api.get<{ runs: DividendRun[] }>('/dividends')).runs
}
onMounted(() => loadRuns().catch(() => {}))

async function calculate() {
  error.value = ''
  busy.value = true
  try {
    preview.value = await api.post<Preview>('/dividends/preview', { rate_percent: form.rate_percent, record_date: form.record_date })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t work it out.'
    preview.value = null
  } finally {
    busy.value = false
  }
}

async function post() {
  if (!preview.value) return
  if (!confirm(`Credit ${formatMoney(preview.value.total_dividend)} in dividends to ${preview.value.member_count} members?`)) return
  error.value = ''
  busy.value = true
  try {
    const res = await api.post<{ run: DividendRun }>('/dividends', form)
    await router.push({ name: 'dividend-run', params: { id: res.run.id } })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Something went wrong. Nothing was credited.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <h1 class="text-xl font-semibold">Dividends</h1>
    <p class="mt-1 text-sm text-gray-600">Pay a percentage on every member's share capital, credited to their shares.</p>

    <section class="card mt-5 p-5">
      <h2 class="font-semibold">New dividend</h2>
      <form class="mt-4 space-y-4" novalidate @submit.prevent="calculate">
        <div class="grid gap-4 sm:grid-cols-4">
          <div>
            <label for="label" class="label">Name</label>
            <input id="label" v-model="form.label" class="input" maxlength="80" placeholder="e.g. 2026" @input="preview = null" />
          </div>
          <div>
            <label for="rate" class="label">Rate (%)</label>
            <input id="rate" v-model="form.rate_percent" class="input tabular-nums" inputmode="decimal" placeholder="e.g. 8.5" @input="preview = null" />
          </div>
          <div>
            <label for="record" class="label">Shares held on</label>
            <input id="record" v-model="form.record_date" type="date" :max="today" class="input" @input="preview = null" />
          </div>
          <div>
            <label for="credit" class="label">Credit on</label>
            <input id="credit" v-model="form.value_date" type="date" :max="today" class="input" />
          </div>
        </div>
        <p class="text-xs text-gray-500">Each member earns the rate on their share capital at the end of the "shares held on" day, rounded down to whole shillings.</p>
        <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
        <button type="submit" class="btn-secondary" :disabled="busy || !form.rate_percent">Work it out</button>
      </form>

      <div v-if="preview" class="mt-6 border-t border-gray-100 pt-5">
        <div class="grid gap-3 sm:grid-cols-3">
          <div class="rounded-lg bg-gray-50 p-3"><p class="text-xs text-gray-600">Members</p><p class="font-semibold">{{ preview.member_count }}</p></div>
          <div class="rounded-lg bg-gray-50 p-3"><p class="text-xs text-gray-600">Share capital on {{ formatDate(preview.record_date) }}</p><p class="font-semibold tabular-nums">{{ formatMoney(preview.total_shares) }}</p></div>
          <div class="rounded-lg bg-green-50 p-3"><p class="text-xs text-green-800">Dividend at {{ preview.rate_percent }}%</p><p class="font-semibold tabular-nums text-green-800">{{ formatMoney(preview.total_dividend) }}</p></div>
        </div>
        <div class="mt-4 max-h-96 overflow-auto rounded-lg border border-gray-200">
          <table class="w-full text-sm tabular-nums">
            <thead class="sticky top-0 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
              <tr><th class="px-3 py-2">Member</th><th class="px-3 py-2">Account</th><th class="px-3 py-2 text-right">Shares</th><th class="px-3 py-2 text-right">Dividend</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="r in preview.rows" :key="r.user_id">
                <td class="px-3 py-2">{{ r.full_name }}<span v-if="r.status !== 'active'" class="ml-1 text-xs text-amber-700">({{ r.status }})</span></td>
                <td class="px-3 py-2 font-mono">{{ r.account_no }}</td>
                <td class="px-3 py-2 text-right">{{ formatMoney(r.shares) }}</td>
                <td class="px-3 py-2 text-right font-medium">{{ formatMoney(r.dividend) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="mt-4 flex justify-end">
          <button type="button" class="btn-primary" :disabled="busy || !preview.member_count || !form.label.trim()" @click="post">
            {{ busy ? 'Crediting…' : `Credit ${formatMoney(preview.total_dividend)}` }}
          </button>
        </div>
      </div>
    </section>

    <h2 class="mt-8 font-semibold">Past dividends</h2>
    <ul class="card mt-3 divide-y divide-gray-100">
      <li v-for="r in runs" :key="r.id">
        <RouterLink :to="{ name: 'dividend-run', params: { id: r.id } }" class="flex flex-wrap items-center justify-between gap-3 p-4 hover:bg-gray-50">
          <div>
            <p class="font-medium">{{ r.label }} <span class="font-normal text-gray-500">· {{ r.rate_percent }}% · {{ r.member_count }} members</span></p>
            <p class="text-sm text-gray-600">Shares held on {{ formatDate(r.record_date) }} · credited {{ formatDate(r.value_date) }} by {{ r.created_by_name }}</p>
          </div>
          <div class="text-right text-sm tabular-nums">
            <p class="font-semibold">{{ formatMoney(r.total_dividend) }}</p>
            <StatusBadge v-if="r.status === 'reversed'" label="Reversed" style-class="bg-gray-100 text-gray-600" />
          </div>
        </RouterLink>
      </li>
      <li v-if="!runs.length" class="px-4 py-10 text-center text-sm text-gray-500">No dividends paid yet.</li>
    </ul>
  </div>
</template>
