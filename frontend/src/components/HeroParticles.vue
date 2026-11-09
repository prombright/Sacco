<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'

/** Gold sparkles drifting up behind a hero panel; rests when off screen or the tab is hidden */
const canvas = ref<HTMLCanvasElement | null>(null)
let frame = 0
let visible = true
let ro: ResizeObserver | undefined
let io: IntersectionObserver | undefined

interface Coin { x: number; y: number; r: number; speed: number; sway: number; phase: number; flip: number }
let coins: Coin[] = []
let w = 0
let h = 0

function size() {
  const c = canvas.value!
  const box = c.parentElement!.getBoundingClientRect()
  const dpr = Math.min(2, window.devicePixelRatio || 1)
  w = box.width
  h = box.height
  c.width = w * dpr
  c.height = h * dpr
  c.getContext('2d')!.setTransform(dpr, 0, 0, dpr, 0, 0)
  const count = Math.min(18, Math.round(w / 56))
  coins = Array.from({ length: count }, () => ({
    x: Math.random() * w,
    y: Math.random() * h,
    r: 1 + Math.random() * 1.6,
    speed: 0.18 + Math.random() * 0.45,
    sway: 8 + Math.random() * 18,
    phase: Math.random() * 6.3,
    flip: 0.6 + Math.random() * 1.4,
  }))
}

function draw(t: number) {
  frame = requestAnimationFrame(draw)
  if (!visible || document.hidden) return
  const ctx = canvas.value!.getContext('2d')!
  ctx.clearRect(0, 0, w, h)
  const s = t / 1000
  for (const c of coins) {
    c.y -= c.speed
    if (c.y < -12) {
      c.y = h + 12
      c.x = Math.random() * w
    }
    const x = c.x + Math.sin(s * 0.7 + c.phase) * c.sway
    // Small and bright, twinkling in turn
    ctx.globalAlpha = 0.3 + Math.abs(Math.sin(s * c.flip + c.phase)) * 0.7
    ctx.fillStyle = '#f7cd63'
    ctx.beginPath()
    ctx.arc(x, c.y, c.r, 0, Math.PI * 2)
    ctx.fill()
  }
}

onMounted(() => {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
  size()
  ro = new ResizeObserver(size)
  ro.observe(canvas.value!.parentElement!)
  io = new IntersectionObserver(([e]) => (visible = e.isIntersecting))
  io.observe(canvas.value!)
  frame = requestAnimationFrame(draw)
})
onBeforeUnmount(() => {
  cancelAnimationFrame(frame)
  ro?.disconnect()
  io?.disconnect()
})
</script>

<template>
  <canvas ref="canvas" class="pointer-events-none absolute inset-0 h-full w-full print:hidden" aria-hidden="true" />
</template>
