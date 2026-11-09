<script setup lang="ts">
import { LANGS, lang, setLang, t, type Lang } from '@/i18n'

/** English, Luganda or Kiswahili for the member side */
defineProps<{ dark?: boolean }>()
</script>

<template>
  <label class="inline-flex items-center gap-2 text-sm" :class="dark ? 'text-navy-100' : 'text-gray-600'">
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
