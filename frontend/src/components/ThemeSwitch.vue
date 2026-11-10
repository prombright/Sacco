<script setup lang="ts">
import { MoonIcon, SunIcon } from '@heroicons/vue/24/outline'
import { useTheme } from '@/stores/theme'

/**
 * One button between light and dark. `label` adds the words beside the icon; `tone` is the
 * background it sits on: the dark menu (default) or a light page.
 */
withDefaults(defineProps<{ label?: boolean; tone?: 'dark' | 'light' }>(), { tone: 'dark' })
const theme = useTheme()
</script>

<template>
  <button
    type="button"
    class="inline-flex items-center gap-2 rounded-lg text-sm font-medium transition"
    :class="[
      label ? 'w-full px-3 py-2' : 'h-9 w-9 justify-center',
      tone === 'dark' ? 'text-navy-100/80 hover:bg-white/10 hover:text-white' : 'text-gray-600 hover:bg-gray-100 hover:text-ink',
    ]"
    role="switch"
    :aria-checked="theme.dark"
    :aria-label="theme.dark ? 'Dark mode is on. Switch to light mode' : 'Switch to dark mode'"
    :title="theme.dark ? 'Switch to light mode' : 'Switch to dark mode'"
    @click="theme.toggle()"
  >
    <component :is="theme.dark ? SunIcon : MoonIcon" class="h-5 w-5 shrink-0" aria-hidden="true" />
    <span v-if="label">{{ theme.dark ? 'Light mode' : 'Dark mode' }}</span>
  </button>
</template>
