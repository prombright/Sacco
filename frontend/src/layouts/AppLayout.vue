<script setup lang="ts">
import SmsToast from '@/components/SmsToast.vue'
import { useMessages } from '@/stores/messages'
import UserAvatar from '@/components/UserAvatar.vue'
import { t } from '@/i18n'
import { canInstall, install } from '@/pwa'
import LanguagePicker from '@/components/LanguagePicker.vue'
import { computed, ref, watch, type Component } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowDownTrayIcon,
  ArrowLeftStartOnRectangleIcon,
  ChatBubbleLeftRightIcon,
  BanknotesIcon,
  Bars3Icon,
  ChevronDoubleLeftIcon,
  ChevronDownIcon,
  ChevronUpDownIcon,
  DocumentTextIcon,
  HomeIcon,
  KeyIcon,
  ReceiptPercentIcon,
  UserCircleIcon,
  WalletIcon,
  XMarkIcon,
  QuestionMarkCircleIcon,
} from '@heroicons/vue/24/outline'
import { useAuth } from '@/stores/auth'
import { useOffice } from '@/stores/office'
import SaccoMark from '@/components/SaccoMark.vue'
import MemberSearch from '@/components/MemberSearch.vue'
import ThemeSwitch from '@/components/ThemeSwitch.vue'
import NotificationBell from '@/components/NotificationBell.vue'
import { dashboardIcon, sectionOf, sections, type Section, type SectionItem } from '@/sections'

interface NavLink {
  name: string
  label: string
  icon: Component
  /** Other pages that belong under this link */
  also?: string[]
  /** How many things are waiting here */
  badge?: number
}
interface NavGroup {
  title: string
  links: NavLink[]
}

const auth = useAuth()
const messages = useMessages()
messages.start()
const office = useOffice()
const route = useRoute()
const router = useRouter()

/** Phones: the menu slides in. Wide screens: it can shrink to an icon rail (remembered). */
const drawer = ref(false)
const accountMenu = ref(false)
const collapsed = ref((() => {
  try {
    return localStorage.getItem('sacco-sidebar') === 'collapsed'
  } catch {
    return false
  }
})())
function toggleCollapsed() {
  collapsed.value = !collapsed.value
  try {
    localStorage.setItem('sacco-sidebar', collapsed.value ? 'collapsed' : 'open')
  } catch {
    // Not remembered in private windows; still works for this visit
  }
}

/**
 * Members see their own money. Office accounts (admins, the super admin) run the SACCO and have
 * no savings or loans of their own - staff who are also members sign in with their member account.
 */
const groups = computed<NavGroup[]>(() => {
  if (!auth.isAdmin) {
    return [{
      title: t('My account'),
      links: [
        { name: 'dashboard', label: t('Dashboard'), icon: HomeIcon },
        { name: 'messages', label: t('Messages'), icon: ChatBubbleLeftRightIcon, badge: messages.unread },
        { name: 'statement', label: t('Statement'), icon: DocumentTextIcon, also: ['statement-print'] },
        { name: 'receipts-mine', label: t('Receipts'), icon: ReceiptPercentIcon },
        { name: 'loans', label: t('Loans'), icon: BanknotesIcon, also: ['loan-apply', 'loan'] },
        { name: 'withdrawals', label: t('Withdraw savings'), icon: WalletIcon },
        { name: 'profile', label: t('Profile & next of kin'), icon: UserCircleIcon },
        { name: 'help', label: t('Help'), icon: QuestionMarkCircleIcon },
      ],
    }]
  }
  // Office accounts: see the sections below
  return []
})

/**
 * The office menu: Dashboard, then a few sections. A section opens on its own dashboard of
 * modules; in the menu only the section you are in shows its modules, so the menu stays short.
 */
const currentSection = computed(() => sectionOf(String(route.name))?.key ?? null)
const open = ref<Set<string>>(new Set())
watch(currentSection, (k) => (open.value = new Set(k ? [k] : [])), { immediate: true })
function toggleSection(key: string) {
  const next = new Set(open.value)
  next.has(key) ? next.delete(key) : next.add(key)
  open.value = next
}
const visibleItems = (s: Section) => s.items.filter((i) => (!i.superOnly || auth.isSuperAdmin) && (!i.needs || auth.can(i.needs)))
const itemBadge = (i: SectionItem) => (office.waiting && i.badge ? i.badge(office.waiting) : 0)
const sectionBadge = (s: Section) => visibleItems(s).reduce((n, i) => n + itemBadge(i), 0)
const isItemCurrent = (i: SectionItem) => route.name === i.name || !!i.also?.includes(String(route.name))

function isCurrent(link: NavLink) {
  const name = String(route.name)
  return name === link.name || !!link.also?.includes(name)
}

const title = computed(() => route.meta.title ?? '')
const wide = computed(() => !collapsed.value || drawer.value)

