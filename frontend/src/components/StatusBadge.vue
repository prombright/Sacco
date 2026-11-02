<script setup lang="ts">
defineProps<{ label: string; styleClass: string }>()
</script>

<template>
  <span class="inline-block whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium" :class="styleClass">{{ label }}</span>
</template>
