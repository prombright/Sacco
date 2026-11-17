<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'

defineProps<{ title: string }>()
const emit = defineEmits<{ close: [] }>()

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('close')
}
// The page behind stays still while the window is open (no scrolling it by accident on a phone)
let before = ''
onMounted(() => {
  document.addEventListener('keydown', onKey)
  before = document.body.style.overflow
  document.body.style.overflow = 'hidden'
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey)
  document.body.style.overflow = before
})
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-40 flex items-end justify-center bg-black/40 sm:items-center sm:p-4" @click.self="emit('close')">
      <div class="modal-panel max-h-[92dvh] w-full overflow-y-auto overscroll-contain rounded-t-2xl bg-surface pb-[env(safe-area-inset-bottom)] sm:max-w-lg sm:rounded-2xl sm:pb-0" role="dialog" aria-modal="true" :aria-label="title">
        <!-- The title stays in view while the window scrolls; on phones a handle shows it's a sheet -->
        <div class="sticky top-0 z-10 flex items-center justify-between border-b border-gray-100 bg-surface px-5 py-4 max-sm:rounded-t-2xl max-sm:pt-5">
          <span class="absolute left-1/2 top-2 h-1 w-10 -translate-x-1/2 rounded-full bg-gray-200 sm:hidden" aria-hidden="true" />
          <h2 class="font-semibold">{{ title }}</h2>
          <button type="button" class="rounded-lg p-1 text-gray-500 hover:bg-gray-100" aria-label="Close" @click="emit('close')">
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
              <path d="M6.3 5.3a1 1 0 00-1.4 1.4L8.6 10l-3.7 3.3a1 1 0 101.4 1.4L10 11.4l3.7 3.3a1 1 0 001.4-1.4L11.4 10l3.7-3.3a1 1 0 00-1.4-1.4L10 8.6 6.3 5.3z" />
            </svg>
          </button>
        </div>
        <div class="p-5">
          <slot />
        </div>
      </div>
    </div>
  </Teleport>
</template>
