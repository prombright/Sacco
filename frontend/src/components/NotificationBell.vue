<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { BellIcon, CheckCircleIcon, ExclamationCircleIcon, InboxArrowDownIcon, UserGroupIcon } from '@heroicons/vue/24/outline'
import { api } from '@/api'

interface Notification {
  id: number
  title: string
  body: string
  link: string | null
  kind: string
  read_at: string | null
  created_at: string
}

/** Messages from the SACCO to this member: approvals, cheques ready, guarantor requests */
const router = useRouter()
const items = ref<Notification[]>([])
const unread = ref(0)
const open = ref(false)

async function load() {
  try {
    const res = await api.get<{ notifications: Notification[]; unread: number }>('/me/notifications')
    items.value = res.notifications
    unread.value = res.unread
  } catch {
    // The bell is extra; pages work without it
  }
}

let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  load()
  timer = setInterval(load, 60_000)
})
onBeforeUnmount(() => clearInterval(timer))

async function go(n: Notification) {
  open.value = false
  if (!n.read_at) {
    await api.post('/me/notifications/read', { ids: [n.id] })
    await load()
  }
  if (n.link) router.push(n.link)
}

async function readAll() {
  await api.post('/me/notifications/read')
  await load()
}

function icon(kind: string) {
  if (kind.endsWith('approved') || kind.endsWith('paid') || kind.endsWith('disbursed')) return { c: CheckCircleIcon, style: 'bg-emerald-50 text-emerald-700' }
  if (kind.endsWith('rejected')) return { c: ExclamationCircleIcon, style: 'bg-red-50 text-red-700' }
  if (kind.startsWith('guarantee') || kind.startsWith('guarantor')) return { c: UserGroupIcon, style: 'bg-navy-50 text-navy-800' }
  return { c: InboxArrowDownIcon, style: 'bg-gray-100 text-gray-700' }
}

function ago(at: string) {
  const mins = Math.round((Date.now() - new Date(at.replace(' ', 'T')).getTime()) / 60000)
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins} min ago`
  const h = Math.round(mins / 60)
  if (h < 24) return `${h} h ago`
  const d = Math.round(h / 24)
  return d === 1 ? 'yesterday' : `${d} days ago`
}
</script>

<template>
  <div class="relative">
    <button
      type="button"
      class="relative inline-flex h-10 w-10 items-center justify-center rounded-full text-gray-600 transition hover:bg-gray-200/60 hover:text-gray-900"
      :aria-label="unread ? `${unread} new messages` : 'Messages'"
      :aria-expanded="open"
      @click="open = !open; open && load()"
    >
      <BellIcon class="h-6 w-6" />
      <span v-if="unread" class="absolute right-1 top-1 inline-flex min-w-[1.15rem] items-center justify-center rounded-full bg-saffron-500 px-1 text-[11px] font-bold leading-[1.15rem] text-navy-950 ring-2 ring-paper">{{ unread }}</span>
    </button>

    <div v-if="open" class="fixed inset-0 z-30" @click="open = false" />
    <div v-if="open" class="card absolute right-0 z-40 mt-2 w-[min(24rem,calc(100vw-2rem))] overflow-hidden shadow-lift" role="dialog" aria-label="Messages">
      <div class="flex items-center justify-between border-b border-line px-4 py-3">
        <p class="font-semibold">Messages</p>
        <button v-if="unread" type="button" class="text-xs font-semibold text-brand hover:underline" @click="readAll">Mark all as read</button>
      </div>
      <ul class="max-h-[26rem] divide-y divide-line overflow-y-auto">
        <li v-for="n in items" :key="n.id">
          <button type="button" class="flex w-full gap-3 px-4 py-3 text-left transition hover:bg-gray-50" :class="{ 'bg-saffron-50/40': !n.read_at }" @click="go(n)">
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl" :class="icon(n.kind).style">
              <component :is="icon(n.kind).c" class="h-5 w-5" aria-hidden="true" />
            </span>
            <span class="min-w-0 flex-1">
              <span class="flex items-start justify-between gap-2">
                <span class="text-sm font-semibold">{{ n.title }}</span>
                <span v-if="!n.read_at" class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-saffron-500" aria-label="New" />
              </span>
              <span class="mt-0.5 block text-sm text-gray-600">{{ n.body }}</span>
              <span class="mt-1 block text-xs text-gray-400">{{ ago(n.created_at) }}</span>
            </span>
          </button>
        </li>
        <li v-if="!items.length" class="px-4 py-10 text-center text-sm text-gray-500">No messages yet. When the office approves something, you'll see it here.</li>
      </ul>
    </div>
  </div>
</template>
