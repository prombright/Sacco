<script setup lang="ts">
import { CheckCircleIcon, ExclamationTriangleIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { dismiss, toasts } from '@/toast'

/** Phones: just above the bottom bar. Wide screens: top right, under the top bar. */
</script>

<template>
  <div class="pointer-events-none fixed inset-x-4 bottom-24 z-[60] flex flex-col items-center gap-2 lg:inset-x-auto lg:bottom-auto lg:right-6 lg:top-20 lg:items-end print:hidden" aria-live="polite">
    <TransitionGroup
      enter-active-class="transition duration-300 ease-out"
      enter-from-class="translate-y-3 opacity-0 lg:translate-x-6 lg:translate-y-0"
      leave-active-class="transition duration-200 ease-in"
      leave-to-class="opacity-0"
    >
      <div
        v-for="t in toasts"
        :key="t.id"
        class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-2xl bg-ink px-4 py-3 text-sm text-white shadow-lift dark:bg-surface dark:text-ink"
        role="status"
      >
        <CheckCircleIcon v-if="t.kind === 'success'" class="h-5 w-5 shrink-0 text-emerald-400" aria-hidden="true" />
        <ExclamationTriangleIcon v-else class="h-5 w-5 shrink-0 text-saffron-400" aria-hidden="true" />
        <p class="flex-1">{{ t.text }}</p>
        <button type="button" class="-m-1 rounded p-1 text-white/60 hover:text-white dark:text-gray-500" aria-label="Dismiss" @click="dismiss(t.id)"><XMarkIcon class="h-4 w-4" /></button>
      </div>
    </TransitionGroup>
  </div>
</template>
