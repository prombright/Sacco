<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api'
import { formatDate, formatMoney } from '@/money'
import StatusBadge from '@/components/StatusBadge.vue'
import Modal from '@/components/Modal.vue'

interface Run {
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
  reversed_by_name: string | null
  reversed_at: string | null
  reversal_reason: string | null
}
interface Row {
  full_name: string
  account_no: string
  amount: string
  narration: string
}

const route = useRoute()
const run = ref<Run | null>(null)
const rows = ref<Row[]>([])
const error = ref('')
const reversing = ref(false)
const reason = ref('')
const formError = ref('')
const busy = ref(false)

async function load() {
  const res = await api.get<{ run: Run; rows: Row[] }>(`/dividends/${route.params.id}`)
  run.value = res.run
  rows.value = res.rows
}
onMounted(() => load().catch((e) => (error.value = e instanceof ApiError ? e.message : 'Couldn\'t load this dividend.')))

async function reverse() {
  formError.value = ''
  busy.value = true
  try {
    await api.post(`/dividends/${route.params.id}/reverse`, { reason: reason.value })
    reversing.value = false
    await load()
  } catch (e) {
    formError.value = e instanceof ApiError ? e.message : 'Something went wrong. Nothing was changed.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <RouterLink :to="{ name: 'dividends' }" class="text-sm font-medium text-gray-600 hover:text-gray-900">← Dividends</RouterLink>
    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <template v-if="run">
      <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-xl font-semibold">Dividend {{ run.label }}</h1>
          <p class="mt-1 text-sm text-gray-600">{{ run.rate_percent }}% on shares held on {{ formatDate(run.record_date) }} · credited {{ formatDate(run.value_date) }} by {{ run.created_by_name }}</p>
        </div>
        <StatusBadge v-if="run.status === 'reversed'" label="Reversed" style-class="bg-gray-100 text-gray-600" />
        <StatusBadge v-else label="Credited" style-class="bg-green-50 text-green-800" />
      </div>
      <p v-if="run.status === 'reversed'" class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700">
        Reversed {{ formatDate(run.reversed_at) }} by {{ run.reversed_by_name }}: {{ run.reversal_reason }}
      </p>

      <div class="mt-5 grid gap-3 sm:grid-cols-3">
        <div class="card p-4"><p class="text-xs text-gray-600">Members</p><p class="text-lg font-semibold">{{ run.member_count }}</p></div>
        <div class="card p-4"><p class="text-xs text-gray-600">Share capital</p><p class="text-lg font-semibold tabular-nums">{{ formatMoney(run.total_shares) }}</p></div>
        <div class="card p-4"><p class="text-xs text-gray-600">Dividend paid</p><p class="text-lg font-semibold tabular-nums">{{ formatMoney(run.total_dividend) }}</p></div>
      </div>

      <div class="card mt-5 overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
            <tr><th class="px-4 py-3">Member</th><th class="px-4 py-3">Account</th><th class="px-4 py-3">Worked out as</th><th class="px-4 py-3 text-right">Dividend</th></tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="r in rows" :key="r.account_no">
              <td class="px-4 py-2">{{ r.full_name }}</td>
              <td class="px-4 py-2 font-mono">{{ r.account_no }}</td>
              <td class="px-4 py-2 text-gray-600">{{ r.narration }}</td>
              <td class="px-4 py-2 text-right tabular-nums">{{ formatMoney(r.amount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="run.status === 'posted'" class="mt-6">
        <button type="button" class="btn-secondary" @click="reversing = true">Reverse this dividend</button>
        <p class="mt-2 text-sm text-gray-600">Takes every member's dividend back off their shares, for example if the rate was wrong.</p>
      </div>
    </template>

    <Modal v-if="reversing" title="Reverse this dividend" @close="reversing = false">
      <form class="space-y-4" @submit.prevent="reverse">
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <div><label for="reason" class="label">Why?</label><input id="reason" v-model="reason" class="input" maxlength="255" placeholder="e.g. Wrong rate" /></div>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="reversing = false">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !reason.trim()">Reverse dividend</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
