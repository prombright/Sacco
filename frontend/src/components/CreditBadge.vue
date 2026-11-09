<script setup lang="ts">
import type { CreditStanding } from '@/types'
import { t } from '@/i18n'

/** Good, fair or slow payer, as a small coloured label */
defineProps<{ credit: CreditStanding }>()
const style = {
  new: 'bg-gray-100 text-gray-700',
  good: 'bg-emerald-50 text-emerald-700',
  fair: 'bg-amber-50 text-amber-800',
  poor: 'bg-red-50 text-red-700',
}
</script>

<template>
  <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="style[credit.grade]">{{ t(credit.label) }}</span>
</template>
