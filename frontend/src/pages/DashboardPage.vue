<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import type { Balances, Transaction } from '@/types'
import BalanceTiles from '@/components/BalanceTiles.vue'
import TransactionList from '@/components/TransactionList.vue'
import { formatDate, formatMoney } from '@/money'

const auth = useAuth()
interface Summary {
  balances: Balances
  pledged: string
  share_price: string
  loan_owed: string
  next_repayment: { date: string; amount: string } | null
  guarantee_requests: number
  recent: Transaction[]
}

const balances = ref<Balances | null>(null)
const summary = ref<Summary | null>(null)
const sharePrice = ref('0')
const recent = ref<Transaction[]>([])
const error = ref('')

onMounted(async () => {
  try {
    const res = await api.get<Summary>('/me/summary')
    summary.value = res
    balances.value = res.balances
    sharePrice.value = res.share_price
    recent.value = res.recent
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load your balances.'
  }
})
</script>

<template>
  <div>
    <h1 class="text-xl font-semibold">Hello, {{ auth.user?.full_name.split(' ')[0] }}</h1>
    <p class="mt-1 text-sm text-gray-600">Account number <span class="font-mono">{{ auth.user?.account_no }}</span></p>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <RouterLink
      v-if="summary?.guarantee_requests"
      :to="{ name: 'loans' }"
      class="mt-5 flex items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 hover:bg-amber-100"
    >
      <span>
        <strong class="font-semibold">{{ summary.guarantee_requests }} member{{ summary.guarantee_requests === 1 ? '' : 's' }}</strong>
        {{ summary.guarantee_requests === 1 ? 'has' : 'have' }} asked you to guarantee a loan.
      </span>
      <span class="font-semibold">Answer →</span>
    </RouterLink>

    <div class="mt-6">
      <BalanceTiles :balances="balances" :share-price="sharePrice" />
      <p v-if="summary && Number(summary.pledged) > 0" class="mt-2 text-sm text-gray-600">
        {{ formatMoney(summary.pledged) }} of your savings is pledged as a loan guarantee.
      </p>
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-3">
      <RouterLink :to="{ name: 'loans' }" class="card p-5 hover:border-gray-300">
        <p class="text-sm font-medium text-gray-600">Loan balance</p>
        <p class="mt-2 text-2xl font-semibold tabular-nums">{{ summary ? formatMoney(summary.loan_owed) : '…' }}</p>
        <p class="mt-1 text-xs text-gray-500">Principal and interest still to pay</p>
      </RouterLink>
      <RouterLink :to="{ name: 'loans' }" class="card p-5 hover:border-gray-300">
        <p class="text-sm font-medium text-gray-600">Next repayment</p>
        <template v-if="summary?.next_repayment">
          <p class="mt-2 text-2xl font-semibold tabular-nums">{{ formatMoney(summary.next_repayment.amount) }}</p>
          <p class="mt-1 text-xs text-gray-500">Due {{ formatDate(summary.next_repayment.date) }}</p>
        </template>
        <p v-else class="mt-2 text-2xl font-semibold text-gray-300">—</p>
      </RouterLink>
      <div class="card p-5">
        <p class="text-sm font-medium text-gray-600">Dividend earnings</p>
        <p class="mt-2 text-2xl font-semibold text-gray-300">—</p>
        <p class="mt-1 text-xs text-gray-400">Coming with dividends</p>
      </div>
    </div>

    <div class="mt-8 flex items-center justify-between">
      <h2 class="font-semibold">Recent activity</h2>
      <RouterLink :to="{ name: 'statement' }" class="text-sm font-medium text-brand hover:underline">Full statement</RouterLink>
    </div>
    <div class="card mt-3 overflow-hidden">
      <TransactionList :transactions="recent" show-account />
    </div>
  </div>
</template>
