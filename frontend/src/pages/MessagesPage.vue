<script setup lang="ts">
import SkeletonRows from '@/components/SkeletonRows.vue'
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

const filters = computed(() =>
  ([
    { key: 'all', label: t('All') },
    ...(auth.isAdmin ? [{ key: 'office', label: 'Office' }] : [{ key: 'money', label: t('Money') }, { key: 'loans', label: t('Loans') }, { key: 'guarantees', label: t('Guarantees') }]),
  ] as { key: typeof filter.value; label: string }[]).map((f) => ({
    ...f,
    count: f.key === 'all' ? list.value.length : list.value.filter((m) => messageGroup(m.kind) === f.key).length,
  })),
)
const unread = computed(() => list.value.filter((m) => !m.read_at).length)

/**
 * Wide screens: the conversation scrolls in its own box beside the filters. Phones and tablets:
 * it scrolls with the page, so nothing hides behind the bottom tabs.
 */
const wide = window.matchMedia('(min-width: 1024px)')
const scrollHeight = () => (wide.matches ? thread.value?.scrollHeight ?? 0 : document.documentElement.scrollHeight)
const scrollTop = () => (wide.matches ? thread.value?.scrollTop ?? 0 : window.scrollY)
function scrollTo(top: number) {
  if (wide.matches) {
    if (thread.value) thread.value.scrollTop = top
  } else {
    window.scrollTo({ top })
  }
}
async function pick(key: typeof filter.value) {
  filter.value = key
  await nextTick()
  scrollTo(scrollHeight())
}

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
  const keep = scrollHeight() - scrollTop()
  try {
    list.value = [...list.value, ...(await fetchPage(list.value[list.value.length - 1]?.id))]
    await nextTick()
    // Stay where they were reading
    scrollTo(scrollHeight() - keep)
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
  // Open at the newest, at the bottom, as on a phone
  scrollTo(scrollHeight())
  // Opening the conversation reads them, as on a phone
  if (list.value.some((m) => !m.read_at)) {
    await store.markRead()
  }
})
</script>

