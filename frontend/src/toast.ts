import { ref } from 'vue'

/** Short "✓ Saved" notes after an action; ToastHost (in the layout) draws them */
export interface Toast { id: number; text: string; kind: 'success' | 'error' }

export const toasts = ref<Toast[]>([])
let next = 1

export function toast(text: string, kind: Toast['kind'] = 'success', ms = 4000) {
  const id = next++
  toasts.value = [...toasts.value.slice(-2), { id, text, kind }]
  setTimeout(() => dismiss(id), ms)
}

export function dismiss(id: number) {
  toasts.value = toasts.value.filter((t) => t.id !== id)
}
