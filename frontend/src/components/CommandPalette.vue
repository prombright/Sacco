<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import { MagnifyingGlassIcon, UserIcon, BanknotesIcon } from '@heroicons/vue/24/outline'
import { api } from '@/api'
import { useAuth } from '@/stores/auth'
import { dashboardIcon, sections } from '@/sections'
import { loanStatus } from '@/loans'
import { formatMoney } from '@/money'
import type { Loan, User } from '@/types'

/**
 * Quick search for the office (Ctrl + K, or "/"): type a name, account number, loan number or a
 * page, then Enter to go straight there. Arrow keys move, Escape closes.
 */
const open = defineModel<boolean>({ required: true })
const router = useRouter()
const auth = useAuth()

interface Hit {
  key: string
  group: 'Pages' | 'Members' | 'Loans'
  title: string
  detail: string
  icon: Component
  to: RouteLocationRaw
  badge?: { label: string; style: string }
}

const q = ref('')
const input = ref<HTMLInputElement | null>(null)
const members = ref<User[]>([])
const loans = ref<Loan[]>([])
const searching = ref(false)
const active = ref(0)

/** Every office page this account may open */
const pages = computed<Hit[]>(() => {
  const out: Hit[] = [{ key: 'p-overview', group: 'Pages', title: 'Dashboard', detail: 'The office at a glance', icon: dashboardIcon, to: { name: 'overview' } }]
  for (const s of sections) {
    out.push({ key: `p-${s.hub}`, group: 'Pages', title: s.label, detail: s.intro, icon: s.icon, to: { name: s.hub } })
    for (const i of s.items) {
      if ((i.superOnly && !auth.isSuperAdmin) || (i.needs && !auth.can(i.needs))) continue
      out.push({ key: `p-${i.name}`, group: 'Pages', title: i.label, detail: `${s.label} · ${i.description}`, icon: i.icon, to: { name: i.name } })
    }
  }
  return out
})

const words = computed(() => q.value.toLowerCase().split(/\s+/).filter(Boolean))
const matchingPages = computed(() => {
  if (!words.value.length) return pages.value.slice(0, 6)
  const scored = pages.value
    .map((p) => {
      const title = p.title.toLowerCase()
      const all = `${title} ${p.detail.toLowerCase()}`
      if (!words.value.every((w) => all.includes(w))) return null
      // Titles starting with what was typed come first
      return { p, score: title.startsWith(words.value[0]) ? 0 : title.includes(words.value[0]) ? 1 : 2 }
    })
    .filter((x): x is { p: Hit; score: number } => x !== null)
  return scored.sort((a, b) => a.score - b.score).slice(0, 5).map((x) => x.p)
})

const hits = computed<Hit[]>(() => [
  ...members.value.map((u) => ({
    key: `m-${u.id}`,
    group: 'Members' as const,
    title: u.full_name,
    detail: [u.account_no, u.employee_no, u.role_label].filter(Boolean).join(' · '),
    icon: UserIcon,
    to: { name: 'member', params: { id: u.id } },
  })),
  ...loans.value.map((l) => ({
    key: `l-${l.id}`,
    group: 'Loans' as const,
    title: `${l.loan_no} · ${l.borrower_name}`,
    detail: formatMoney(l.amount),
    icon: BanknotesIcon,
    to: { name: 'loan', params: { id: l.id } },
    badge: loanStatus[l.status] ? { label: loanStatus[l.status].label, style: loanStatus[l.status].style } : undefined,
  })),
  ...matchingPages.value,
])

const groups = computed(() => {
  const order: Hit['group'][] = words.value.length ? ['Members', 'Loans', 'Pages'] : ['Pages']
  return order.map((g) => ({ g, items: hits.value.filter((h) => h.group === g) })).filter((x) => x.items.length)
})
/** The same order as shown, for the arrow keys */
const flat = computed(() => groups.value.flatMap((x) => x.items))

let timer: ReturnType<typeof setTimeout> | undefined
let asked = 0
watch(q, (value) => {
  active.value = 0
  clearTimeout(timer)
  const term = value.trim()
  if (term.length < 2) {
    members.value = []
    loans.value = []
    return
  }
  timer = setTimeout(async () => {
    const mine = ++asked
    searching.value = true
    try {
      const [m, l] = await Promise.all([
        api.get<{ users: User[] }>('/users', { q: term }),
        api.get<{ loans: Loan[] }>('/loans', { q: term, limit: 5 }).catch(() => ({ loans: [] as Loan[] })),
      ])
      if (mine !== asked) return
      members.value = m.users.slice(0, 6)
      loans.value = l.loans
    } finally {
      if (mine === asked) searching.value = false
    }
  }, 180)
})

function go(h: Hit | undefined) {
  if (!h) return
  open.value = false
  router.push(h.to)
}

