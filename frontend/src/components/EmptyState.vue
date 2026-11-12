<script setup lang="ts">
import type { Component } from 'vue'
import { InboxIcon } from '@heroicons/vue/24/outline'

/** What an empty list says: an icon, a short heading, what will appear here, and an optional next step */
withDefaults(defineProps<{ title: string; text?: string; icon?: Component; compact?: boolean }>(), { text: '', icon: () => InboxIcon, compact: false })
</script>

<template>
  <div class="flex flex-col items-center text-center" :class="compact ? 'px-4 py-6' : 'px-6 py-12'">
    <span class="flex items-center justify-center rounded-2xl bg-navy-50 text-navy-800" :class="compact ? 'h-10 w-10' : 'h-12 w-12'">
      <component :is="icon" :class="compact ? 'h-5 w-5' : 'h-6 w-6'" aria-hidden="true" />
    </span>
    <p class="mt-3 font-semibold text-ink">{{ title }}</p>
    <p v-if="text" class="mt-1 max-w-sm text-sm text-gray-600">{{ text }}</p>
    <div v-if="$slots.default" class="mt-4"><slot /></div>
  </div>
</template>
