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
    <div class="relative overflow-hidden rounded-2xl bg-navy-900 p-5 text-white shadow-lift">
      <div class="absolute -right-14 -top-16 h-32 w-32 rounded-full border-2 border-saffron-400/90" aria-hidden="true" />
      <p class="text-xs font-semibold uppercase tracking-wider text-navy-200">Savings balance</p>
      <p class="mt-2 text-3xl font-bold tabular-nums">{{ balances ? formatMoney(balances.savings) : '…' }}</p>
    </div>
    <div class="card p-5">
      <p class="eyebrow">Share capital</p>
      <p class="figure mt-2 text-3xl">{{ balances ? formatMoney(balances.shares) : '…' }}</p>
      <p v-if="shareCount !== null" class="mt-1 text-sm text-gray-500">{{ shareCount.toLocaleString() }} shares</p>
    </div>
  </div>
</template>
