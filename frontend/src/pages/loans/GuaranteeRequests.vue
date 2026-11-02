<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api'
import type { LoanStatus } from '@/types'
import { formatDate, formatMoney } from '@/money'
import { guarantorStatus, loanStatus } from '@/loans'
import StatusBadge from '@/components/StatusBadge.vue'

interface Guarantee {
  id: number
  amount: string
  status: string
  created_at: string
  loan_id: number
  loan_no: string
  loan_amount: string
  months: number
  purpose: string | null
  loan_status: LoanStatus
  borrower_name: string
}

const guarantees = ref<Guarantee[]>([])
const pledged = ref('0')
const freeSavings = ref('0')
const busy = ref<number | null>(null)
const error = ref('')

// Requests still waiting for an answer; the rest is what they've agreed to
const pending = computed(() => guarantees.value.filter((g) => g.status === 'pending' && g.loan_status === 'pending_guarantors'))
const standing = computed(() => guarantees.value.filter((g) => g.status === 'accepted' && ['pending_approval', 'approved', 'active', 'pending_guarantors'].includes(g.loan_status)))

async function load() {
  const res = await api.get<{ guarantees: Guarantee[]; pledged: string; free_savings: string }>('/me/guarantees')
  guarantees.value = res.guarantees
  pledged.value = res.pledged
  freeSavings.value = res.free_savings
}

async function answer(g: Guarantee, action: 'accept' | 'decline') {
  if (action === 'decline' && !confirm(`Decline to guarantee ${g.borrower_name}'s loan?`)) return
  error.value = ''
  busy.value = g.id
  try {
    await api.post(`/guarantees/${g.id}/${action}`)
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Something went wrong. Please try again.'
  } finally {
    busy.value = null
  }
}

onMounted(load)
</script>

<template>
  <div v-if="pending.length || standing.length">
    <section v-if="pending.length" class="card border-amber-200 bg-amber-50/40 p-5">
      <h2 class="font-semibold">Asked to guarantee</h2>
      <p class="mt-1 text-sm text-gray-600">
        Agreeing ties up that much of your savings until the loan is repaid. You have {{ formatMoney(freeSavings) }} free to pledge.
      </p>
      <div v-if="error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
      <ul class="mt-4 space-y-3">
        <li v-for="g in pending" :key="g.id" class="card p-4">
          <p class="font-medium">{{ g.borrower_name }} <span class="font-normal text-gray-600">asks you to guarantee</span> <span class="tabular-nums">{{ formatMoney(g.amount) }}</span></p>
          <p class="mt-1 text-sm text-gray-600">
            of a {{ formatMoney(g.loan_amount) }} loan over {{ g.months }} months<template v-if="g.purpose"> for {{ g.purpose.toLowerCase() }}</template> · asked {{ formatDate(g.created_at) }}
          </p>
          <div class="mt-3 flex gap-2">
            <button type="button" class="btn-primary" :disabled="busy === g.id" @click="answer(g, 'accept')">Agree</button>
            <button type="button" class="btn-secondary" :disabled="busy === g.id" @click="answer(g, 'decline')">Decline</button>
          </div>
        </li>
      </ul>
    </section>

    <section v-if="standing.length" :class="{ 'mt-6': pending.length }">
      <h2 class="font-semibold">Loans I guarantee</h2>
      <p class="mt-1 text-sm text-gray-600">{{ formatMoney(pledged) }} of your savings is pledged right now. It's released as these loans are repaid.</p>
      <ul class="card mt-3 divide-y divide-gray-100">
        <li v-for="g in standing" :key="g.id" class="flex flex-wrap items-center justify-between gap-2 p-4 text-sm">
          <div>
            <p class="font-medium">{{ g.borrower_name }}</p>
            <p class="text-gray-600">{{ g.loan_no }} · you guarantee <span class="tabular-nums">{{ formatMoney(g.amount) }}</span> of {{ formatMoney(g.loan_amount) }}</p>
          </div>
          <div class="flex gap-2">
            <StatusBadge :label="guarantorStatus[g.status].label" :style-class="guarantorStatus[g.status].style" />
            <StatusBadge :label="loanStatus[g.loan_status].label" :style-class="loanStatus[g.loan_status].style" />
          </div>
        </li>
      </ul>
    </section>
  </div>
</template>
