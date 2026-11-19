<script setup lang="ts">
/**
 * The dashboard banner's background: soft blue light on the left where the greeting sits, and on
 * the right blue rings, a slowly turning teal arc, a dot grid and a few shapes in the app's navy,
 * blue and teal. The same all day.
 */
const uid = Math.random().toString(36).slice(2, 8)
</script>

<template>
  <svg class="banner-art absolute inset-0 h-full w-full pointer-events-none select-none" viewBox="0 0 1600 200" preserveAspectRatio="xMaxYMid slice" aria-hidden="true">
    <defs>
      <linearGradient :id="`bg-${uid}`" x1="0" y1="0" x2="1" y2="0.4">
        <stop offset="0" class="ba-bg-1" />
        <stop offset="0.55" class="ba-bg-2" />
        <stop offset="1" class="ba-bg-3" />
      </linearGradient>
      <pattern :id="`dots-${uid}`" width="16" height="16" patternUnits="userSpaceOnUse">
        <circle cx="2" cy="2" r="1.6" class="ba-dot" />
      </pattern>
    </defs>

    <rect width="1600" height="200" :fill="`url(#bg-${uid})`" />
    <g class="ba-deco">
    <!-- a dot grid fading in behind the rings -->
    <rect x="1040" y="18" width="220" height="164" :fill="`url(#dots-${uid})`" opacity="0.55" />

    <!-- rings -->
    <g fill="none" class="ba-ring">
      <circle cx="1440" cy="100" r="60" stroke-width="2" opacity="0.28" />
      <circle cx="1440" cy="100" r="100" stroke-width="2" opacity="0.2" />
      <circle cx="1440" cy="100" r="140" stroke-width="2" opacity="0.14" />
      <circle cx="1440" cy="100" r="180" stroke-width="2" opacity="0.09" />
    </g>
    <!-- the teal arc, turning slowly -->
    <g class="ba-spin" style="transform-origin: 1440px 100px">
      <circle cx="1440" cy="100" r="100" fill="none" stroke="#14b8a6" stroke-width="8" stroke-linecap="round" stroke-dasharray="150 478" />
      <circle cx="1540" cy="100" r="7" fill="#14b8a6" />
    </g>

    <!-- shapes -->
    <circle cx="1440" cy="100" r="30" fill="#6a1ff5" opacity="0.9" />
    <circle cx="1440" cy="100" r="12" fill="#fff" opacity="0.9" />
    <circle cx="1290" cy="44" r="14" fill="#14b8a6" opacity="0.85" class="ba-float" />
    <circle cx="1565" cy="172" r="22" class="ba-navy" />
    <circle cx="1205" cy="156" r="7" class="ba-navy" opacity="0.7" />
    <rect x="1330" y="150" width="54" height="16" rx="8" fill="#6a1ff5" opacity="0.18" transform="rotate(-18 1357 158)" />
    <rect x="1505" y="22" width="40" height="12" rx="6" fill="#14b8a6" opacity="0.35" transform="rotate(24 1525 28)" />
    </g>
  </svg>
</template>

<style scoped>
.ba-bg-1 { stop-color: #ffffff; }
.ba-bg-2 { stop-color: #f1f5ff; }
.ba-bg-3 { stop-color: #dfe8ff; }
.ba-dot { fill: #6a1ff5; opacity: 0.35; }
.ba-ring { stroke: #6a1ff5; }
.ba-navy { fill: #16133a; }
.ba-spin { animation: ba-turn 40s linear infinite; }
.ba-float { animation: ba-float 6s ease-in-out infinite alternate; }
@keyframes ba-turn { to { transform: rotate(360deg); } }
@keyframes ba-float { to { transform: translateY(10px); } }
/* phones: the greeting fills the banner, so only the soft blue light stays behind it */
@media (max-width: 639px) {
  .ba-deco { display: none; }
}
@media (prefers-reduced-motion: reduce) {
  .ba-spin, .ba-float { animation: none; }
}
</style>

<style>
/* dark mode (unscoped, so .dark on <html> reaches the picture) */
.dark .banner-art .ba-bg-1 { stop-color: rgb(19 25 36); }
.dark .banner-art .ba-bg-2 { stop-color: #0f1a3d; }
.dark .banner-art .ba-bg-3 { stop-color: #112266; }
.dark .banner-art .ba-dot { fill: #9fb6ff; opacity: 0.25; }
.dark .banner-art .ba-ring { stroke: #9fb6ff; }
.dark .banner-art .ba-navy { fill: #9fb6ff; opacity: 0.5; }
</style>
