<script setup lang="ts">
defineProps<{ page: number; perPage: number; total: number }>()
const emit = defineEmits<{ go: [page: number] }>()
</script>

<template>
  <div v-if="total > perPage" class="mt-4 flex items-center justify-between text-sm text-gray-600">
    <span>{{ (page - 1) * perPage + 1 }}–{{ Math.min(page * perPage, total) }} of {{ total }}</span>
    <div class="flex gap-2">
      <button type="button" class="btn-secondary" :disabled="page === 1" @click="emit('go', page - 1)">Previous</button>
      <button type="button" class="btn-secondary" :disabled="page * perPage >= total" @click="emit('go', page + 1)">Next</button>
    </div>
  </div>
</template>
