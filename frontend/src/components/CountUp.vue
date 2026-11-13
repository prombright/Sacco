<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { formatMoney } from '@/money'
import Money from '@/components/Money.vue'

/** A figure that counts up to its value when it first shows, and glides when the value changes */
const props = withDefaults(
  defineProps<{
    value: string | number
    /** How to write the number; money by default */
    format?: (n: number) => string
    duration?: number
    delay?: number
  }>(),
  { format: undefined, duration: 1100, delay: 0 },
)

const money = (n: number) => formatMoney(String(n))
const write = (n: number) => (props.format ?? money)(n)
const shown = ref(0)
let frame = 0
let timer: ReturnType<typeof setTimeout> | undefined

function run(from: number, to: number) {
  cancelAnimationFrame(frame)
  clearTimeout(timer)
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || from === to) {
    shown.value = to
    return
  }
  timer = setTimeout(() => {
    const start = performance.now()
    const step = (now: number) => {
      const t = Math.min(1, (now - start) / props.duration)
      const eased = 1 - (1 - t) ** 4
      // Whole numbers on the way; the exact value at the end
      shown.value = t < 1 ? Math.round(from + (to - from) * eased) : to
      if (t < 1) frame = requestAnimationFrame(step)
    }
    frame = requestAnimationFrame(step)
  }, props.delay)
}

onMounted(() => run(0, Number(props.value) || 0))
watch(() => props.value, (v) => run(shown.value, Number(v) || 0))
onBeforeUnmount(() => {
  cancelAnimationFrame(frame)
  clearTimeout(timer)
})
</script>

<template>
  <!-- Money is drawn with its currency small; other figures (percentages, counts) as they are -->
  <Money :text="write(shown)" :aria-label="write(Number(value) || 0)" />
</template>
