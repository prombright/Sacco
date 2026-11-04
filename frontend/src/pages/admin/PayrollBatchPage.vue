<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api'
import { formatDate, formatMoney } from '@/money'
import StatusBadge from '@/components/StatusBadge.vue'
import Modal from '@/components/Modal.vue'

interface Batch {
  id: number
  period: string
  file_name: string | null
  row_count: number
  total_savings: string
  total_shares: string
  total_loans: string
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
  savings: string
  shares: string
  loan: string
  loan_no: string | null
}

const route = useRoute()
const batch = ref<Batch | null>(null)
const rows = ref<Row[]>([])
const error = ref('')
const reversing = ref(false)
const reason = ref('')
const formError = ref('')
const busy = ref(false)

async function load() {
  const res = await api.get<{ batch: Batch; rows: Row[] }>(`/payroll/batches/${route.params.id}`)
  batch.value = res.batch
  rows.value = res.rows
}
onMounted(() => load().catch((e) => (error.value = e instanceof ApiError ? e.message : 'Couldn\'t load this upload.')))

async function reverse() {
  formError.value = ''
  busy.value = true
  try {
    await api.post(`/payroll/batches/${route.params.id}/reverse`, { reason: reason.value })
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
    <RouterLink :to="{ name: 'payroll' }" class="text-sm font-medium text-gray-600 hover:text-gray-900">← Payroll uploads</RouterLink>
    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <template v-if="batch">
      <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-xl font-semibold">Payroll {{ batch.period }}</h1>
          <p class="mt-1 text-sm text-gray-600">{{ batch.file_name }} · posted {{ formatDate(batch.created_at) }} by {{ batch.created_by_name }}</p>
        </div>
        <StatusBadge v-if="batch.status === 'reversed'" label="Reversed" style-class="bg-gray-100 text-gray-600" />
        <StatusBadge v-else label="Posted" style-class="bg-green-50 text-green-800" />
      </div>

      <p v-if="batch.status === 'reversed'" class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700">
        Reversed {{ formatDate(batch.reversed_at) }} by {{ batch.reversed_by_name }}: {{ batch.reversal_reason }}
      </p>

      <div class="mt-5 grid gap-3 sm:grid-cols-4">
        <div class="card p-4"><p class="text-xs text-gray-600">Members</p><p class="text-lg font-semibold">{{ batch.row_count }}</p></div>
        <div class="card p-4"><p class="text-xs text-gray-600">Savings</p><p class="text-lg font-semibold tabular-nums">{{ formatMoney(batch.total_savings) }}</p></div>
        <div class="card p-4"><p class="text-xs text-gray-600">Shares</p><p class="text-lg font-semibold tabular-nums">{{ formatMoney(batch.total_shares) }}</p></div>
        <div class="card p-4"><p class="text-xs text-gray-600">Loan repayments</p><p class="text-lg font-semibold tabular-nums">{{ formatMoney(batch.total_loans) }}</p></div>
      </div>

      <div class="card mt-5 overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
            <tr><th class="px-4 py-3">Member</th><th class="px-4 py-3">Account</th><th class="px-4 py-3 text-right">Savings</th><th class="px-4 py-3 text-right">Shares</th><th class="px-4 py-3 text-right">Loan</th></tr>
          </thead>
          <tbody class="divide-y divide-gray-100 tabular-nums">
            <tr v-for="r in rows" :key="r.account_no">
              <td class="px-4 py-2">{{ r.full_name }}</td>
              <td class="px-4 py-2 font-mono">{{ r.account_no }}</td>
              <td class="px-4 py-2 text-right">{{ Number(r.savings) ? formatMoney(r.savings) : '' }}</td>
              <td class="px-4 py-2 text-right">{{ Number(r.shares) ? formatMoney(r.shares) : '' }}</td>
              <td class="px-4 py-2 text-right">{{ Number(r.loan) ? formatMoney(r.loan) : '' }}<span v-if="r.loan_no" class="block text-xs text-gray-500">{{ r.loan_no }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="batch.status === 'posted'" class="mt-6">
        <button v-can="'money'" type="button" class="btn-secondary" @click="reversing = true">Reverse this upload</button>
        <p class="mt-2 text-sm text-gray-600">Undoes every entry it made, for example if the wrong month's file was posted. Both stay in each member's history.</p>
      </div>
    </template>

    <Modal v-if="reversing" title="Reverse this upload" @close="reversing = false">
      <form class="space-y-4" @submit.prevent="reverse">
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <p class="text-sm text-gray-700">Every savings, shares and loan entry from this upload will be cancelled.</p>
        <div><label for="reason" class="label">Why?</label><input id="reason" v-model="reason" class="input" maxlength="255" placeholder="e.g. Wrong month's file" /></div>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="reversing = false">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !reason.trim()">Reverse upload</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
