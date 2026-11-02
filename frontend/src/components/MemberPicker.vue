<script setup lang="ts">
import { ref, watch } from 'vue'
import { api } from '@/api'
import type { PickedMember } from '@/types'

/** Search active members by name or account number and pick one */

const props = defineProps<{ exclude?: number[]; placeholder?: string }>()
const emit = defineEmits<{ pick: [member: PickedMember] }>()

const q = ref('')
const results = ref<PickedMember[]>([])
const open = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined

watch(q, (value) => {
  clearTimeout(timer)
  if (value.trim().length < 2) {
    results.value = []
    return
  }
  timer = setTimeout(async () => {
    const res = await api.get<{ members: PickedMember[] }>('/members/search', { q: value.trim() })
    results.value = res.members.filter((m) => !props.exclude?.includes(m.id))
    open.value = true
  }, 250)
})

// Let a click on a result land before the list closes
function close() {
  setTimeout(() => (open.value = false), 150)
}

function pick(m: PickedMember) {
  emit('pick', m)
  q.value = ''
  results.value = []
  open.value = false
}
</script>

<template>
  <div class="relative">
    <input
      v-model="q"
      type="search"
      class="input"
      :placeholder="placeholder ?? 'Search a member by name or account number'"
      autocomplete="off"
      role="combobox"
      :aria-expanded="open && results.length > 0"
      @focus="open = true"
      @blur="close"
    />
    <ul v-if="open && results.length" class="card absolute z-20 mt-1 max-h-64 w-full overflow-y-auto p-1 shadow-lg" role="listbox">
      <li v-for="m in results" :key="m.id">
        <button type="button" class="flex w-full items-center justify-between rounded-md px-3 py-2 text-left text-sm hover:bg-gray-100" @mousedown.prevent="pick(m)">
          <span>{{ m.full_name }}</span>
          <span class="font-mono text-xs text-gray-500">{{ m.account_no }}</span>
        </button>
      </li>
    </ul>
    <p v-if="open && q.trim().length >= 2 && !results.length" class="mt-1 text-xs text-gray-500">No members match.</p>
  </div>
</template>