function key(e: KeyboardEvent) {
  if (e.key === 'ArrowDown') active.value = Math.min(active.value + 1, flat.value.length - 1)
  else if (e.key === 'ArrowUp') active.value = Math.max(active.value - 1, 0)
  else if (e.key === 'Enter') go(flat.value[active.value])
  else return
  e.preventDefault()
  nextTick(() => document.getElementById(`cmd-${flat.value[active.value]?.key}`)?.scrollIntoView({ block: 'nearest' }))
}

watch(open, (v) => {
  if (v) {
    q.value = ''
    active.value = 0
    nextTick(() => input.value?.focus())
  }
})

/** Ctrl + K (or Cmd + K) anywhere; "/" when not typing in a box; Escape closes */
function shortcut(e: KeyboardEvent) {
  const typing = e.target instanceof HTMLElement && (e.target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName))
  if ((e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey)) {
    e.preventDefault()
    open.value = !open.value
  } else if (e.key === '/' && !typing && !open.value) {
    e.preventDefault()
    open.value = true
  } else if (e.key === 'Escape' && open.value) {
    open.value = false
  }
}
onMounted(() => document.addEventListener('keydown', shortcut))
onBeforeUnmount(() => document.removeEventListener('keydown', shortcut))
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0"
      leave-active-class="transition duration-100 ease-in"
      leave-to-class="opacity-0"
    >
      <div v-if="open" class="fixed inset-0 z-50 flex items-start justify-center bg-navy-950/40 p-3 pt-[8vh] backdrop-blur-sm sm:p-6 sm:pt-[12vh]" @click.self="open = false">
        <div class="w-full max-w-xl overflow-hidden rounded-2xl border border-line bg-surface shadow-lift" role="dialog" aria-modal="true" aria-label="Quick search">
          <div class="relative border-b border-line">
            <MagnifyingGlassIcon class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" aria-hidden="true" />
            <input
              ref="input"
              v-model="q"
              type="search"
              class="h-14 w-full bg-transparent pl-12 pr-16 text-base text-ink placeholder:text-gray-400 focus:outline-none"
              placeholder="Find a member, loan or page…"
              aria-label="Find a member, loan or page"
              role="combobox"
              aria-controls="cmd-results"
              :aria-expanded="flat.length > 0"
              :aria-activedescendant="flat[active] ? `cmd-${flat[active].key}` : undefined"
              autocomplete="off"
              spellcheck="false"
              @keydown="key"
            />
            <span v-if="searching" class="absolute right-14 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin rounded-full border-2 border-gray-200 border-t-brand" aria-hidden="true" />
            <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 rounded-md border border-line px-1.5 py-0.5 text-[11px] font-semibold text-gray-500 hover:bg-gray-50" @click="open = false"><span class="sm:hidden">Close</span><span class="max-sm:hidden">Esc</span></button>
          </div>

          <div id="cmd-results" class="max-h-[60vh] overflow-y-auto overscroll-contain p-2" role="listbox">
            <section v-for="grp in groups" :key="grp.g" class="mb-1 last:mb-0">
              <p class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ words.length ? grp.g : 'Go to' }}</p>
              <button
                v-for="h in grp.items"
                :id="`cmd-${h.key}`"
                :key="h.key"
                type="button"
                role="option"
                :aria-selected="flat[active]?.key === h.key"
                class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left"
                :class="flat[active]?.key === h.key ? 'bg-navy-50' : 'hover:bg-gray-50'"
                @click="go(h)"
                @mousemove="active = flat.indexOf(h)"
              >
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="flat[active]?.key === h.key ? 'bg-brand text-white' : 'bg-gray-100 text-gray-500'">
                  <component :is="h.icon" class="h-4 w-4" aria-hidden="true" />
                </span>
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-sm font-semibold text-ink">{{ h.title }}</span>
                  <span class="block truncate text-xs text-gray-500">{{ h.detail }}</span>
                </span>
                <span v-if="h.badge" class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium" :class="h.badge.style">{{ h.badge.label }}</span>
                <span v-if="flat[active]?.key === h.key" class="hidden shrink-0 text-xs text-gray-400 sm:inline" aria-hidden="true">Enter ↵</span>
              </button>
            </section>
            <p v-if="!flat.length && !searching" class="px-4 py-8 text-center text-sm text-gray-500">Nothing matches “{{ q }}”.</p>
          </div>

          <div class="hidden items-center gap-4 border-t border-line bg-gray-50 px-4 py-2 text-[11px] text-gray-500 sm:flex">
            <span><kbd class="rounded border border-line bg-surface px-1 font-semibold text-gray-600">↑</kbd> <kbd class="rounded border border-line bg-surface px-1 font-semibold text-gray-600">↓</kbd> move</span>
            <span><kbd class="rounded border border-line bg-surface px-1 font-semibold text-gray-600">Enter</kbd> open</span>
            <span><kbd class="rounded border border-line bg-surface px-1 font-semibold text-gray-600">Esc</kbd> close</span>
            <span class="ml-auto"><kbd class="rounded border border-line bg-surface px-1 font-semibold text-gray-600">Ctrl</kbd> <kbd class="rounded border border-line bg-surface px-1 font-semibold text-gray-600">K</kbd> from any page</span>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

