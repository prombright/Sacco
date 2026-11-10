<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowRightIcon, EnvelopeIcon } from '@heroicons/vue/24/outline'
import { api } from '@/api'
import { t } from '@/i18n'
import { useAuth } from '@/stores/auth'
import { messageGroup, useMessages, type Message } from '@/stores/messages'
import SaccoMark from '@/components/SaccoMark.vue'

/**
 * Messages from the SACCO, shown like a text-message conversation on a phone: oldest at the top,
 * newest at the bottom. Every one also went to the member's email when they have one.
 */
const auth = useAuth()
const store = useMessages()
const router = useRouter()
const list = ref<Message[]>([])
const more = ref(false)
const loading = ref(true)
const loadingOlder = ref(false)
const filter = ref<'all' | 'money' | 'loans' | 'guarantees' | 'office'>('all')
const thread = ref<HTMLElement | null>(null)

const filters = computed(() => [
  { key: 'all', label: t('All') },
  ...(auth.isAdmin ? [{ key: 'office', label: 'Office' }] : [{ key: 'money', label: t('Money') }, { key: 'loans', label: t('Loans') }, { key: 'guarantees', label: t('Guarantees') }]),
] as { key: typeof filter.value; label: string }[])

// Oldest first, in days
const days = computed(() => {
  const shown = list.value.filter((m) => filter.value === 'all' || messageGroup(m.kind) === filter.value).slice().reverse()
  const out: { day: string; items: Message[] }[] = []
  for (const m of shown) {
    const day = dayLabel(m.created_at)
    if (out[out.length - 1]?.day !== day) out.push({ day, items: [] })
    out[out.length - 1].items.push(m)
  }
  return out
})

function dayLabel(at: string) {
  const d = new Date(at.replace(' ', 'T'))
  const today = new Date()
  const yesterday = new Date(Date.now() - 864e5)
  if (d.toDateString() === today.toDateString()) return t('Today')
  if (d.toDateString() === yesterday.toDateString()) return t('Yesterday')
  return d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', ...(d.getFullYear() !== today.getFullYear() ? { year: 'numeric' } : {}) })
}
const time = (at: string) => new Date(at.replace(' ', 'T')).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })

async function fetchPage(before?: number) {
  const res = await api.get<{ notifications: Message[]; more: boolean }>(`/me/notifications?limit=40${before ? `&before=${before}` : ''}`)
  more.value = res.more
  return res.notifications
}

async function older() {
  loadingOlder.value = true
  const keep = thread.value ? thread.value.scrollHeight - thread.value.scrollTop : 0
  try {
    list.value = [...list.value, ...(await fetchPage(list.value[list.value.length - 1]?.id))]
    await nextTick()
    // Stay where they were reading
    if (thread.value) thread.value.scrollTop = thread.value.scrollHeight - keep
  } finally {
    loadingOlder.value = false
  }
}

function open(m: Message) {
  if (m.link) router.push(m.link)
}

onMounted(async () => {
  try {
    list.value = await fetchPage()
  } finally {
    loading.value = false
  }
  await nextTick()
  if (thread.value) thread.value.scrollTop = thread.value.scrollHeight
  // Opening the conversation reads them, as on a phone
  if (list.value.some((m) => !m.read_at)) {
    await store.markRead()
  }
})
</script>

<template>
  <div class="mx-auto max-w-2xl">
    <div class="card overflow-hidden">
      <!-- Like the top of a conversation on a phone -->
      <div class="flex items-center gap-3 border-b border-line px-4 py-3">
        <SaccoMark :sacco="auth.sacco" size="sm" />
        <div class="min-w-0 flex-1">
          <h1 class="truncate font-semibold">{{ auth.sacco?.name ?? 'SACCO' }}</h1>
          <p class="text-xs text-gray-500">{{ t('Messages about your account') }}</p>
        </div>
      </div>
      <div class="flex gap-1.5 overflow-x-auto border-b border-line px-4 py-2" role="radiogroup" aria-label="Show">
        <button
          v-for="f in filters"
          :key="f.key"
          type="button"
          role="radio"
          :aria-checked="filter === f.key"
          class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold transition"
          :class="filter === f.key ? 'bg-brand text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
          @click="filter = f.key"
        >{{ f.label }}</button>
      </div>

      <div ref="thread" class="h-[calc(100vh-15rem)] min-h-[22rem] overflow-y-auto bg-gray-50 px-3 py-4 sm:px-5">
        <div v-if="more" class="mb-4 text-center">
          <button type="button" class="rounded-full bg-surface px-4 py-1.5 text-xs font-semibold text-brand shadow-sm hover:underline" :disabled="loadingOlder" @click="older">
            {{ loadingOlder ? t('Loading…') : t('Earlier messages') }}
          </button>
        </div>
        <p v-if="loading" class="py-16 text-center text-sm text-gray-500">{{ t('Loading…') }}</p>
        <p v-else-if="!days.length" class="py-16 text-center text-sm text-gray-500">
          {{ auth.isAdmin ? 'No messages yet. When a super admin changes the rules or the books, it shows here.' : t('No messages yet. Money in and out of your account, loan news and reminders will show here.') }}
        </p>
        <section v-for="d in days" :key="d.day" class="mb-2">
          <p class="sticky top-0 z-10 my-3 text-center">
            <span class="rounded-full bg-gray-200/80 px-3 py-1 text-[11px] font-semibold text-gray-600 backdrop-blur">{{ d.day }}</span>
          </p>
          <div v-for="m in d.items" :key="m.id" class="mb-2.5 flex">
            <div
              class="max-w-[88%] rounded-2xl rounded-tl-md border border-line bg-surface px-4 py-2.5 shadow-sm sm:max-w-[80%]"
              :class="{ 'cursor-pointer hover:border-brand/40': m.link, 'ring-2 ring-saffron-300/60': !m.read_at }"
              @click="open(m)"
            >
              <p class="text-sm font-semibold text-ink">{{ m.title }}</p>
              <p class="mt-0.5 whitespace-pre-line text-sm text-gray-700">{{ m.body }}</p>
              <p class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-gray-400">
                <span>{{ time(m.created_at) }}</span>
                <span v-if="m.email_status === 'sent'" class="inline-flex items-center gap-1"><EnvelopeIcon class="h-3.5 w-3.5" aria-hidden="true" />{{ t('Also sent to your email') }}</span>
                <span v-if="m.link" class="ml-auto inline-flex items-center gap-0.5 font-semibold text-brand">{{ t('Open') }}<ArrowRightIcon class="h-3 w-3" aria-hidden="true" /></span>
              </p>
            </div>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>
