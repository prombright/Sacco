<script setup lang="ts">
import { computed } from 'vue'

/** A small trend line for a tile: one series, the title beside it names it */
const props = withDefaults(defineProps<{ values: number[]; color: string; label: string; width?: number; height?: number }>(), { width: 120, height: 40 })

const points = computed(() => {
  const v = props.values.length ? props.values : [0, 0]
  const max = Math.max(...v)
  const min = Math.min(...v)
  const span = max - min || 1
  return v.map((n, i) => [(i / Math.max(1, v.length - 1)) * (props.width - 6) + 3, props.height - 4 - ((n - min) / span) * (props.height - 10)] as const)
})
const line = computed(() => points.value.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`).join(' '))
const last = computed(() => points.value[points.value.length - 1])
</script>

<template>
  <svg :width="width" :height="height" :viewBox="`0 0 ${width} ${height}`" role="img" :aria-label="`${label}: trend`" class="block max-w-full overflow-visible">
    <path class="chart-line" pathLength="1" :d="line" fill="none" :stroke="color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
    <circle class="chart-area" :cx="last[0]" :cy="last[1]" r="3.5" :fill="color" />
  </svg>
</template>
