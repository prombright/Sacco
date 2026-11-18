<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/api'
import { formatMoney } from '@/money'
import CountUp from '@/components/CountUp.vue'

interface Earnings {
  year: number
  savings_interest: { rate_year: number; savings: string; a_month: string; this_year: string; held: string }
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

/**
 * What the member earns, each kind on its own: interest on their savings every month (held for
 * them, not for loans or withdrawals); the dividend on their shares at the end of the year; and
 * their share of what the SACCO's investments earn.
 */
const data = ref<Earnings | null>(null)
onMounted(async () => {
  try {
    data.value = await api.get<Earnings>('/me/earnings')
  } catch {
    // Extra; the dashboard works without it
  }
})
const rate = (v: number) => `${+v.toFixed(2)}%`
</script>

<template>
  <section v-if="data" class="card overflow-hidden" aria-labelledby="earnings-title">
    <div class="border-b border-line bg-gradient-to-r from-saffron-50 to-transparent px-5 py-4">
      <h2 id="earnings-title" class="font-semibold">What your money earns</h2>
    </div>
    <div class="grid gap-px bg-line" :class="Number(data.profit.estimate) > 0 ? 'lg:grid-cols-3' : 'sm:grid-cols-2'">
      <!-- Savings: interest every month, held apart -->
      <div class="bg-surface p-5">
        <p class="eyebrow">Interest on your savings, each month</p>
        <template v-if="data.savings_interest.rate_year > 0">
          <p class="figure mt-2 text-2xl"><CountUp :value="data.savings_interest.a_month" :delay="150" /></p>
          <p class="mt-1 text-sm text-gray-600">
            Your savings of {{ formatMoney(data.savings_interest.savings) }} earn {{ rate(data.savings_interest.rate_year) }} a year: about this much every month.
          </p>
        </template>
        <p v-else class="mt-2 text-sm text-gray-600">The SACCO hasn't set a savings interest rate yet.</p>
        <p v-if="Number(data.savings_interest.held) > 0" class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-sm">
          Held for you: <strong class="tabular-nums">{{ formatMoney(data.savings_interest.held) }}</strong>
          <span class="block text-xs text-gray-500">Interest earned and not yet paid out. It is kept apart from your savings, so it doesn't count for loans or withdrawals; the office pays it out.</span>
        </p>
      </div>
      <!-- Shares: the dividend at year end, only from shares -->
      <div class="bg-surface p-5">
        <p class="eyebrow">Dividend on your shares, end of {{ data.year }}</p>
        <p class="figure mt-2 text-2xl"><CountUp :value="data.dividend.estimate" :delay="300" /></p>
        <p class="mt-1 text-sm text-gray-600">{{ data.dividend.rate_percent }}% of your share capital of {{ formatMoney(data.dividend.shares) }}. Dividends come from shares only: more shares, bigger dividend.</p>
      </div>
      <!-- Investments: a share of what they earn -->
      <div v-if="Number(data.profit.estimate) > 0" class="bg-surface p-5">
        <p class="eyebrow">Your share of investment profit</p>
        <p class="figure mt-2 text-2xl"><CountUp :value="data.profit.estimate" :delay="450" /></p>
        <p class="mt-1 text-sm text-gray-600">
          Your {{ data.profit.basis === 'average_savings' ? 'average savings this year' : 'savings' }} are {{ data.profit.share_percent }}% of all members', so you get
          {{ data.profit.share_percent }}% of the {{ formatMoney(data.profit.expected_pool) }} the SACCO's investments are expected to earn.
        </p>
      </div>
    </div>
    <p class="border-t border-line px-5 py-3 text-xs text-gray-500">
      Estimates from today's figures. The dividend is worked out and added at the end of the year.
      <template v-if="data.last_year.length"> Last year you received {{ data.last_year.map((l) => `${formatMoney(l.amount)} (${l.label})`).join(' and ') }}.</template>
    </p>
  </section>
</template>
