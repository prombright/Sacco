<script setup lang="ts">
import { computed } from 'vue'
import EmptyState from '@/components/EmptyState.vue'
import type { Transaction } from '@/types'
import { accountLabel, formatDate, formatMoney, transactionTypeLabel } from '@/money'

/**
 * A ledger: a table on wide screens, a list on phones. Admins get a Reverse button per entry.
 * `byMonth` puts a heading over each month with what came in and went out.
 */
const props = defineProps<{ transactions: Transaction[]; showAccount?: boolean; canReverse?: boolean; byMonth?: boolean }>()

const groups = computed(() => {
  if (!props.byMonth) return [{ key: 'all', label: '', in: 0, out: 0, rows: props.transactions }]
  const out: { key: string; label: string; in: number; out: number; rows: Transaction[] }[] = []
  for (const t of props.transactions) {
    const key = t.value_date.slice(0, 7)
    let g = out.at(-1)
    if (!g || g.key !== key) {
      g = { key, label: new Date(`${key}-01T00:00:00`).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }), in: 0, out: 0, rows: [] }
      out.push(g)
    }
    g.rows.push(t)
    // Opening balances and reversed entries aren't money moving in the month
    if (t.reversed_by || t.type === 'opening_balance' || t.type === 'reversal') continue
    const n = Number(t.amount)
    n > 0 ? (g.in += n) : (g.out -= n)
  }
  return out
})
const emit = defineEmits<{ reverse: [transaction: Transaction] }>()

function detail(t: Transaction) {
  return [t.reference, t.narration].filter(Boolean).join(' · ')
}
</script>

<template>
  <div>
    <table class="hidden w-full text-sm md:table">
      <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
        <tr>
          <th class="px-4 py-3">Date</th>
          <th class="px-4 py-3">Entry</th>
          <th class="px-4 py-3">Details</th>
          <th class="px-4 py-3 text-right">Amount</th>
          <th class="px-4 py-3 text-right">Balance</th>
          <th v-if="canReverse" class="px-4 py-3"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody v-for="g in groups" :key="g.key" class="divide-y divide-gray-100">
        <tr v-if="byMonth" class="bg-gray-50/80">
          <th :colspan="canReverse ? 6 : 5" class="px-4 py-2 text-left">
            <span class="text-sm font-semibold text-ink">{{ g.label }}</span>
            <span v-if="g.in" class="ml-3 text-xs font-medium text-green-700">in {{ formatMoney(String(g.in), { sign: true }) }}</span>
            <span v-if="g.out" class="ml-2 text-xs font-medium text-red-700">out −{{ formatMoney(String(g.out)) }}</span>
          </th>
        </tr>
        <tr v-for="t in g.rows" :key="t.id" :class="{ 'text-gray-400': t.reversed_by }">
          <td class="whitespace-nowrap px-4 py-3">{{ formatDate(t.value_date) }}</td>
          <td class="px-4 py-3">
            <span class="font-medium" :class="t.reversed_by ? 'line-through' : 'text-gray-900'">{{ transactionTypeLabel[t.type] }}</span>
            <span v-if="showAccount" class="ml-1 text-gray-500">· {{ accountLabel[t.account] }}</span>
            <span v-if="t.reversed_by" class="ml-2 text-xs">reversed</span>
          </td>
          <td class="max-w-xs px-4 py-3 text-gray-600">
            <span class="line-clamp-2">{{ detail(t) }}</span>
            <span v-if="t.created_by_name" class="block text-xs text-gray-400">by {{ t.created_by_name }}</span>
          </td>
          <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums" :class="!t.reversed_by && (Number(t.amount) < 0 ? 'text-red-700' : 'text-green-700')">
            {{ formatMoney(t.amount, { sign: true }) }}
          </td>
          <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(t.balance_after) }}</td>
          <td v-if="canReverse" class="px-4 py-3 text-right">
            <button v-if="t.type !== 'reversal' && !t.reversed_by" type="button" class="btn-ghost px-2 py-1" @click="emit('reverse', t)">Reverse</button>
          </td>
        </tr>
      </tbody>
    </table>

    <ul class="divide-y divide-gray-100 md:hidden">
      <template v-for="g in groups" :key="g.key">
      <li v-if="byMonth" class="flex items-baseline justify-between gap-2 bg-gray-50 px-4 py-2">
        <span class="text-sm font-semibold">{{ g.label }}</span>
        <span class="text-xs">
          <span v-if="g.in" class="font-medium text-green-700">+{{ formatMoney(String(g.in)) }}</span>
          <span v-if="g.out" class="ml-2 font-medium text-red-700">−{{ formatMoney(String(g.out)) }}</span>
        </span>
      </li>
      <li v-for="t in g.rows" :key="t.id" class="p-4" :class="{ 'text-gray-400': t.reversed_by }">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium" :class="t.reversed_by ? 'line-through' : 'text-gray-900'">
              {{ transactionTypeLabel[t.type] }}<span v-if="showAccount" class="font-normal text-gray-500"> · {{ accountLabel[t.account] }}</span>
            </p>
            <p class="text-xs text-gray-500">{{ formatDate(t.value_date) }}<template v-if="t.reversed_by"> · reversed</template></p>
            <p v-if="detail(t)" class="mt-1 text-sm text-gray-600">{{ detail(t) }}</p>
          </div>
          <div class="shrink-0 text-right">
            <p class="font-medium tabular-nums" :class="!t.reversed_by && (Number(t.amount) < 0 ? 'text-red-700' : 'text-green-700')">
              {{ formatMoney(t.amount, { sign: true }) }}
            </p>
            <p class="text-xs tabular-nums text-gray-500">Bal. {{ formatMoney(t.balance_after) }}</p>
          </div>
        </div>
        <button v-if="canReverse && t.type !== 'reversal' && !t.reversed_by" type="button" class="btn-secondary mt-2 px-3 py-1.5" @click="emit('reverse', t)">Reverse</button>
      </li>
      </template>
    </ul>

    <EmptyState v-if="!transactions.length" title="No entries yet" text="Deposits, payroll deductions and withdrawals will show here as they are recorded." compact />
  </div>
</template>
