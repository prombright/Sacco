<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { formatDate, formatMoney } from '@/money'
import { amountInWords, type Withdrawal, withdrawalStatus } from '@/withdrawals'
import SaccoMark from '@/components/SaccoMark.vue'

/** A printable withdrawal slip, kept with the cheque stub */
const route = useRoute()
const auth = useAuth()
const w = ref<Withdrawal | null>(null)
const error = ref('')

function printSlip() {
  window.print()
}

onMounted(async () => {
  try {
    w.value = (await api.get<{ request: Withdrawal }>(`/withdrawals/${route.params.id}`)).request
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load this slip.'
  }
})
</script>

<template>
  <div class="force-light min-h-screen bg-gray-100 px-4 py-8 text-ink print:bg-white print:p-0">
    <div class="mx-auto mb-4 flex max-w-2xl justify-between print:hidden">
      <button type="button" class="btn-secondary" @click="$router.back()">← Back</button>
      <button type="button" class="btn-primary" @click="printSlip">Print</button>
    </div>

    <div v-if="error" class="mx-auto max-w-2xl rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</div>

    <article v-if="w" class="mx-auto max-w-2xl rounded-xl bg-surface p-8 shadow-sm print:max-w-none print:rounded-none print:shadow-none">
      <header class="flex items-center gap-4 border-b border-gray-900 pb-4">
        <SaccoMark :sacco="auth.sacco" size="lg" />
        <div>
          <p class="text-lg font-semibold">{{ auth.sacco?.name }}</p>
          <p class="text-sm uppercase tracking-wide text-gray-600">Savings withdrawal slip</p>
        </div>
        <p class="ml-auto font-mono text-sm">{{ w.request_no }}</p>
      </header>

      <dl class="mt-6 grid grid-cols-[10rem_1fr] gap-y-3 text-sm">
        <dt class="text-gray-600">Member</dt><dd class="font-medium">{{ w.full_name }}</dd>
        <dt class="text-gray-600">Account number</dt><dd class="font-mono">{{ w.account_no }}</dd>
        <dt v-if="w.employee_no" class="text-gray-600">Employee number</dt><dd v-if="w.employee_no">{{ w.employee_no }}</dd>
        <dt class="text-gray-600">Withdrawal</dt><dd class="tabular-nums">{{ formatMoney(w.amount) }}</dd>
        <template v-if="Number(w.fee) > 0">
          <dt class="text-gray-600">Withdrawal fee</dt><dd class="tabular-nums">{{ formatMoney(w.fee) }} {{ w.fee_on_top ? '(charged to savings)' : '(deducted)' }}</dd>
        </template>
        <dt class="text-gray-600">Cheque amount</dt><dd class="text-lg font-semibold tabular-nums">{{ formatMoney(w.cheque_amount) }}</dd>
        <dt class="text-gray-600">In words</dt><dd>{{ amountInWords(w.cheque_amount) }} {{ auth.sacco?.currency === 'UGX' ? 'shillings' : auth.sacco?.currency }} only</dd>
        <dt v-if="w.reason" class="text-gray-600">Reason</dt><dd v-if="w.reason">{{ w.reason }}</dd>
        <dt class="text-gray-600">Requested</dt><dd>{{ formatDate(w.created_at) }}</dd>
        <dt class="text-gray-600">Approved by</dt><dd>{{ w.decided_by_name ?? '—' }}<template v-if="w.decided_at"> · {{ formatDate(w.decided_at) }}</template></dd>
        <dt class="text-gray-600">Cheque number</dt><dd class="font-mono">{{ w.cheque_no ?? '—' }}</dd>
        <dt class="text-gray-600">Cheque date</dt><dd>{{ w.paid_on ? formatDate(w.paid_on) : '—' }}</dd>
        <dt class="text-gray-600">Status</dt><dd>{{ withdrawalStatus[w.status].label }}</dd>
      </dl>

      <div class="mt-14 grid grid-cols-2 gap-10 text-sm">
        <div><div class="border-t border-gray-900 pt-1">Received by (member)</div><p class="mt-6 border-t border-gray-300 pt-1 text-gray-500">Date</p></div>
        <div><div class="border-t border-gray-900 pt-1">Issued by (SACCO office)</div><p class="mt-6 border-t border-gray-300 pt-1 text-gray-500">Date</p></div>
      </div>
    </article>
  </div>
</template>
