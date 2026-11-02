<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api'
import type { Loan, LoanStatus } from '@/types'
import { formatDate, formatMoney } from '@/money'
import { loanStatus } from '@/loans'
import StatusBadge from '@/components/StatusBadge.vue'

// In the order an admin works through them
const tabs: { status: LoanStatus; label: string }[] = [
  { status: 'pending_approval', label: 'To approve' },
  { status: 'approved', label: 'To pay out' },
  { status: 'pending_guarantors', label: 'Waiting for guarantors' },
  { status: 'active', label: 'Active' },
  { status: 'closed', label: 'Repaid' },
  { status: 'rejected', label: 'Rejected' },
  { status: 'cancelled', label: 'Cancelled' },
]

const status = ref<LoanStatus>('pending_approval')
const q = ref('')
const loans = ref<Loan[]>([])
const counts = ref<Record<string, number>>({})
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  try {
    const res = await api.get<{ loans: Loan[]; counts: Record<string, number> }>('/loans', { status: status.value, q: q.value || undefined })
    loans.value = res.loans
    counts.value = res.counts
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load loans.'
  } finally {
    loading.value = false
  }
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(q, () => { clearTimeout(timer); timer = setTimeout(load, 300) })
watch(status, load)
onMounted(load)
</script>

<template>
  <div>
    <h1 class="text-xl font-semibold">Loan applications</h1>
    <p class="mt-1 text-sm text-gray-600">Approve, pay out and record repayments. Your own loans are handled by another admin.</p>

    <div class="mt-5 flex gap-1 overflow-x-auto pb-1" role="tablist">
      <button
        v-for="tab in tabs"
        :key="tab.status"
        type="button"
        role="tab"
        :aria-selected="status === tab.status"
        class="whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium"
        :class="status === tab.status ? 'bg-brand text-white' : 'text-gray-600 hover:bg-gray-100'"
        @click="status = tab.status"
      >
        {{ tab.label }}<span v-if="counts[tab.status]" class="ml-1 opacity-75">{{ counts[tab.status] }}</span>
      </button>
    </div>

    <input v-model="q" type="search" class="input mt-3 sm:max-w-xs" placeholder="Search name, account or loan no." aria-label="Search loans" />

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <ul class="card mt-4 divide-y divide-gray-100">
      <li v-for="loan in loans" :key="loan.id">
        <RouterLink :to="{ name: 'loan', params: { id: loan.id } }" class="flex flex-wrap items-center justify-between gap-3 p-4 hover:bg-gray-50">
          <div class="min-w-0">
            <p class="font-medium">{{ loan.borrower_name }} <span class="font-mono text-xs font-normal text-gray-500">{{ loan.borrower_account_no }}</span></p>
            <p class="text-sm text-gray-600">{{ loan.loan_no }} · {{ loan.purpose }} · applied {{ formatDate(loan.created_at) }}</p>
          </div>
          <div class="text-right">
            <p class="font-semibold tabular-nums">{{ formatMoney(loan.amount) }} <span class="text-sm font-normal text-gray-500">/ {{ loan.months }} mo</span></p>
            <p v-if="loan.position && loan.status === 'active'" class="text-sm tabular-nums text-gray-600">
              {{ formatMoney(loan.position.total_left) }} left
              <span v-if="Number(loan.position.overdue) > 0" class="text-red-700">· {{ formatMoney(loan.position.overdue) }} overdue</span>
            </p>
            <StatusBadge v-else :label="loanStatus[loan.status].label" :style-class="loanStatus[loan.status].style" />
          </div>
        </RouterLink>
      </li>
      <li v-if="!loading && !loans.length" class="px-4 py-10 text-center text-sm text-gray-500">Nothing here.</li>
    </ul>
  </div>
</template>
