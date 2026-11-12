<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue'
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api'
import type { Loan } from '@/types'
import { formatDate, formatMoney } from '@/money'
import { loanFigures, loanStatus } from '@/loans'
import StatusBadge from '@/components/StatusBadge.vue'
import GuaranteeRequests from './GuaranteeRequests.vue'

const loans = ref<Loan[] | null>(null)
const error = ref('')

// One application at a time
const inProgress = computed(() => loans.value?.find((l) => ['pending_guarantors', 'pending_approval', 'approved'].includes(l.status)))

onMounted(async () => {
  try {
    loans.value = (await api.get<{ loans: Loan[] }>('/me/loans')).loans
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load your loans.'
  }
})
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <p class="eyebrow">My account</p>
        <h1 class="mt-1 text-2xl font-bold">Loans</h1>
        <p class="mt-1 text-sm text-gray-600">Your loans, and members who've asked you to guarantee theirs.</p>
      </div>
      <RouterLink v-if="loans && !inProgress" :to="{ name: 'loan-apply' }" class="btn-primary">Apply for a loan</RouterLink>
    </div>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <GuaranteeRequests class="mt-6" />

    <h2 class="mt-8 font-semibold">My loans</h2>
    <p v-if="inProgress" class="mt-1 text-sm text-gray-600">You can apply again once {{ inProgress.loan_no }} has been decided.</p>

    <div class="mt-3 space-y-3">
      <RouterLink
        v-for="loan in loans ?? []"
        :key="loan.id"
        :to="{ name: 'loan', params: { id: loan.id } }"
        class="card block p-4 transition hover:border-gray-300 hover:shadow-sm"
      >
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p class="font-semibold tabular-nums">{{ formatMoney(loan.amount) }} <span class="font-normal text-gray-500">over {{ loan.months }} months</span></p>
            <p class="text-sm text-gray-600">{{ loan.loan_no }} · {{ loan.purpose }} · applied {{ formatDate(loan.created_at) }}</p>
          </div>
          <StatusBadge :label="loanStatus[loan.status].label" :style-class="loanStatus[loan.status].style" />
        </div>
        <template v-if="loan.position">
          <dl class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
            <div><dt class="text-gray-600">Borrowed</dt><dd class="font-semibold tabular-nums">{{ formatMoney(loan.amount) }}</dd></div>
            <div><dt class="text-gray-600">Interest</dt><dd class="font-semibold tabular-nums">{{ formatMoney(loan.position.total_interest) }}</dd></div>
            <div><dt class="text-gray-600">Total</dt><dd class="font-semibold tabular-nums">{{ formatMoney(String(loanFigures(loan).total)) }}</dd></div>
            <div><dt class="text-gray-600">Paid</dt><dd class="font-semibold tabular-nums">{{ formatMoney(loan.position.paid) }}</dd></div>
            <div><dt class="text-gray-600">Still to pay</dt><dd class="font-semibold tabular-nums">{{ formatMoney(loan.position.total_left) }}</dd></div>
          </dl>
          <p v-if="loan.status === 'active'" class="mt-2 text-sm">
            <span class="text-gray-600">Next payment</span> <span class="font-semibold tabular-nums">{{ formatMoney(loan.position.next_due_amount) }}</span> <span class="text-gray-600">on {{ formatDate(loan.position.next_due_date) }}</span>
            <span v-if="Number(loan.position.overdue) > 0" class="ml-2 font-semibold text-red-700">· {{ formatMoney(loan.position.overdue) }} overdue</span>
          </p>
        </template>
        <p v-if="loan.guarantee_shortfall" class="mt-2 text-sm text-amber-800">
          Still needs
          <template v-if="loan.guarantee_shortfall.guarantors">{{ loan.guarantee_shortfall.guarantors }} more guarantor{{ loan.guarantee_shortfall.guarantors === 1 ? '' : 's' }}</template>
          <template v-if="loan.guarantee_shortfall.guarantors && Number(loan.guarantee_shortfall.amount) > 0"> and </template>
          <template v-if="Number(loan.guarantee_shortfall.amount) > 0">{{ formatMoney(loan.guarantee_shortfall.amount) }} more in guarantees</template>.
        </p>
      </RouterLink>
      <div v-if="loans && !loans.length" class="card">
        <EmptyState title="You haven't taken a loan yet" text="See how much you can borrow and what it would cost each month, before you apply.">
          <RouterLink :to="{ name: 'loan-apply' }" class="btn-primary">Apply for a loan</RouterLink>
        </EmptyState>
      </div>
    </div>
  </div>
</template>
