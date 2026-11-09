<script setup lang="ts">
import { computed } from 'vue'
import { photoSrc } from '@/photos'

/** Someone's photo, or their initials when they haven't added one */
const props = withDefaults(defineProps<{ name: string; photoUrl?: string | null; size?: string }>(), { photoUrl: null, size: 'h-9 w-9 text-sm' })
const initials = computed(() => props.name.split(' ').filter(Boolean).map((p) => p[0]).slice(0, 2).join('').toUpperCase())
const src = computed(() => photoSrc(props.photoUrl))
</script>

<template>
  <img v-if="src" :src="src" alt="" class="shrink-0 rounded-full object-cover" :class="size" />
  <span v-else class="inline-flex shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-saffron-300 to-saffron-500 font-bold text-navy-950" :class="size" aria-hidden="true">{{ initials }}</span>
</template>
