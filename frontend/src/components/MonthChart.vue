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
  /** A dot on every point of an area chart */
  dots?: boolean
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

/** A round top (and bottom, when a month went below zero) for the axis, about 3 gridlines between */
const scale = computed(() => {
  const max = Math.max(...values.value, 0)
  const min = Math.min(...values.value, 0)
  if (max === 0 && min === 0) return { top: 1, bottom: 0, ticks: [0] }
  const rough = (max - min) / 3
  const magnitude = 10 ** Math.floor(Math.log10(rough))
  const step = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((s) => s >= rough)!
  const top = step * Math.ceil(max / step)
  const bottom = step * Math.floor(min / step)
  return { top: top || step, bottom, ticks: Array.from({ length: Math.round(((top || step) - bottom) / step) + 1 }, (_, i) => bottom + i * step) }
})

const plotW = computed(() => width.value - pad.left - pad.right)
const plotH = height - pad.top - pad.bottom
const slot = computed(() => plotW.value / Math.max(1, props.points.length))
const x = (i: number) => pad.left + slot.value * i + slot.value / 2
const y = (v: number) => pad.top + plotH - ((v - scale.value.bottom) / (scale.value.top - scale.value.bottom)) * plotH

const line = computed(() => values.value.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' '))
const area = computed(() =>
  `${line.value} L${x(values.value.length - 1).toFixed(1)},${y(0)} L${x(0).toFixed(1)},${y(0)} Z`,
)

/** Bars: thin, with a 2px gap, rounded (4px) only at the data end */
const barW = computed(() => Math.min(28, Math.max(6, slot.value * 0.55)))
function barPath(i: number, v: number) {
  const h = Math.abs(y(0) - y(v))
  if (h === 0) return ''
  const r = Math.min(4, h, barW.value / 2)
  const left = x(i) - barW.value / 2
  const right = left + barW.value
  const base = y(0)
  const end = y(v)
  // Below zero (a deficit): the bar hangs from the zero line, rounded at its lower end
  if (v < 0) return `M${left},${base} L${left},${end - r} Q${left},${end} ${left + r},${end} L${right - r},${end} Q${right},${end} ${right},${end - r} L${right},${base} Z`
  return `M${left},${base} L${left},${end + r} Q${left},${end} ${left + r},${end} L${right - r},${end} Q${right},${end} ${right},${end + r} L${right},${base} Z`
}

const monthLabel = (m: string, long = false) =>
  new Date(m + '-01T00:00:00').toLocaleDateString('en-GB', long ? { month: 'long', year: 'numeric' } : { month: 'short' })

/** Fewer month labels when the chart is narrow */
const labelEvery = computed(() => (slot.value < 34 ? 2 : 1))

const compact = (n: number): string => {
  if (n < 0) return `-${compact(-n)}`
  const v = n
  return v >= 1e9 ? `${+(v / 1e9).toFixed(1)}B` : v >= 1e6 ? `${+(v / 1e6).toFixed(1)}M` : v >= 1e3 ? `${+(v / 1e3).toFixed(0)}K` : String(+v.toFixed(1))
}

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
  <!-- Under two months of records there is no line to draw yet: say so, with what there is -->
  <div v-if="points.length < 2" class="flex h-[220px] flex-col items-center justify-center rounded-xl border border-dashed border-gray-200 px-6 text-center">
    <template v-if="points.length === 1">
      <p class="text-xs text-gray-500">{{ label }} at the end of {{ monthLabel(points[0].month, true) }}</p>
      <p class="mt-1 text-2xl font-semibold tabular-nums">{{ format(Number(points[0].value)) }}</p>
      <p class="mt-2 max-w-xs text-sm text-gray-500">The records in this system start here. The chart fills in month by month.</p>
    </template>
    <p v-else class="max-w-xs text-sm text-gray-500">Nothing recorded yet. The chart fills in month by month.</p>
  </div>
  <div v-else ref="box" class="relative w-full">
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
        <path class="chart-area" :d="area" :fill="`url(#${fillId})`" />
        <path class="chart-line" pathLength="1" :d="line" fill="none" :stroke="color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
        <!-- a dot on every month (the last one larger), as in the dashboard design -->
        <template v-if="dots">
          <circle v-for="(v, i) in values" :key="'d' + i" class="chart-dot" :cx="x(i)" :cy="y(v)" :r="i === values.length - 1 ? 5.5 : 3.5" fill="rgb(var(--surface))" :stroke="color" :stroke-width="i === values.length - 1 ? 3 : 2" />
        </template>
        <circle v-if="hover !== null" :cx="x(hover)" :cy="y(values[hover])" r="5" :fill="color" stroke="rgb(var(--surface))" stroke-width="2" />
      </template>
      <template v-else>
        <path v-for="(v, i) in values" :key="i" class="chart-bar" :style="{ '--i': i }" :d="barPath(i, v)" :fill="color" :opacity="hover === null || hover === i ? 1 : 0.55" />
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
