<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import type { Balances, Transaction } from '@/types'
import BalanceTiles from '@/components/BalanceTiles.vue'
import TransactionList from '@/components/TransactionList.vue'

const auth = useAuth()
const balances = ref<Balances | null>(null)
const sharePrice = ref('0')
const recent = ref<Transaction[]>([])
const error = ref('')

// Filled in as loans and dividends are built
const comingSoon = [
  { label: 'Active loan balance', note: 'Coming with loans' },
  { label: 'Next repayment', note: 'Coming with loans' },
  { label: 'Dividend earnings', note: 'Coming with dividends' },
]

onMounted(async () => {
  try {
    const res = await api.get<{ balances: Balances; share_price: string; recent: Transaction[] }>('/me/summary')
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

    <div class="mt-6">
      <BalanceTiles :balances="balances" :share-price="sharePrice" />
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-3">
      <div v-for="tile in comingSoon" :key="tile.label" class="card p-5">
        <p class="text-sm font-medium text-gray-600">{{ tile.label }}</p>
        <p class="mt-2 text-2xl font-semibold text-gray-300">—</p>
        <p class="mt-1 text-xs text-gray-400">{{ tile.note }}</p>
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
