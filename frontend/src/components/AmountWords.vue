<script setup lang="ts">
import { computed } from 'vue'

/**
 * Under an amount box: the amount in words ("One million two hundred thousand shillings"),
 * so a missing or extra zero is noticed before anything is saved.
 */
const props = defineProps<{ value: string | number | null | undefined }>()

const ONES = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen']
const TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety']
const SCALES = ['', 'thousand', 'million', 'billion', 'trillion']

/** 0-999 in words */
function hundreds(n: number): string {
  const parts: string[] = []
  if (n >= 100) {
    parts.push(`${ONES[Math.floor(n / 100)]} hundred`)
    n %= 100
    if (n) parts.push('and')
  }
  if (n >= 20) parts.push(TENS[Math.floor(n / 10)] + (n % 10 ? `-${ONES[n % 10]}` : ''))
  else if (n) parts.push(ONES[n])
  return parts.join(' ')
}

function words(n: number): string {
  if (n === 0) return 'zero'
  const groups: string[] = []
  let scale = 0
  while (n > 0 && scale < SCALES.length) {
    const chunk = n % 1000
    if (chunk) groups.unshift(hundreds(chunk) + (SCALES[scale] ? ` ${SCALES[scale]}` : ''))
    n = Math.floor(n / 1000)
    scale++
  }
  return groups.join(', ')
}

const text = computed(() => {
  const n = Number(String(props.value ?? '').replace(/[,\s]/g, ''))
  if (!Number.isFinite(n) || n <= 0 || n >= 1e15) return ''
  const whole = Math.floor(n)
  const said = words(whole)
  return said.charAt(0).toUpperCase() + said.slice(1) + ' shilling' + (whole === 1 ? '' : 's')
})
</script>

<template>
  <p v-if="text" class="mt-1 text-xs text-gray-500" aria-live="polite">{{ text }}</p>
</template>
