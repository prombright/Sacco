<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { SparklesIcon } from '@heroicons/vue/24/outline'
import { api } from '@/api'
import { formatMoney } from '@/money'

interface Earnings {
  year: number
  dividend: { rate_percent: string; shares: string; estimate: string }
  profit: {
    basis: string
    your_savings_basis: string
    share_percent: number
    expected_pool: string
    received_so_far: string
    estimate: string
    credit_account: string
  }
  total_estimate: string
  last_year: { kind: 'dividend' | 'profit'; label: string; amount: string }[]
}

/** What the member can expect at year end: the dividend on their shares and their share of investment profit */
const data = ref<Earnings | null>(null)
onMounted(async () => {
  try {
    data.value = await api.get<Earnings>('/me/earnings')
  } catch {
    // An estimate is extra; the dashboard works without it
  }
})
</script>

<template>
  <section v-if="data" class="card overflow-hidden" aria-labelledby="earnings-title">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-gradient-to-r from-saffron-50 to-transparent px-5 py-4">
      <h2 id="earnings-title" class="flex items-center gap-2 font-semibold"><SparklesIcon class="h-5 w-5 text-saffron-600" aria-hidden="true" />What you can expect for {{ data.year }}</h2>
      <p class="text-sm text-gray-600">About <strong class="text-lg font-bold tabular-nums text-ink">{{ formatMoney(data.total_estimate) }}</strong> at the end of the year</p>
    </div>
    <div class="grid gap-px bg-line sm:grid-cols-2">
      <div class="bg-surface p-5">
        <p class="eyebrow">Dividend on your shares</p>
        <p class="figure mt-2 text-2xl">{{ formatMoney(data.dividend.estimate) }}</p>
        <p class="mt-1 text-sm text-gray-600">{{ data.dividend.rate_percent }}% of your share capital of {{ formatMoney(data.dividend.shares) }}. More shares, bigger dividend.</p>
      </div>
      <div class="bg-surface p-5">
        <p class="eyebrow">Your share of investment profit</p>
        <p class="figure mt-2 text-2xl">{{ formatMoney(data.profit.estimate) }}</p>
        <p class="mt-1 text-sm text-gray-600">
          Your {{ data.profit.basis === 'average_savings' ? 'average savings this year' : 'savings' }} ({{ formatMoney(data.profit.your_savings_basis) }})
          are <strong class="font-semibold">{{ data.profit.share_percent }}%</strong> of all members', so you get {{ data.profit.share_percent }}% of the
          {{ formatMoney(data.profit.expected_pool) }} the SACCO's investments are expected to earn. The more you save, the bigger your share.
        </p>
      </div>
    </div>
    <p class="border-t border-line px-5 py-3 text-xs text-gray-500">
      Estimates from today's figures; the final amounts are worked out and added to your account at the end of the year.
      <template v-if="data.last_year.length"> Last year you received {{ data.last_year.map((l) => `${formatMoney(l.amount)} (${l.label})`).join(' and ') }}.</template>
    </p>
  </section>
</template>
