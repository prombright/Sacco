<script setup lang="ts">
import { useAuth } from '@/stores/auth'

const auth = useAuth()

// Filled in as each part is built: savings and shares (ledger), loans, dividends
const tiles = [
  { label: 'Savings balance', note: 'Coming with the savings ledger' },
  { label: 'Shares owned', note: 'Coming with the savings ledger' },
  { label: 'Active loan balance', note: 'Coming with loans' },
  { label: 'Next repayment', note: 'Coming with loans' },
  { label: 'Dividend earnings', note: 'Coming with dividends' },
]
</script>

<template>
  <div>
    <h1 class="text-xl font-semibold">Hello, {{ auth.user?.full_name.split(' ')[0] }}</h1>
    <p class="mt-1 text-sm text-gray-600">Account number {{ auth.user?.account_no }}</p>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <div v-for="tile in tiles" :key="tile.label" class="card p-5">
        <p class="text-sm font-medium text-gray-600">{{ tile.label }}</p>
        <p class="mt-2 text-2xl font-semibold text-gray-300">—</p>
        <p class="mt-1 text-xs text-gray-400">{{ tile.note }}</p>
      </div>
    </div>
  </div>
</template>
