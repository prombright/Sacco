<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { QuestionMarkCircleIcon } from '@heroicons/vue/24/outline'
import { answer, question } from '@/confirm'

/** The app's "are you sure?" box */
const yes = ref<HTMLButtonElement | null>(null)
watch(question, async (q) => {
  if (q) {
    await nextTick()
    yes.value?.focus()
  }
})
function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape' && question.value) answer(false)
}
onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <div v-if="question" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4" @click.self="answer(false)">
      <div class="w-full rounded-t-2xl bg-surface p-6 shadow-lift sm:max-w-md sm:rounded-2xl" role="alertdialog" aria-modal="true" aria-labelledby="confirm-text">
        <div class="flex gap-4">
          <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-saffron-100 text-saffron-700"><QuestionMarkCircleIcon class="h-6 w-6" aria-hidden="true" /></span>
          <p id="confirm-text" class="self-center text-ink">{{ question.text }}</p>
        </div>
        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="answer(false)">Cancel</button>
          <button ref="yes" type="button" class="btn-primary" @click="answer(true)">Yes, go ahead</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
