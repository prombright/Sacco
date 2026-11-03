<script setup lang="ts">
import { ComputerDesktopIcon, MoonIcon, SunIcon } from '@heroicons/vue/24/outline'
import { useTheme, type ThemeMode } from '@/stores/theme'

/** Light / Auto / Dark. `compact` shows just the icon for the current mode, cycling on click. */
defineProps<{ compact?: boolean }>()
const theme = useTheme()

const modes: { mode: ThemeMode; label: string; icon: typeof SunIcon }[] = [
  { mode: 'light', label: 'Light', icon: SunIcon },
  { mode: 'auto', label: 'Auto', icon: ComputerDesktopIcon },
  { mode: 'dark', label: 'Dark', icon: MoonIcon },
]

function cycle() {
  const i = modes.findIndex((m) => m.mode === theme.mode)
  theme.set(modes[(i + 1) % modes.length].mode)
}
</script>

<template>
  <button
    v-if="compact"
    type="button"
    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-navy-100/80 hover:bg-white/10 hover:text-white"
    :title="`Theme: ${modes.find((m) => m.mode === theme.mode)?.label} (click to change)`"
    :aria-label="`Theme: ${theme.mode}. Change theme`"
    @click="cycle"
  >
    <component :is="modes.find((m) => m.mode === theme.mode)!.icon" class="h-5 w-5" />
  </button>
  <div v-else class="grid grid-cols-3 gap-1 rounded-xl bg-white/5 p-1" role="radiogroup" aria-label="Theme">
    <button
      v-for="m in modes"
      :key="m.mode"
      type="button"
      role="radio"
      :aria-checked="theme.mode === m.mode"
      class="flex items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-xs font-semibold transition"
      :class="theme.mode === m.mode ? 'bg-white text-navy-950 shadow-sm' : 'text-navy-100/70 hover:bg-white/10 hover:text-white'"
      @click="theme.set(m.mode)"
    >
      <component :is="m.icon" class="h-4 w-4" aria-hidden="true" />{{ m.label }}
    </button>
  </div>
</template>
