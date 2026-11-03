<script setup lang="ts">
import type { Account } from '@/types'

const model = defineModel<Account>({ required: true })
const tabs: { value: Account; label: string }[] = [
  { value: 'savings', label: 'Savings' },
  { value: 'shares', label: 'Shares' },
]
</script>

<template>
  <div class="inline-flex rounded-lg border border-gray-200 bg-surface p-1" role="tablist">
    <button
      v-for="tab in tabs"
      :key="tab.value"
      type="button"
      role="tab"
      :aria-selected="model === tab.value"
      class="rounded-md px-4 py-1.5 text-sm font-medium"
      :class="model === tab.value ? 'bg-brand text-white' : 'text-gray-600 hover:text-gray-900'"
      @click="model = tab.value"
    >
      {{ tab.label }}
    </button>
  </div>
</template>
