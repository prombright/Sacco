<script setup lang="ts">
import { computed } from 'vue'
import type { Balances } from '@/types'
import { formatMoney } from '@/money'

const props = defineProps<{
  balances: Balances | null
  sharePrice?: string
  /** Shares held against the SACCO's minimum (dashboard) */
  sharePosition?: { shares: number | null; min_shares: number; still_needed: string } | null
}>()

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
      <template v-if="sharePosition && sharePosition.min_shares > 0 && sharePosition.shares !== null">
        <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100" role="progressbar" :aria-valuenow="Math.min(sharePosition.shares, sharePosition.min_shares)" aria-valuemin="0" :aria-valuemax="sharePosition.min_shares">
          <div class="h-full rounded-full" :class="Number(sharePosition.still_needed) > 0 ? 'bg-saffron-500' : 'bg-emerald-600'" :style="{ width: Math.min(100, (Number(balances?.shares ?? 0) / (Number(balances?.shares ?? 0) + Number(sharePosition.still_needed) || 1)) * 100) + '%' }" />
        </div>
        <p class="mt-1.5 text-sm" :class="Number(sharePosition.still_needed) > 0 ? 'text-amber-800' : 'text-gray-500'">
          {{ sharePosition.shares }} of {{ sharePosition.min_shares }} shares<template v-if="Number(sharePosition.still_needed) > 0"> · {{ formatMoney(sharePosition.still_needed) }} more to reach the minimum</template><template v-else> · minimum reached</template>
        </p>
      </template>
      <p v-else-if="shareCount !== null" class="mt-1 text-sm text-gray-500">{{ shareCount.toLocaleString() }} share{{ shareCount === 1 ? '' : 's' }}</p>
    </div>
  </div>
</template>
