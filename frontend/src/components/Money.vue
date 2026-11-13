<script setup lang="ts">
import { computed } from 'vue'
import { formatMoney } from '@/money'

/**
 * An amount as the eye reads it: the currency small and quiet, the figure bold.
 * Pass `value` (an amount) or `text` (already written, e.g. by CountUp or a hidden "••••").
 */
const props = defineProps<{ value?: string | number | null; text?: string; sign?: boolean }>()
const written = computed(() => props.text ?? formatMoney(props.value === null || props.value === undefined ? '0' : String(props.value), { sign: props.sign }))
/** "UGX 1,200" -> ["UGX", "1,200"], keeping any sign in front of the figure */
const parts = computed(() => {
  const m = written.value.match(/^([+−-]?)([A-Z]{3}) (.+)$/)
  return m ? { unit: m[2], figure: m[1] + m[3] } : null
})
</script>

<template>
  <span class="whitespace-nowrap tabular-nums" :aria-label="written"><template v-if="parts"><span class="money-unit" aria-hidden="true">{{ parts.unit }}</span><span aria-hidden="true">{{ parts.figure }}</span></template><template v-else>{{ written }}</template></span>
</template>
