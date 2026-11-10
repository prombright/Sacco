<script setup lang="ts">
import { computed, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useMessages, type Message } from '@/stores/messages'
import { useRouter } from 'vue-router'
import {
  ArrowDownLeftIcon,
  ArrowTrendingUpIcon,
  ArrowUpRightIcon,
  ArrowUturnLeftIcon,
  BanknotesIcon,
  BellIcon,
  CalendarDaysIcon,
  CheckCircleIcon,
  ExclamationCircleIcon,
  ExclamationTriangleIcon,
  InboxArrowDownIcon,
  LockOpenIcon,
  ShieldCheckIcon,
  UserCircleIcon,
  UserGroupIcon,
} from '@heroicons/vue/24/outline'
import { api } from '@/api'
import { useAuth } from '@/stores/auth'

type Notification = Message

/**
 * Messages in the app. Members hear about their own money (deposits, withdrawals, repayments,
 * dividends, changes to their account); office accounts hear about every change a super admin
 * makes, for accountability.
 */
const auth = useAuth()
const router = useRouter()
const store = useMessages()
const { items, unread } = storeToRefs(store)
const load = () => store.load()
const open = ref(false)
const onlyUnread = ref(false)
const shown = computed(() => (onlyUnread.value ? items.value.filter((n) => !n.read_at) : items.value))


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
  if (kind === 'accountability') return { c: ShieldCheckIcon, style: 'bg-saffron-100 text-saffron-700' }
  if (kind === 'money_in' || kind === 'loan_repayment') return { c: ArrowDownLeftIcon, style: 'bg-emerald-50 text-emerald-700' }
  if (kind === 'money_out') return { c: ArrowUpRightIcon, style: 'bg-red-50 text-red-700' }
  if (kind === 'money_reversed') return { c: ArrowUturnLeftIcon, style: 'bg-amber-50 text-amber-800' }
  if (kind === 'dividend' || kind === 'profit_paid') return { c: BanknotesIcon, style: 'bg-saffron-100 text-saffron-700' }
  if (kind === 'loan_penalty') return { c: ExclamationCircleIcon, style: 'bg-red-50 text-red-700' }
  if (kind === 'account_update') return { c: UserCircleIcon, style: 'bg-navy-50 text-navy-800' }
  if (kind === 'loan_disbursed' || kind === 'withdrawal_paid') return { c: BanknotesIcon, style: 'bg-emerald-50 text-emerald-700' }
  if (kind.endsWith('approved')) return { c: CheckCircleIcon, style: 'bg-emerald-50 text-emerald-700' }
  if (kind.endsWith('rejected')) return { c: ExclamationCircleIcon, style: 'bg-red-50 text-red-700' }
  if (kind === 'loan_applied') return { c: InboxArrowDownIcon, style: 'bg-navy-50 text-navy-800' }
  if (kind === 'loan_due') return { c: CalendarDaysIcon, style: 'bg-navy-50 text-navy-800' }
  if (kind === 'loan_late') return { c: ExclamationCircleIcon, style: 'bg-red-50 text-red-700' }
  if (kind === 'slow_payer') return { c: ExclamationTriangleIcon, style: 'bg-amber-50 text-amber-800' }
  if (kind === 'credit_up') return { c: ArrowTrendingUpIcon, style: 'bg-emerald-50 text-emerald-700' }
  if (kind === 'guarantee_released') return { c: LockOpenIcon, style: 'bg-emerald-50 text-emerald-700' }
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
      <span v-if="unread" class="absolute right-1 top-1 inline-flex min-w-[1.15rem] items-center justify-center rounded-full bg-saffron-500 px-1 text-[11px] font-bold leading-[1.15rem] text-navy-950 ring-2 ring-paper">{{ unread > 99 ? '99+' : unread }}</span>
    </button>

    <div v-if="open" class="fixed inset-0 z-30" @click="open = false" />
    <div v-if="open" class="card absolute right-0 z-40 mt-2 w-[min(25rem,calc(100vw-2rem))] overflow-hidden shadow-lift" role="dialog" aria-label="Messages">
      <div class="flex items-center justify-between gap-2 border-b border-line px-4 py-3">
        <p class="font-semibold">Messages</p>
        <div class="flex items-center gap-1 rounded-lg bg-gray-100 p-0.5 text-xs font-semibold" role="radiogroup" aria-label="Show">
          <button type="button" role="radio" :aria-checked="!onlyUnread" class="rounded-md px-2.5 py-1" :class="!onlyUnread ? 'bg-surface text-ink shadow-sm' : 'text-gray-600'" @click="onlyUnread = false">All</button>
          <button type="button" role="radio" :aria-checked="onlyUnread" class="rounded-md px-2.5 py-1" :class="onlyUnread ? 'bg-surface text-ink shadow-sm' : 'text-gray-600'" @click="onlyUnread = true">Unread<template v-if="unread"> ({{ unread }})</template></button>
        </div>
      </div>
      <ul class="max-h-[28rem] divide-y divide-line overflow-y-auto">
        <li v-for="n in shown" :key="n.id">
          <button type="button" class="flex w-full gap-3 px-4 py-3 text-left transition hover:bg-gray-50" :class="{ 'bg-saffron-50/40': !n.read_at }" @click="go(n)">
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl" :class="icon(n.kind).style">
              <component :is="icon(n.kind).c" class="h-5 w-5" aria-hidden="true" />
            </span>
            <span class="min-w-0 flex-1">
              <span class="flex items-start justify-between gap-2">
                <span class="text-sm font-semibold">{{ n.title }}</span>
                <span v-if="!n.read_at" class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-saffron-500" aria-label="New" />
              </span>
              <span class="mt-0.5 block whitespace-pre-line text-sm text-gray-600">{{ n.body }}</span>
              <span class="mt-1 block text-xs text-gray-400">{{ ago(n.created_at) }}</span>
            </span>
          </button>
        </li>
        <li v-if="!shown.length" class="px-4 py-10 text-center text-sm text-gray-500">
          <template v-if="onlyUnread">You've read everything.</template>
          <template v-else-if="auth.isAdmin">No messages yet. When a super admin changes the rules or the books, you'll see it here.</template>
          <template v-else>No messages yet. Money in and out of your account, and news from the office, will show here.</template>
        </li>
      </ul>
      <div class="flex items-center justify-between border-t border-line px-4 py-2.5">
        <RouterLink :to="{ name: 'messages' }" class="text-xs font-semibold text-brand hover:underline" @click="open = false">See all messages</RouterLink>
        <button v-if="unread" type="button" class="text-xs font-semibold text-brand hover:underline" @click="readAll">Mark all as read</button>
      </div>
    </div>
  </div>
</template>
