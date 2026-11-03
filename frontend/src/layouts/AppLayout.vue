<script setup lang="ts">
import { computed, ref, watch, type Component } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowLeftStartOnRectangleIcon,
  ArrowTrendingUpIcon,
  ArrowUpTrayIcon,
  BanknotesIcon,
  Bars3Icon,
  CalculatorIcon,
  ChartBarIcon,
  ChevronDoubleLeftIcon,
  ChevronUpDownIcon,
  ClipboardDocumentCheckIcon,
  Cog6ToothIcon,
  DocumentTextIcon,
  HomeIcon,
  InboxStackIcon,
  KeyIcon,
  MegaphoneIcon,
  ReceiptPercentIcon,
  Squares2X2Icon,
  UserCircleIcon,
  UsersIcon,
  WalletIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import { useAuth } from '@/stores/auth'
import { useOffice } from '@/stores/office'
import SaccoMark from '@/components/SaccoMark.vue'
import MemberSearch from '@/components/MemberSearch.vue'
import ThemeSwitch from '@/components/ThemeSwitch.vue'
import NotificationBell from '@/components/NotificationBell.vue'

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
      title: 'My account',
      links: [
        { name: 'dashboard', label: 'Dashboard', icon: HomeIcon },
        { name: 'statement', label: 'Statement', icon: DocumentTextIcon, also: ['statement-print'] },
        { name: 'loans', label: 'Loans', icon: BanknotesIcon, also: ['loan-apply', 'loan'] },
        { name: 'withdrawals', label: 'Withdraw savings', icon: WalletIcon },
        { name: 'profile', label: 'Profile & next of kin', icon: UserCircleIcon },
      ],
    }]
  }
  const w = office.waiting
  return [
    { title: '', links: [{ name: 'overview', label: 'Overview', icon: Squares2X2Icon }] },
    {
      title: 'People',
      links: [
        { name: 'users', label: 'Members', icon: UsersIcon, also: ['member', 'member-import', 'member-statement'] },
        { name: 'notices', label: 'Notices', icon: MegaphoneIcon },
      ],
    },
    {
      title: 'Lending',
      links: [
        { name: 'admin-loans', label: 'Loans', icon: ClipboardDocumentCheckIcon, also: ['loan'], badge: w ? w.loans_to_approve + w.loans_to_pay_out : 0 },
        { name: 'admin-withdrawals', label: 'Withdrawals', icon: InboxStackIcon, badge: w ? w.withdrawals_to_approve + w.withdrawals_to_pay : 0 },
      ],
    },
    {
      title: 'Money',
      links: [
        { name: 'payroll', label: 'Payroll uploads', icon: ArrowUpTrayIcon, also: ['payroll-batch'] },
        { name: 'investments', label: 'Investments', icon: ArrowTrendingUpIcon },
        { name: 'dividends', label: 'Dividends & profit', icon: ReceiptPercentIcon, also: ['dividend-run'] },
        { name: 'cashbook', label: 'Income & expenses', icon: CalculatorIcon },
        { name: 'reports', label: 'Reports', icon: ChartBarIcon },
      ],
    },
    { title: 'Setup', links: [{ name: 'settings', label: 'SACCO rules', icon: Cog6ToothIcon }] },
  ]
})

function isCurrent(link: NavLink) {
  const name = String(route.name)
  return name === link.name || !!link.also?.includes(name)
}

const title = computed(() => route.meta.title ?? '')
const initials = computed(() => auth.user?.full_name.split(' ').map((p) => p[0]).slice(0, 2).join('') ?? '')
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
        <ThemeSwitch v-if="wide" />
        <ThemeSwitch v-else compact class="mx-auto flex" />

        <div class="relative">
          <button
            type="button"
            class="flex w-full items-center gap-3 rounded-xl p-2 text-left transition hover:bg-white/[0.06]"
            :class="{ 'justify-center': !wide }"
            :aria-expanded="accountMenu"
            aria-haspopup="menu"
            @click="accountMenu = !accountMenu"
          >
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-saffron-300 to-saffron-500 text-sm font-bold text-navy-950 ring-2 ring-white/10">{{ initials }}</span>
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
            <RouterLink to="/change-password" class="flex items-center gap-2 rounded-lg px-3 py-2 text-navy-100 hover:bg-white/10 hover:text-white" role="menuitem">
              <KeyIcon class="h-4 w-4" />Change password
            </RouterLink>
            <button type="button" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-navy-100 hover:bg-white/10 hover:text-white" role="menuitem" @click="signOut">
              <ArrowLeftStartOnRectangleIcon class="h-4 w-4" />Sign out
            </button>
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
        <NotificationBell v-if="!auth.isAdmin" class="ml-auto xl:ml-2" />
      </div>
      <div v-if="auth.isAdmin" class="px-4 pb-3 sm:hidden">
        <MemberSearch />
      </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
      <RouterView />
    </main>
  </div>
</template>