<template>
  <div class="lg:grid lg:grid-cols-[17rem_minmax(0,1fr)] lg:gap-6 xl:grid-cols-[19rem_minmax(0,1fr)] 2xl:grid-cols-[21rem_minmax(0,1fr)]">
    <!-- Wide screens: who the messages are from, and the filters with how many in each -->
    <aside class="hidden lg:block">
      <div class="card sticky top-20 flex h-[calc(100dvh-8rem)] min-h-[28rem] flex-col p-4">
        <div class="flex items-center gap-3">
          <SaccoMark :sacco="auth.sacco" />
          <div class="min-w-0">
            <p class="truncate font-semibold">{{ auth.sacco?.name ?? 'SACCO' }}</p>
            <p class="text-xs text-gray-500">{{ t('Messages about your account') }}</p>
          </div>
        </div>
        <nav class="mt-4 space-y-0.5" aria-label="Show">
          <button
            v-for="f in filters"
            :key="f.key"
            type="button"
            class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm font-medium transition"
            :class="filter === f.key ? 'bg-navy-50 text-navy-800' : 'text-gray-600 hover:bg-gray-50 hover:text-ink'"
            :aria-pressed="filter === f.key"
            @click="pick(f.key)"
          >
            {{ f.label }}
            <span class="rounded-full px-2 py-0.5 text-xs tabular-nums" :class="filter === f.key ? 'bg-brand text-white' : 'bg-gray-100 text-gray-500'">{{ f.count }}</span>
          </button>
        </nav>
        <p class="mt-auto border-t border-line pt-3 text-xs text-gray-500">
          <template v-if="unread">{{ unread }} new · </template>{{ auth.isAdmin ? 'Changes made in the office, as they happen.' : 'Each one also goes to your email when you have one.' }}
        </p>
      </div>
    </aside>

    <div class="card overflow-clip lg:flex lg:h-[calc(100dvh-8rem)] lg:min-h-[28rem] lg:flex-col">
      <!-- The top of the conversation -->
      <div class="flex items-center gap-3 border-b border-line px-4 py-3">
        <SaccoMark :sacco="auth.sacco" size="sm" class="lg:hidden" />
        <div class="min-w-0 flex-1">
          <h1 class="truncate font-semibold">
            <span class="lg:hidden">{{ auth.sacco?.name ?? 'SACCO' }}</span>
            <span class="hidden lg:inline">{{ filters.find((f) => f.key === filter)?.label }} {{ filter === 'all' ? t('Messages').toLowerCase() : '' }}</span>
          </h1>
          <p class="text-xs text-gray-500">
            <span class="lg:hidden">{{ t('Messages about your account') }}</span>
            <span class="hidden lg:inline">Oldest at the top, newest at the bottom</span>
          </p>
        </div>
        <span v-if="unread" class="shrink-0 rounded-full bg-[#efe8fe] px-2.5 py-0.5 text-xs font-semibold text-[#5b19f3] dark:bg-white/10 dark:text-[#c4a6ff]">{{ unread }} new</span>
      </div>

      <!-- Phones and tablets: the filters stay under the top bar while scrolling -->
      <div class="sticky top-16 z-20 flex gap-2 overflow-x-auto border-b border-line bg-surface/95 px-4 py-2.5 backdrop-blur lg:hidden" role="radiogroup" aria-label="Show">
        <button
          v-for="f in filters"
          :key="f.key"
          type="button"
          role="radio"
          :aria-checked="filter === f.key"
          class="tap inline-flex shrink-0 items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-semibold transition"
          :class="filter === f.key ? 'bg-brand text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
          @click="pick(f.key)"
        >
          {{ f.label }}<span class="text-xs tabular-nums opacity-75">{{ f.count }}</span>
        </button>
      </div>

      <div ref="thread" class="bg-gray-50 px-3 py-4 sm:px-6 lg:flex-1 lg:overflow-y-auto lg:overscroll-contain lg:px-8">
        <div v-if="more" class="mb-4 text-center">
          <button type="button" class="rounded-full bg-surface px-4 py-2 text-sm font-semibold text-brand shadow-sm hover:underline" :disabled="loadingOlder" @click="older">
            {{ loadingOlder ? t('Loading…') : t('Earlier messages') }}
          </button>
        </div>
        <SkeletonRows v-if="loading" :rows="5" avatar :amount="false" />
        <p v-else-if="!days.length" class="py-16 text-center text-sm text-gray-500">
          {{ auth.isAdmin ? 'No messages yet. When a super admin changes the rules or the books, it shows here.' : t('No messages yet. Money in and out of your account, loan news and reminders will show here.') }}
        </p>
        <section v-for="d in days" :key="d.day" class="mb-2">
          <p class="my-3 text-center lg:sticky lg:top-0 lg:z-10">
            <span class="rounded-full bg-gray-200/80 px-3 py-1 text-[11px] font-semibold text-gray-600 backdrop-blur">{{ d.day }}</span>
          </p>
          <div v-for="m in d.items" :key="m.id" class="mb-2.5 flex">
            <div
              class="w-full min-w-0 rounded-2xl rounded-tl-md border border-line bg-surface px-4 py-3 shadow-sm sm:px-5"
              :class="{ 'cursor-pointer transition hover:border-brand/40 hover:shadow-card': m.link, 'ring-2 ring-[#8a3ffb]/40': !m.read_at }"
              :role="m.link ? 'link' : undefined"
              :tabindex="m.link ? 0 : undefined"
              @click="open(m)"
              @keydown.enter="open(m)"
            >
              <p class="text-[0.95rem] font-semibold leading-snug text-ink">{{ m.title }}</p>
              <p class="mt-1 whitespace-pre-line break-words text-sm leading-relaxed text-gray-700">{{ m.body }}</p>
              <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-400">
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
