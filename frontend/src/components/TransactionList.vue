<script setup lang="ts">
import type { Transaction } from '@/types'
import { accountLabel, formatDate, formatMoney, transactionTypeLabel } from '@/money'

/** A ledger: a table on wide screens, a list on phones. Admins get a Reverse button per entry. */
defineProps<{ transactions: Transaction[]; showAccount?: boolean; canReverse?: boolean }>()
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
      <tbody class="divide-y divide-gray-100">
        <tr v-for="t in transactions" :key="t.id" :class="{ 'text-gray-400': t.reversed_by }">
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
      <li v-for="t in transactions" :key="t.id" class="p-4" :class="{ 'text-gray-400': t.reversed_by }">
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
    </ul>

    <p v-if="!transactions.length" class="px-4 py-10 text-center text-sm text-gray-500">No entries yet.</p>
  </div>
</template>
