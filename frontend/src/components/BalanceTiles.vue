<script setup lang="ts">
import { computed } from 'vue'
import type { Balances } from '@/types'
import { formatMoney } from '@/money'

const props = defineProps<{ balances: Balances | null; sharePrice?: string }>()

// Shown once the committee sets a share price
const shareCount = computed(() => {
  const price = Number(props.sharePrice ?? 0)
  return price > 0 && props.balances ? Math.floor(Number(props.balances.shares) / price) : null
})
</script>

<template>
  <div class="grid gap-4 sm:grid-cols-2">
    <div class="card p-5">
      <p class="text-sm font-medium text-gray-600">Savings balance</p>
      <p class="mt-2 text-2xl font-semibold tabular-nums">{{ balances ? formatMoney(balances.savings) : '…' }}</p>
    </div>
    <div class="card p-5">
      <p class="text-sm font-medium text-gray-600">Share capital</p>
      <p class="mt-2 text-2xl font-semibold tabular-nums">{{ balances ? formatMoney(balances.shares) : '…' }}</p>
      <p v-if="shareCount !== null" class="mt-1 text-sm text-gray-500">{{ shareCount.toLocaleString() }} shares</p>
    </div>
  </div>
</template>
