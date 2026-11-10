<script setup lang="ts">
import { LanguageIcon } from '@heroicons/vue/24/outline'
import { LANGS, lang, setLang, t, type Lang } from '@/i18n'

/** English, Luganda or Kiswahili for the member side. `compact`: an icon and the choice, no label. */
defineProps<{ dark?: boolean; compact?: boolean }>()
</script>

<template>
  <label v-if="compact" class="relative inline-flex items-center text-sm text-gray-700">
    <span class="sr-only">{{ t('Language') }}</span>
    <LanguageIcon class="pointer-events-none absolute left-2.5 h-4 w-4 text-gray-500" aria-hidden="true" />
    <select
      :value="lang"
      class="cursor-pointer appearance-none rounded-lg bg-transparent py-1.5 pl-8 pr-7 text-sm font-medium text-ink hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/40"
      @change="setLang(($event.target as HTMLSelectElement).value as Lang)"
    >
      <option v-for="l in LANGS" :key="l.code" :value="l.code">{{ l.name }}</option>
    </select>
    <svg class="pointer-events-none absolute right-2 h-4 w-4 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
      <path fill-rule="evenodd" d="M5.2 7.2a.75.75 0 011.06 0L10 10.94l3.74-3.74a.75.75 0 111.06 1.06l-4.27 4.27a.75.75 0 01-1.06 0L5.2 8.26a.75.75 0 010-1.06z" clip-rule="evenodd" />
    </svg>
  </label>
  <label v-else class="inline-flex items-center gap-2 text-sm" :class="dark ? 'text-navy-100' : 'text-gray-600'">
    <span>{{ t('Language') }}</span>
    <select
      :value="lang"
      class="rounded-lg border px-2 py-1 text-sm"
      :class="dark ? 'border-white/15 bg-navy-900 text-white' : 'border-line bg-surface text-ink'"
      @change="setLang(($event.target as HTMLSelectElement).value as Lang)"
    >
      <option v-for="l in LANGS" :key="l.code" :value="l.code">{{ l.name }}</option>
    </select>
  </label>
</template>
