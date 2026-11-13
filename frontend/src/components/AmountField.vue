<script setup lang="ts">
import AmountWords from '@/components/AmountWords.vue'

/**
 * The amount box on every money form: a large figure with "UGX" in front, the amount in words
 * underneath, quick amounts to tap, and an optional line about what happens after (the slot).
 */
const props = withDefaults(defineProps<{ id: string; label?: string; quick?: number[]; max?: number | null; maxLabel?: string }>(), {
  label: 'Amount',
  quick: () => [50000, 100000, 200000, 500000],
  max: null,
  maxLabel: 'All',
})
const model = defineModel<string>({ required: true })
const short = (n: number) => (n >= 1_000_000 ? `${n / 1_000_000}M` : `${n / 1000}k`)
const pick = (n: number) => (model.value = String(n))
</script>

<template>
  <div>
    <label :for="id" class="label">{{ label }}</label>
    <div class="relative">
      <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-sm font-semibold text-gray-500">UGX</span>
      <input
        :id="id"
        v-model="model"
        class="input h-14 pl-14 text-2xl font-semibold tabular-nums tracking-tight"
        inputmode="numeric"
        autocomplete="off"
        placeholder="0"
      />
    </div>
    <AmountWords :value="model" />
    <div v-if="quick.length || props.max" class="mt-2 flex flex-wrap gap-1.5" aria-label="Quick amounts">
      <button
        v-for="n in quick"
        :key="n"
        type="button"
        class="tap rounded-full border border-line bg-surface px-3 py-1 text-xs font-semibold text-gray-700 transition hover:border-navy-100 hover:bg-navy-50 hover:text-navy-800"
        :class="{ '!border-brand !bg-navy-50 !text-navy-800': Number(model) === n }"
        @click="pick(n)"
      >{{ short(n) }}</button>
      <button
        v-if="props.max && props.max > 0"
        type="button"
        class="tap rounded-full border border-line bg-surface px-3 py-1 text-xs font-semibold text-gray-700 transition hover:border-navy-100 hover:bg-navy-50 hover:text-navy-800"
        :class="{ '!border-brand !bg-navy-50 !text-navy-800': Number(model) === props.max }"
        @click="pick(Math.floor(props.max))"
      >{{ maxLabel }}</button>
    </div>
    <slot />
  </div>
</template>
