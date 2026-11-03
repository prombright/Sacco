import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Light, dark, or "auto" (follow the device). Remembered on this device only; index.html applies
 * it before the page draws, so there's no flash of the wrong theme.
 */
export type ThemeMode = 'light' | 'dark' | 'auto'

const KEY = 'sacco-theme'
const media = window.matchMedia('(prefers-color-scheme: dark)')

function read(): ThemeMode {
  try {
    const v = localStorage.getItem(KEY)
    return v === 'light' || v === 'dark' ? v : 'auto'
  } catch {
    return 'auto'
  }
}

export const useTheme = defineStore('theme', () => {
  const mode = ref<ThemeMode>(read())
  const dark = ref(false)

  function apply() {
    dark.value = mode.value === 'dark' || (mode.value === 'auto' && media.matches)
    document.documentElement.classList.toggle('dark', dark.value)
  }

  function set(m: ThemeMode) {
    mode.value = m
    try {
      if (m === 'auto') localStorage.removeItem(KEY)
      else localStorage.setItem(KEY, m)
    } catch {
      // Private windows may refuse storage; the choice still holds for this visit
    }
    apply()
  }

  media.addEventListener('change', apply)
  apply()

  return { mode, dark, set }
})
