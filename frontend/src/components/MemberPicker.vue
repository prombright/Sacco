<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { ChevronDownIcon } from '@heroicons/vue/20/solid'
import { api } from '@/api'
import type { PickedMember } from '@/types'

/**
 * Pick an active member: type a name or account number, or tap the box (or the arrow) to scroll
 * the whole list, A to Z, and tap one. Typing narrows the list.
 */

const props = defineProps<{ exclude?: number[]; placeholder?: string }>()
const emit = defineEmits<{ pick: [member: PickedMember] }>()

const q = ref('')
const open = ref(false)
const all = ref<PickedMember[] | null>(null)
const loadingAll = ref(false)
/** Server matches for what's typed (also finds employee numbers, which the list doesn't show) */
const found = ref<PickedMember[]>([])
const active = ref(-1)
const input = ref<HTMLInputElement | null>(null)
const listId = `members-${Math.random().toString(36).slice(2, 8)}`
let timer: ReturnType<typeof setTimeout> | undefined

async function loadAll() {
  if (all.value || loadingAll.value) return
  loadingAll.value = true
  try {
    all.value = (await api.get<{ members: PickedMember[] }>('/members/search', { all: '1' })).members
  } catch {
    all.value = []
  } finally {
    loadingAll.value = false
  }
}

watch(q, (value) => {
  active.value = -1
  open.value = true
  clearTimeout(timer)
  found.value = []
  if (value.trim().length < 2) return
  timer = setTimeout(async () => {
    try {
      found.value = (await api.get<{ members: PickedMember[] }>('/members/search', { q: value.trim() })).members
    } catch {
      found.value = []
    }
  }, 250)
})

const results = computed(() => {
  const skip = new Set(props.exclude ?? [])
  const term = q.value.trim().toLowerCase()
  const list = (all.value ?? []).filter((m) => !term || m.full_name.toLowerCase().includes(term) || (m.account_no ?? '').toLowerCase().includes(term))
  const seen = new Set(list.map((m) => m.id))
  for (const m of found.value) if (!seen.has(m.id)) list.push(m)
  return list.filter((m) => !skip.has(m.id))
})

/** Opens above the box when there's no room below it (the box near the foot of a short page) */
const up = ref(false)
function place() {
  const r = input.value?.getBoundingClientRect()
  if (!r) return
  const below = window.innerHeight - r.bottom
  up.value = below < 260 && r.top > below
}

function show() {
  const wasOpen = open.value
  open.value = true
  loadAll()
  if (wasOpen) return
  // On a phone the list would open below the screen edge: bring the box up so names show,
  // then open upward if the page couldn't scroll far enough
  place()
  nextTick(() => {
    const r = input.value?.getBoundingClientRect()
    if (r && r.bottom > window.innerHeight - 260) {
      up.value = false
      input.value?.scrollIntoView({ block: 'start', behavior: 'smooth' })
      setTimeout(place, 450)
    }
  })
}

function toggle() {
  if (open.value) {
    open.value = false
  } else {
    show()
    input.value?.focus()
  }
}

// Let a tap on a name land before the list closes
function close() {
  setTimeout(() => (open.value = false), 150)
}

function pick(m: PickedMember) {
  emit('pick', m)
  q.value = ''
  found.value = []
  open.value = false
  active.value = -1
}

function move(step: number) {
  if (!open.value) show()
  const n = results.value.length
  if (!n) return
  active.value = (active.value + step + n) % n
  document.getElementById(`${listId}-${active.value}`)?.scrollIntoView({ block: 'nearest' })
}

function choose() {
  const m = results.value[active.value] ?? (results.value.length === 1 ? results.value[0] : undefined)
  if (m) pick(m)
}

const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase()
</script>

<template>
  <div class="relative">
    <div class="relative">
      <input
        ref="input"
        v-model="q"
        type="search"
        class="input scroll-mt-24 pr-11"
        :placeholder="placeholder ?? 'Search a member by name or account number'"
        autocomplete="off"
        role="combobox"
        :aria-expanded="open"
        :aria-controls="listId"
        :aria-activedescendant="active >= 0 ? `${listId}-${active}` : undefined"
        @focus="show"
        @click="show"
        @blur="close"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.enter.prevent="choose"
        @keydown.esc="open = false"
      />
      <button
        type="button"
        class="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-lg text-gray-500 hover:text-ink"
        :aria-label="open ? 'Hide the member list' : 'Show all members'"
        @mousedown.prevent
        @click="toggle"
      >
        <ChevronDownIcon class="h-5 w-5 transition" :class="open ? 'rotate-180' : ''" />
      </button>
    </div>
    <div v-if="open" class="card absolute z-20 w-full overflow-hidden shadow-lg" :class="up ? 'bottom-full mb-1' : 'mt-1'">
      <p class="border-b border-line px-3 py-2 text-xs text-gray-500">
        <template v-if="loadingAll && !results.length">Loading members…</template>
        <template v-else-if="results.length">{{ q.trim() ? `${results.length} match${results.length === 1 ? '' : 'es'}` : `All members (${results.length}), A to Z` }} · tap a name</template>
        <template v-else>No members match.</template>
      </p>
      <ul v-if="results.length" :id="listId" class="overflow-y-auto p-1" :class="up ? 'max-h-60' : 'max-h-72'" role="listbox">
        <li v-for="(m, i) in results" :id="`${listId}-${i}`" :key="m.id" role="option" :aria-selected="i === active">
          <button
            type="button"
            class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm"
            :class="i === active ? 'bg-gray-100' : 'hover:bg-gray-100'"
            @mousedown.prevent="pick(m)"
          >
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-navy-50 text-xs font-semibold text-navy-800" aria-hidden="true">{{ initials(m.full_name) }}</span>
            <span class="min-w-0 flex-1 truncate">{{ m.full_name }}</span>
            <span class="shrink-0 font-mono text-xs text-gray-500">{{ m.account_no }}</span>
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>
