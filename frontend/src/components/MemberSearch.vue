<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import { api } from '@/api'
import type { User } from '@/types'

/** Find any member from anywhere (admins): type a name, account or employee number, press Enter */
const router = useRouter()
const q = ref('')
const results = ref<User[]>([])
const open = ref(false)
const active = ref(0)
let timer: ReturnType<typeof setTimeout> | undefined

watch(q, (value) => {
  clearTimeout(timer)
  if (value.trim().length < 2) {
    results.value = []
    return
  }
  timer = setTimeout(async () => {
    const res = await api.get<{ users: User[] }>('/users', { q: value.trim() })
    results.value = res.users.slice(0, 8)
    active.value = 0
    open.value = true
  }, 200)
})

function go(user: User | undefined) {
  if (!user) return
  q.value = ''
  results.value = []
  open.value = false
  router.push({ name: 'member', params: { id: user.id } })
}

function key(e: KeyboardEvent) {
  if (e.key === 'ArrowDown') active.value = Math.min(active.value + 1, results.value.length - 1)
  else if (e.key === 'ArrowUp') active.value = Math.max(active.value - 1, 0)
  else if (e.key === 'Enter') go(results.value[active.value])
  else if (e.key === 'Escape') open.value = false
  else return
  e.preventDefault()
}

function close() {
  setTimeout(() => (open.value = false), 150)
}
</script>

<template>
  <div class="relative w-full max-w-md">
    <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" aria-hidden="true" />
    <input
      v-model="q"
      type="search"
      class="input rounded-full border-transparent bg-gray-100 pl-9 focus:bg-white"
      placeholder="Find a member: name, account or employee no."
      aria-label="Find a member"
      role="combobox"
      :aria-expanded="open && results.length > 0"
      autocomplete="off"
      @keydown="key"
      @focus="open = results.length > 0"
      @blur="close"
    />
    <ul v-if="open && results.length" class="card absolute z-30 mt-2 w-full overflow-hidden p-1 shadow-lift" role="listbox">
      <li v-for="(u, i) in results" :key="u.id" role="option" :aria-selected="i === active">
        <button
          type="button"
          class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left"
          :class="i === active ? 'bg-navy-50' : 'hover:bg-gray-50'"
          @mousedown.prevent="go(u)"
          @mouseenter="active = i"
        >
          <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-navy-100 text-xs font-bold text-navy-800">
            {{ u.full_name.split(' ').map((p) => p[0]).slice(0, 2).join('') }}
          </span>
          <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-semibold">{{ u.full_name }}</span>
            <span class="block truncate text-xs text-gray-500">{{ u.account_no }}<template v-if="u.employee_no"> · {{ u.employee_no }}</template> · {{ u.role_label }}</span>
          </span>
        </button>
      </li>
    </ul>
    <p v-if="open && q.trim().length >= 2 && !results.length" class="card absolute z-30 mt-2 w-full px-4 py-3 text-sm text-gray-500 shadow-lift">No member matches.</p>
  </div>
</template>
