<script setup lang="ts">
import { computed } from 'vue'

/** Parts of a whole. Hovering a slice or its legend row (via `active`) picks it out. */
const props = defineProps<{
  slices: { key: string; label: string; value: number; color: string }[]
  active: string | null
  center: string
  caption: string
}>()
const emit = defineEmits<{ hover: [key: string | null] }>()

const R = 42
const C = 2 * Math.PI * R
const total = computed(() => props.slices.reduce((s, x) => s + x.value, 0))
// A 2-unit gap keeps neighbouring colours apart
const arcs = computed(() => {
  let at = 0
  return props.slices.filter((s) => s.value > 0).map((s) => {
    const len = (s.value / (total.value || 1)) * C
    const arc = { ...s, dash: `${Math.max(len - 2, 0.5)} ${C}`, offset: -at }
    at += len
    return arc
  })
})
const shown = computed(() => props.slices.find((s) => s.key === props.active) ?? null)
</script>

<template>
  <div class="relative mx-auto h-40 w-40">
    <svg viewBox="0 0 100 100" class="h-full w-full -rotate-90" role="img" :aria-label="slices.map((s) => `${s.label}: ${s.value}`).join(', ')">
      <circle cx="50" cy="50" :r="R" fill="none" class="stroke-gray-100" stroke-width="11" />
      <circle
        v-for="(a, i) in arcs"
        :key="a.key"
        cx="50"
        cy="50"
        :r="R"
        fill="none"
        pathLength="263.89"
        class="donut-slice cursor-pointer transition-opacity"
        :class="active && active !== a.key ? 'opacity-30' : ''"
        :stroke="a.color"
        :stroke-width="active === a.key ? 14 : 11"
        :stroke-dasharray="a.dash"
        :stroke-dashoffset="a.offset"
        :style="{ '--i': i }"
        @pointerenter="emit('hover', a.key)"
        @pointerleave="emit('hover', null)"
      />
    </svg>
    <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
      <p class="text-3xl font-bold tabular-nums">{{ shown ? shown.value : center }}</p>
      <p class="max-w-[5.5rem] text-[11px] leading-tight text-gray-500">{{ shown ? shown.label : caption }}</p>
    </div>
  </div>
</template>
