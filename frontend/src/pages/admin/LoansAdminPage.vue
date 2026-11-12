<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue'
import { ClipboardDocumentCheckIcon } from '@heroicons/vue/24/outline'
import { ask } from '@/confirm'
import { onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api'
import type { Loan, LoanStatus } from '@/types'
import { formatDate, formatMoney } from '@/money'
import { loanFigures, loanStatus } from '@/loans'
import StatusBadge from '@/components/StatusBadge.vue'
import ExistingLoanForm from './ExistingLoanForm.vue'

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

const route = useRoute()
/** What each tab says when it is empty */
const EMPTY: Partial<Record<LoanStatus, [string, string]>> = {
  pending_approval: ['No loans waiting for approval', 'Applications come here once their guarantors have agreed.'],
  approved: ['Nothing to pay out', 'Approved loans wait here until the cheque is recorded.'],
  pending_guarantors: ['No loans waiting for guarantors', 'New applications sit here while guarantors decide.'],
  active: ['No running loans', 'Loans appear here once they are paid out.'],
  closed: ['No repaid loans yet', 'Fully repaid loans are kept here.'],
  rejected: ['No rejected loans', ''],
  cancelled: ['No cancelled loans', ''],
}
const status = ref<LoanStatus>((route.meta.loanStatus as LoanStatus) ?? 'pending_approval')
const q = ref('')
const loans = ref<Loan[]>([])
const counts = ref<Record<string, number>>({})
const loading = ref(true)
const error = ref('')
const charging = ref(false)
const charged = ref('')
const bringingIn = ref(false)

/** Charges every late loan its penalty; safe to press again (once per loan per month) */
async function chargePenalties() {
  if (!await ask('Charge the late-payment penalty on every loan with instalments past their due date? Each loan is charged at most once a month.')) return
  charging.value = true
  error.value = ''
  try {
    const res = await api.post<{ charged: { loan_no: string; borrower: string; penalty: string }[] }>('/loans/penalties/run')
    charged.value = res.charged.length
      ? `Penalties charged on ${res.charged.length} loan${res.charged.length === 1 ? '' : 's'}: ` + res.charged.map((c) => `${c.borrower} (${c.loan_no}) ${formatMoney(c.penalty)}`).join(', ') + '.'
      : 'No loan needed a penalty: none is late, or each late one was already charged this month.'
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : "Couldn't charge penalties."
  } finally {
    charging.value = false
  }
}

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
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <p class="eyebrow">Loans</p>
        <h1 class="mt-1 text-2xl font-bold">Loan applications</h1>
        <p class="mt-1 text-sm text-gray-600">Approve, pay out and record repayments. Your own loans are handled by another admin.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button v-can="'loans'" type="button" class="btn-secondary" :disabled="charging" @click="chargePenalties">Charge late-payment penalties</button>
        <button v-can="'loans'" type="button" class="btn-primary" @click="bringingIn = true">Record a loan from old records</button>
      </div>
    </div>
    <div v-if="charged" class="mt-4 rounded-lg bg-saffron-50 px-4 py-3 text-sm text-gray-800" role="status">{{ charged }}</div>

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
              Interest {{ formatMoney(loan.position.total_interest) }} · paid {{ formatMoney(loan.position.paid) }} of {{ formatMoney(String(loanFigures(loan).total)) }} · {{ formatMoney(loan.position.total_left) }} left
              <span v-if="Number(loan.position.overdue) > 0" class="text-red-700">· {{ formatMoney(loan.position.overdue) }} overdue</span>
            </p>
            <StatusBadge v-else :label="loanStatus[loan.status].label" :style-class="loanStatus[loan.status].style" />
          </div>
        </RouterLink>
      </li>
      <li v-if="!loading && !loans.length">
        <EmptyState v-if="q" title="No loans match" :text="`Nothing in this list matches “${q}”.`" />
        <EmptyState v-else :title="EMPTY[status]?.[0] ?? 'Nothing here'" :text="EMPTY[status]?.[1]" :icon="ClipboardDocumentCheckIcon" />
      </li>
    </ul>
    <ExistingLoanForm v-if="bringingIn" @close="bringingIn = false" />
  </div>
</template>