watch(
  () => route.fullPath,
  () => {
    drawer.value = false
    accountMenu.value = false
    if (auth.isAdmin) office.refresh()
  },
  { immediate: true },
)

async function signOut() {
  messages.stop()
  await auth.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen transition-[padding] duration-200" :class="collapsed ? 'lg:pl-[76px]' : 'lg:pl-[272px]'">
    <!-- Sidebar: fixed on wide screens (full or icon rail), a drawer on phones -->
    <div v-if="drawer" class="fixed inset-0 z-40 bg-navy-950/60 backdrop-blur-sm lg:hidden" @click="drawer = false" />
    <aside
      class="fixed inset-y-0 left-0 z-50 flex flex-col bg-gradient-to-b from-navy-950 via-navy-950 to-[#090c2e] text-navy-100 shadow-2xl transition-all duration-200 dark:from-[#080b26] dark:via-[#080b26] dark:to-[#05071a] lg:translate-x-0 lg:shadow-none"
      :class="[drawer ? 'translate-x-0' : '-translate-x-full', wide ? 'w-[272px]' : 'w-[76px]']"
      aria-label="Menu"
    >
      <!-- Brand -->
      <div class="flex h-16 shrink-0 items-center gap-3 px-4" :class="{ 'justify-center !px-0': !wide }">
        <SaccoMark :sacco="auth.sacco" />
        <div v-if="wide" class="min-w-0 flex-1">
          <p class="truncate text-[15px] font-bold leading-tight text-white">{{ auth.sacco?.short_name || auth.sacco?.name }}</p>
          <p class="truncate text-xs text-navy-200/60">{{ auth.isAdmin ? 'SACCO office' : 'Savings & credit' }}</p>
        </div>
        <button type="button" class="rounded-lg p-1.5 text-navy-200 hover:bg-white/10 lg:hidden" aria-label="Close menu" @click="drawer = false">
          <XMarkIcon class="h-5 w-5" />
        </button>
      </div>

      <!-- Links -->
      <nav class="flex-1 overflow-y-auto overflow-x-hidden px-3 pb-4 pt-2 [scrollbar-width:thin]">
        <!-- Office: Dashboard, then the sections -->
        <template v-if="auth.isAdmin">
          <RouterLink
            :to="{ name: 'overview' }"
            class="group relative mb-3 flex items-center gap-3 rounded-xl py-2 text-sm font-medium transition"
            :class="[wide ? 'px-3' : 'justify-center px-0', route.name === 'overview' ? 'bg-gradient-to-r from-white/[0.14] to-white/[0.04] text-white' : 'text-navy-100/70 hover:bg-white/[0.06] hover:text-white']"
            :aria-label="wide ? undefined : 'Dashboard'"
          >
            <span v-if="route.name === 'overview'" class="absolute inset-y-2 left-0 w-[3px] rounded-r-full bg-saffron-400" aria-hidden="true" />
            <component :is="dashboardIcon" class="h-5 w-5 shrink-0" :class="route.name === 'overview' ? 'text-saffron-300' : 'text-navy-200/50 group-hover:text-navy-100'" aria-hidden="true" />
            <span v-if="wide" class="flex-1">Dashboard</span>
          </RouterLink>

          <div v-for="s in sections" :key="s.key" class="mb-1">
            <div class="group relative flex items-center rounded-xl transition" :class="currentSection === s.key ? 'bg-white/[0.07]' : 'hover:bg-white/[0.05]'">
              <span v-if="currentSection === s.key" class="absolute inset-y-2 left-0 w-[3px] rounded-r-full bg-saffron-400" aria-hidden="true" />
              <RouterLink
                :to="{ name: s.hub }"
                class="flex min-w-0 flex-1 items-center gap-3 py-2 text-sm font-semibold"
                :class="[wide ? 'pl-3' : 'justify-center', currentSection === s.key ? 'text-white' : 'text-navy-100/75 hover:text-white']"
                :aria-label="wide ? undefined : s.label"
              >
                <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br shadow-sm" :class="currentSection === s.key ? s.tone : 'from-white/10 to-white/5'">
                  <component :is="s.icon" class="h-4 w-4" :class="currentSection === s.key ? 'text-white' : 'text-navy-100/70'" aria-hidden="true" />
                </span>
                <span v-if="wide" class="flex-1 truncate">{{ s.label }}</span>
                <span v-if="sectionBadge(s) && (!wide || !open.has(s.key))" class="rounded-full bg-saffron-400 text-[11px] font-bold leading-none text-navy-950" :class="wide ? 'px-2 py-1' : 'absolute right-1 top-0.5 px-1 py-0.5'">{{ sectionBadge(s) }}</span>
                <span v-if="!wide" class="pointer-events-none absolute left-full z-50 ml-3 whitespace-nowrap rounded-lg bg-navy-900 px-2.5 py-1.5 text-xs font-semibold text-white opacity-0 shadow-lift transition group-hover:opacity-100" aria-hidden="true">{{ s.label }}</span>
              </RouterLink>
              <button
                v-if="wide"
                type="button"
                class="mr-1 rounded-lg p-1.5 text-navy-200/60 transition hover:bg-white/10 hover:text-white"
                :aria-expanded="open.has(s.key)"
                :aria-label="`${open.has(s.key) ? 'Hide' : 'Show'} ${s.label} pages`"
                @click="toggleSection(s.key)"
              >
                <ChevronDownIcon class="h-4 w-4 transition-transform" :class="{ 'rotate-180': open.has(s.key) }" />
              </button>
            </div>
            <div v-if="wide && open.has(s.key)" class="mb-2 ml-[1.35rem] mt-0.5 border-l border-white/10 pl-3">
              <RouterLink
                v-for="i in visibleItems(s)"
                :key="i.name"
                :to="{ name: i.name }"
                class="flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-[13px] transition"
                :class="isItemCurrent(i) ? 'bg-white/10 font-semibold text-white' : 'text-navy-100/60 hover:bg-white/[0.05] hover:text-white'"
              >
                <span class="flex-1 truncate">{{ i.label }}</span>
                <span v-if="itemBadge(i)" class="rounded-full bg-saffron-400 px-1.5 py-0.5 text-[11px] font-bold leading-none text-navy-950">{{ itemBadge(i) }}</span>
              </RouterLink>
            </div>
          </div>
        </template>

        <div v-for="group in groups" :key="group.title" class="mb-4">
          <p v-if="group.title && wide" class="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-[0.12em] text-navy-200/40">{{ group.title }}</p>
          <div v-else-if="group.title" class="mx-auto mb-2 h-px w-6 bg-white/10" aria-hidden="true" />
          <RouterLink
            v-for="link in group.links"
            :key="link.name"
            :to="{ name: link.name }"
            class="group relative mb-0.5 flex items-center gap-3 rounded-xl py-2 text-sm font-medium transition"
            :class="[
              wide ? 'px-3' : 'justify-center px-0',
              isCurrent(link) ? 'bg-gradient-to-r from-white/[0.14] to-white/[0.04] text-white shadow-[inset_0_0_0_1px_rgba(255,255,255,0.06)]' : 'text-navy-100/70 hover:bg-white/[0.06] hover:text-white',
            ]"
            :aria-label="wide ? undefined : link.label"
          >
            <span v-if="isCurrent(link)" class="absolute inset-y-2 left-0 w-[3px] rounded-r-full bg-saffron-400" aria-hidden="true" />
            <component
              :is="link.icon"
              class="h-5 w-5 shrink-0 transition"
              :class="isCurrent(link) ? 'text-saffron-300' : 'text-navy-200/50 group-hover:text-navy-100'"
              aria-hidden="true"
            />
            <span v-if="wide" class="flex-1 truncate">{{ link.label }}</span>
            <span
              v-if="link.badge"
              class="rounded-full bg-saffron-400 text-[11px] font-bold leading-none text-navy-950"
              :class="wide ? 'px-2 py-1' : 'absolute right-2 top-1 min-w-[1rem] px-1 py-0.5 text-center'"
              :aria-label="`${link.badge} waiting`"
            >{{ link.badge }}</span>
            <!-- Icon rail: the name appears on hover -->
            <span
              v-if="!wide"
              class="pointer-events-none absolute left-full z-50 ml-3 whitespace-nowrap rounded-lg bg-navy-900 px-2.5 py-1.5 text-xs font-semibold text-white opacity-0 shadow-lift transition group-hover:opacity-100"
              aria-hidden="true"
            >{{ link.label }}</span>
          </RouterLink>
        </div>
      </nav>

      <!-- Theme, account, shrink -->
      <div class="shrink-0 space-y-2 border-t border-white/[0.07] p-3">
        <ThemeSwitch v-if="wide" label />
        <ThemeSwitch v-else class="mx-auto flex" />

        <div class="relative">
          <button
            type="button"
            class="flex w-full items-center gap-3 rounded-xl p-2 text-left transition hover:bg-white/[0.06]"
            :class="{ 'justify-center': !wide }"
            :aria-expanded="accountMenu"
            aria-haspopup="menu"
            @click="accountMenu = !accountMenu"
          >
            <UserAvatar :name="auth.user?.full_name ?? ''" :photo-url="auth.user?.photo_url" size="h-9 w-9 text-sm ring-2 ring-white/10" />
            <span v-if="wide" class="min-w-0 flex-1">
              <span class="block truncate text-sm font-semibold text-white">{{ auth.user?.full_name }}</span>
              <span class="block truncate text-xs text-navy-200/60">{{ auth.user?.role_label }}<template v-if="!auth.isAdmin"> · {{ auth.user?.account_no }}</template></span>
            </span>
            <ChevronUpDownIcon v-if="wide" class="h-4 w-4 text-navy-200/50" aria-hidden="true" />
          </button>
          <div v-if="accountMenu" class="fixed inset-0 z-10" @click="accountMenu = false" />
          <div
            v-if="accountMenu"
            class="absolute bottom-full z-20 mb-2 rounded-xl border border-white/10 bg-navy-900 p-1.5 text-sm shadow-lift"
            :class="wide ? 'left-0 right-0' : 'left-0 w-56'"
            role="menu"
          >
            <RouterLink v-if="!auth.isAdmin" :to="{ name: 'profile' }" class="flex items-center gap-2 rounded-lg px-3 py-2 text-navy-100 hover:bg-white/10 hover:text-white" role="menuitem">
              <UserCircleIcon class="h-4 w-4" />My profile
            </RouterLink>
            <RouterLink v-if="auth.isAdmin" :to="{ name: 'messages' }" class="flex items-center gap-2 rounded-lg px-3 py-2 text-navy-100 hover:bg-white/10 hover:text-white" role="menuitem">
              <ChatBubbleLeftRightIcon class="h-4 w-4" />Messages
            </RouterLink>
            <RouterLink to="/change-password" class="flex items-center gap-2 rounded-lg px-3 py-2 text-navy-100 hover:bg-white/10 hover:text-white" role="menuitem">
              <KeyIcon class="h-4 w-4" />Change password
            </RouterLink>
            <button type="button" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-navy-100 hover:bg-white/10 hover:text-white" role="menuitem" @click="signOut">
              <ArrowLeftStartOnRectangleIcon class="h-4 w-4" />{{ t('Sign out') }}
            </button>
            <button v-if="canInstall" type="button" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-navy-100 hover:bg-white/10 hover:text-white" role="menuitem" @click="install">
              <ArrowDownTrayIcon class="h-4 w-4" />{{ t('Install the app') }}
            </button>
            <div v-if="!auth.isAdmin" class="border-t border-white/10 px-3 pb-1 pt-2"><LanguagePicker dark /></div>
          </div>
        </div>

        <button
          type="button"
          class="hidden w-full items-center gap-2 rounded-lg px-3 py-1.5 text-xs font-medium text-navy-200/50 transition hover:bg-white/[0.06] hover:text-navy-100 lg:flex"
          :class="{ 'justify-center !px-0': !wide }"
          :aria-label="collapsed ? 'Expand the menu' : 'Shrink the menu'"
          @click="toggleCollapsed"
        >
          <ChevronDoubleLeftIcon class="h-4 w-4 transition" :class="{ 'rotate-180': collapsed }" aria-hidden="true" />
          <span v-if="wide">Shrink menu</span>
        </button>
      </div>
    </aside>

    <!-- Top bar -->
    <header class="sticky top-0 z-30 border-b border-line bg-paper/80 backdrop-blur-md">
      <div class="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
        <button type="button" class="-ml-1 rounded-lg p-2 text-gray-700 hover:bg-gray-200/60 lg:hidden" aria-label="Open menu" @click="drawer = true">
          <Bars3Icon class="h-6 w-6" />
        </button>
        <p class="truncate font-semibold text-gray-800">{{ title }}</p>
        <div v-if="auth.isAdmin" class="ml-auto hidden w-full max-w-md sm:block">
          <MemberSearch />
        </div>
        <span class="hidden whitespace-nowrap text-sm text-gray-500 xl:inline" :class="auth.isAdmin ? 'ml-4' : 'ml-auto'">
          {{ new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' }) }}
        </span>
        <span v-if="auth.viewOnly" class="ml-2 hidden items-center gap-1 rounded-full bg-navy-50 px-2.5 py-1 text-xs font-semibold text-navy-800 md:inline-flex" title="You can see everything and download reports; changes are made by the super admin">View only</span>
        <button v-if="canInstall && !auth.isAdmin" type="button" class="tap ml-auto inline-flex items-center gap-1.5 rounded-full bg-brand px-3 py-1.5 text-xs font-semibold text-white lg:hidden" @click="install">
          <ArrowDownTrayIcon class="h-4 w-4" aria-hidden="true" />{{ t('Install the app') }}
        </button>
        <NotificationBell :class="auth.isAdmin ? 'ml-auto sm:ml-2' : 'ml-auto xl:ml-2'" />
      </div>
      <div v-if="auth.isAdmin" class="px-4 pb-3 sm:hidden">
        <MemberSearch />
      </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
      <SmsToast />
      <RouterView v-slot="{ Component, route: page }">
        <Transition name="page" mode="out-in">
          <component :is="Component" :key="page.path" />
        </Transition>
      </RouterView>
    </main>
  </div>
</template>
