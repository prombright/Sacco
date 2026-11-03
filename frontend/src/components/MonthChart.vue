<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * One series over months, as an area (a level over time, e.g. member funds) or bars (an amount per
 * month, e.g. repayments collected). Hovering anywhere over a month shows its value; a table with
 * the same numbers is there for screen readers.
 */
const props = defineProps<{
  points: { month: string; value: string | number }[]
  kind: 'area' | 'bar'
  /** One of the validated chart colours */
  color: string
  /** What the series is, for the tooltip and the table */
  label: string
  format: (v: number) => string
}>()

const box = ref<HTMLDivElement | null>(null)
const width = ref(600)
const height = 220
const pad = { top: 12, right: 8, bottom: 26, left: 52 }
let observer: ResizeObserver | undefined

onMounted(() => {
  observer = new ResizeObserver(([entry]) => (width.value = Math.max(280, entry.contentRect.width)))
  if (box.value) observer.observe(box.value)
})
onBeforeUnmount(() => observer?.disconnect())

const values = computed(() => props.points.map((p) => Number(p.value)))

/** A round top for the axis, and 3 gridlines under it */
const scale = computed(() => {
  const max = Math.max(...values.value, 0)
  if (max === 0) return { top: 1, ticks: [0] }
  const rough = max / 3
  const magnitude = 10 ** Math.floor(Math.log10(rough))
  const step = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((s) => s >= rough)!
  const top = step * Math.ceil(max / step)
  return { top, ticks: Array.from({ length: Math.round(top / step) + 1 }, (_, i) => i * step) }
})

const plotW = computed(() => width.value - pad.left - pad.right)
const plotH = height - pad.top - pad.bottom
const slot = computed(() => plotW.value / Math.max(1, props.points.length))
const x = (i: number) => pad.left + slot.value * i + slot.value / 2
const y = (v: number) => pad.top + plotH - (v / scale.value.top) * plotH

const line = computed(() => values.value.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' '))
const area = computed(() =>
  `${line.value} L${x(values.value.length - 1).toFixed(1)},${y(0)} L${x(0).toFixed(1)},${y(0)} Z`,
)

/** Bars: thin, with a 2px gap, rounded (4px) only at the data end */
const barW = computed(() => Math.min(28, Math.max(6, slot.value * 0.55)))
function barPath(i: number, v: number) {
  const h = Math.max(0, y(0) - y(v))
  if (h === 0) return ''
  const r = Math.min(4, h, barW.value / 2)
  const left = x(i) - barW.value / 2
  const top = y(v)
  const right = left + barW.value
  const bottom = y(0)
  return `M${left},${bottom} L${left},${top + r} Q${left},${top} ${left + r},${top} L${right - r},${top} Q${right},${top} ${right},${top + r} L${right},${bottom} Z`
}

const monthLabel = (m: string, long = false) =>
  new Date(m + '-01T00:00:00').toLocaleDateString('en-GB', long ? { month: 'long', year: 'numeric' } : { month: 'short' })

/** Fewer month labels when the chart is narrow */
const labelEvery = computed(() => (slot.value < 34 ? 2 : 1))

const compact = (v: number) =>
  v >= 1e9 ? `${+(v / 1e9).toFixed(1)}B` : v >= 1e6 ? `${+(v / 1e6).toFixed(1)}M` : v >= 1e3 ? `${+(v / 1e3).toFixed(0)}K` : String(v)

// An id the gradient can be found by (no spaces), unique per chart
const fillId = `fill-${props.label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${Math.random().toString(36).slice(2, 7)}`

const hover = ref<number | null>(null)
function move(e: PointerEvent) {
  const rect = (e.currentTarget as SVGElement).getBoundingClientRect()
  const i = Math.floor((e.clientX - rect.left - pad.left) / slot.value)
  hover.value = i >= 0 && i < props.points.length ? i : null
}
const tip = computed(() => {
  if (hover.value === null) return null
  const i = hover.value
  const left = x(i)
  return {
    left: Math.min(Math.max(left, 70), width.value - 70),
    top: y(values.value[i]),
    month: monthLabel(props.points[i].month, true),
    value: props.format(values.value[i]),
  }
})
</script>

<template>
  <div ref="box" class="relative w-full">
    <svg
      :width="width"
      :height="height"
      class="block touch-none select-none"
      role="img"
      :aria-label="`${label} by month`"
      @pointermove="move"
      @pointerleave="hover = null"
    >
      <!-- Gridlines and their values: recessive -->
      <g>
        <template v-for="t in scale.ticks" :key="t">
          <line :x1="pad.left" :x2="width - pad.right" :y1="y(t)" :y2="y(t)" :class="t === 0 ? 'stroke-gray-300' : 'stroke-gray-200'" stroke-width="1" />
          <text :x="pad.left - 8" :y="y(t) + 4" text-anchor="end" class="fill-gray-400 text-[11px] tabular-nums">{{ compact(t) }}</text>
        </template>
      </g>

      <!-- Hover column, under the marks -->
      <rect v-if="hover !== null" :x="x(hover) - slot / 2" :y="pad.top" :width="slot" :height="plotH" class="fill-navy-50" />

      <template v-if="kind === 'area'">
        <defs>
          <linearGradient :id="fillId" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" :stop-color="color" stop-opacity="0.18" />
            <stop offset="100%" :stop-color="color" stop-opacity="0" />
          </linearGradient>
        </defs>
        <path :d="area" :fill="`url(#${fillId})`" />
        <path :d="line" fill="none" :stroke="color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
        <circle v-if="hover !== null" :cx="x(hover)" :cy="y(values[hover])" r="5" :fill="color" stroke="white" stroke-width="2" />
      </template>
      <template v-else>
        <path v-for="(v, i) in values" :key="i" :d="barPath(i, v)" :fill="color" :opacity="hover === null || hover === i ? 1 : 0.55" />
      </template>

      <!-- Months -->
      <template v-for="(p, i) in points" :key="p.month">
        <text v-if="i % labelEvery === 0 || i === points.length - 1" :x="x(i)" :y="height - 8" text-anchor="middle" class="fill-gray-500 text-[11px]">{{ monthLabel(p.month) }}</text>
      </template>
    </svg>

    <div
      v-if="tip"
      class="pointer-events-none absolute z-10 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-lg bg-navy-950 px-3 py-2 text-xs text-white shadow-lift"
      :style="{ left: `${tip.left}px`, top: `${Math.max(tip.top - 10, 40)}px` }"
    >
      <p class="text-gray-300">{{ tip.month }}</p>
      <p class="mt-0.5 flex items-center gap-1.5 font-semibold tabular-nums">
        <span class="inline-block h-2 w-2 rounded-full" :style="{ background: color }" />{{ tip.value }}
      </p>
    </div>

    <table class="sr-only">
      <caption>{{ label }} by month</caption>
      <thead><tr><th>Month</th><th>{{ label }}</th></tr></thead>
      <tbody><tr v-for="p in points" :key="p.month"><td>{{ monthLabel(p.month, true) }}</td><td>{{ format(Number(p.value)) }}</td></tr></tbody>
    </table>
  </div>
</template>
