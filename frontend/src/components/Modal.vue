<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'

defineProps<{ title: string }>()
const emit = defineEmits<{ close: [] }>()

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('close')
}
onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-40 flex items-end justify-center bg-black/40 sm:items-center sm:p-4" @click.self="emit('close')">
      <div class="max-h-[92vh] w-full overflow-y-auto rounded-t-2xl bg-surface sm:max-w-lg sm:rounded-2xl" role="dialog" aria-modal="true" :aria-label="title">
        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
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
