<script setup lang="ts">
import { ref } from 'vue'

defineProps<{ id: string; autocomplete: string; invalid?: boolean }>()
const model = defineModel<string>({ required: true })
const shown = ref(false)
</script>

<template>
  <div class="relative">
    <input
      :id="id"
      v-model="model"
      :type="shown ? 'text' : 'password'"
      :autocomplete="autocomplete"
      :aria-invalid="invalid || undefined"
      class="input pr-16"
      :class="{ 'border-red-400': invalid }"
    />
    <button
      type="button"
      class="absolute inset-y-0 right-0 px-3 text-sm font-medium text-gray-500 hover:text-gray-800"
      :aria-label="shown ? 'Hide password' : 'Show password'"
      @click="shown = !shown"
    >
      {{ shown ? 'Hide' : 'Show' }}
    </button>
  </div>
</template>
