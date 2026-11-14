import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

/**
 * Light (the default) or dark, switched with one button. Remembered on this device only;
 * brand/theme-init.js applies it before the page draws, so there's no flash of the wrong theme.
 */
export type ThemeMode = 'light' | 'dark'

const KEY = 'sacco-theme'

function read(): ThemeMode {
  try {
    return localStorage.getItem(KEY) === 'dark' ? 'dark' : 'light'
  } catch {
    return 'light'
  }
}

export const useTheme = defineStore('theme', () => {
  const mode = ref<ThemeMode>(read())
  const dark = ref(false)

  function apply() {
    dark.value = mode.value === 'dark'
    document.documentElement.classList.toggle('dark', dark.value)
  }

  function set(m: ThemeMode) {
    mode.value = m
    try {
      localStorage.setItem(KEY, m)
    } catch {
      // Private windows may refuse storage; the choice still holds for this visit
    }
    apply()
  }

  function toggle() {
    set(mode.value === 'dark' ? 'light' : 'dark')
  }

  apply()

  /** Chart colours, one set for every chart: deeper in light mode, brighter on dark cards */
  const chart = computed(() => (dark.value ? { blue: '#6b78ec', amber: '#d69a1b' } : { blue: '#3a46c4', amber: '#c98a08' }))

  return { mode, dark, chart, set, toggle }
})
