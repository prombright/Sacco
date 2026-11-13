<script setup lang="ts">
import { computed } from 'vue'
import type { Component } from 'vue'
import { useRoute } from 'vue-router'
import {
  BanknotesIcon,
  ChatBubbleLeftRightIcon,
  ClipboardDocumentCheckIcon,
  DocumentTextIcon,
  EllipsisHorizontalCircleIcon,
  HomeIcon,
  Squares2X2Icon,
  UsersIcon,
  WalletIcon,
} from '@heroicons/vue/24/outline'
import { useAuth } from '@/stores/auth'
import { useMessages } from '@/stores/messages'
import { sectionOf } from '@/sections'
import { t } from '@/i18n'

/**
 * Phones and small tablets: the main places one tap away at the bottom of the screen, like a
 * banking app. "More" opens the full menu.
 */
const emit = defineEmits<{ more: [] }>()
const auth = useAuth()
const messages = useMessages()
const route = useRoute()

interface Tab { label: string; to: string; icon: Component; active: boolean; badge?: number }
const name = computed(() => String(route.name ?? ''))
const tabs = computed<Tab[]>(() => {
  if (auth.isAdmin) {
    const section = sectionOf(name.value)?.key
    return [
      { label: 'Dashboard', to: '/office', icon: Squares2X2Icon, active: name.value === 'overview' },
      { label: 'Members', to: '/office/members', icon: UsersIcon, active: section === 'members' || name.value === 'member' },
      { label: 'Money', to: '/office/savings', icon: WalletIcon, active: section === 'money' },
      { label: 'Loans', to: '/office/loans', icon: ClipboardDocumentCheckIcon, active: section === 'loans' || name.value === 'loan' },
    ]
  }
  return [
    { label: t('Home'), to: '/', icon: HomeIcon, active: name.value === 'dashboard' },
    { label: t('Loans'), to: '/loans', icon: BanknotesIcon, active: ['loans', 'loan', 'loan-apply'].includes(name.value) },
    { label: t('Statement'), to: '/statement', icon: DocumentTextIcon, active: ['statement', 'receipts-mine'].includes(name.value) },
    { label: t('Messages'), to: '/messages', icon: ChatBubbleLeftRightIcon, active: name.value === 'messages', badge: messages.unread },
  ]
})
const moreActive = computed(() => !tabs.value.some((tab) => tab.active))
</script>

<template>
  <nav
    class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 pb-[env(safe-area-inset-bottom)] shadow-[0_-8px_24px_-16px_rgba(16,36,74,0.35)] backdrop-blur-md lg:hidden print:hidden"
    aria-label="Main"
  >
    <ul class="mx-auto grid max-w-lg grid-cols-5">
      <li v-for="tab in tabs" :key="tab.to">
        <RouterLink
          :to="tab.to"
          class="relative flex h-16 flex-col items-center justify-center gap-1 text-[11px] font-medium transition"
          :class="tab.active ? 'text-brand' : 'text-gray-500 hover:text-ink'"
          :aria-current="tab.active ? 'page' : undefined"
        >
          <span class="relative flex h-8 w-12 items-center justify-center rounded-full transition" :class="tab.active ? 'bg-navy-50' : ''">
            <component :is="tab.icon" class="h-6 w-6" aria-hidden="true" />
            <span v-if="tab.badge" class="absolute -right-0.5 -top-1 min-w-[1.15rem] rounded-full bg-saffron-400 px-1 text-center text-[10px] font-bold leading-[1.15rem] text-navy-950">{{ tab.badge > 9 ? '9+' : tab.badge }}</span>
          </span>
          {{ tab.label }}
        </RouterLink>
      </li>
      <li>
        <button
          type="button"
          class="flex h-16 w-full flex-col items-center justify-center gap-1 text-[11px] font-medium transition"
          :class="moreActive ? 'text-brand' : 'text-gray-500 hover:text-ink'"
          @click="emit('more')"
        >
          <span class="flex h-8 w-12 items-center justify-center rounded-full" :class="moreActive ? 'bg-navy-50' : ''"><EllipsisHorizontalCircleIcon class="h-6 w-6" aria-hidden="true" /></span>
          {{ t('More') }}
        </button>
      </li>
    </ul>
  </nav>
</template>
