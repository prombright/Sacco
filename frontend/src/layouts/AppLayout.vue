<script setup lang="ts">
import { computed, ref, watch, type Component } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowLeftStartOnRectangleIcon,
  ArrowUpTrayIcon,
  BanknotesIcon,
  Bars3Icon,
  ChartBarIcon,
  ClipboardDocumentCheckIcon,
  Cog6ToothIcon,
  DocumentTextIcon,
  HomeIcon,
  InboxStackIcon,
  KeyIcon,
  ReceiptPercentIcon,
  Squares2X2Icon,
  UsersIcon,
  WalletIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import { useAuth } from '@/stores/auth'
import { useOffice } from '@/stores/office'
import SaccoMark from '@/components/SaccoMark.vue'
import MemberSearch from '@/components/MemberSearch.vue'

interface NavLink {
  name: string
  label: string
  icon: Component
  /** Other pages that belong under this link */
  also?: string[]
  /** How many things are waiting here */
  badge?: number
}

const auth = useAuth()
const office = useOffice()
const route = useRoute()
const router = useRouter()
const drawer = ref(false)

// Everyone's own money
const mine: NavLink[] = [
  { name: 'dashboard', label: 'My dashboard', icon: HomeIcon },
  { name: 'statement', label: 'My statement', icon: DocumentTextIcon },
  { name: 'loans', label: 'My loans', icon: BanknotesIcon, also: ['loan-apply'] },
  { name: 'withdrawals', label: 'Withdraw savings', icon: WalletIcon },
]

// Running the SACCO (admins), with counts of what's waiting
const officeLinks = computed<NavLink[]>(() => {
  const w = office.waiting
  return [
    { name: 'overview', label: 'Overview', icon: Squares2X2Icon },
    { name: 'users', label: 'Members', icon: UsersIcon, also: ['member', 'member-import'] },
    { name: 'admin-loans', label: 'Loans', icon: ClipboardDocumentCheckIcon, badge: w ? w.loans_to_approve + w.loans_to_pay_out : 0 },
    { name: 'admin-withdrawals', label: 'Withdrawals', icon: InboxStackIcon, badge: w ? w.withdrawals_to_approve + w.withdrawals_to_pay : 0 },
    { name: 'payroll', label: 'Payroll uploads', icon: ArrowUpTrayIcon, also: ['payroll-batch'] },
    { name: 'dividends', label: 'Dividends', icon: ReceiptPercentIcon, also: ['dividend-run'] },
    { name: 'reports', label: 'Reports', icon: ChartBarIcon },
    { name: 'settings', label: 'SACCO rules', icon: Cog6ToothIcon },
  ]
})

function isCurrent(link: NavLink) {
  const name = String(route.name)
  if (name === link.name || link.also?.includes(name)) return true
  // A loan page belongs to "Loans" for admins looking at someone else's, else to "My loans"
  if (name === 'loan') return auth.isAdmin ? link.name === 'admin-loans' : link.name === 'loans'
  return false
}

const title = computed(() => route.meta.title ?? '')
const initials = computed(() => auth.user?.full_name.split(' ').map((p) => p[0]).slice(0, 2).join('') ?? '')

watch(
  () => route.fullPath,
  () => {
    drawer.value = false
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
  <div class="min-h-screen lg:pl-64">
    <!-- Sidebar: fixed on wide screens, a drawer on phones -->
    <div v-if="drawer" class="fixed inset-0 z-40 bg-navy-950/50 lg:hidden" @click="drawer = false" />
    <aside
      class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-navy-950 text-navy-100 transition-transform duration-200 lg:translate-x-0"
      :class="drawer ? 'translate-x-0' : '-translate-x-full'"
      aria-label="Menu"
    >
      <div class="flex items-center gap-3 px-5 pb-4 pt-5">
        <SaccoMark :sacco="auth.sacco" />
        <div class="min-w-0">
          <p class="truncate font-bold text-white">{{ auth.sacco?.short_name || auth.sacco?.name }}</p>
          <p class="truncate text-xs text-navy-200/70">Savings &amp; credit</p>
        </div>
        <button type="button" class="ml-auto rounded-lg p-1 text-navy-200 hover:bg-white/10 lg:hidden" aria-label="Close menu" @click="drawer = false">
          <XMarkIcon class="h-5 w-5" />
        </button>
      </div>

      <nav class="flex-1 space-y-6 overflow-y-auto px-3 pb-4">
        <div>
          <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-navy-200/50">My account</p>
          <RouterLink
            v-for="link in mine"
            :key="link.name"
            :to="{ name: link.name }"
            class="group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition"
            :class="isCurrent(link) ? 'bg-white/10 text-white' : 'text-navy-100/80 hover:bg-white/5 hover:text-white'"
          >
            <span v-if="isCurrent(link)" class="absolute inset-y-1.5 left-0 w-1 rounded-r bg-saffron-400" aria-hidden="true" />
            <component :is="link.icon" class="h-5 w-5 shrink-0" :class="isCurrent(link) ? 'text-saffron-300' : 'text-navy-200/60 group-hover:text-navy-100'" aria-hidden="true" />
            {{ link.label }}
          </RouterLink>
        </div>

        <div v-if="auth.isAdmin">
          <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-navy-200/50">SACCO office</p>
          <RouterLink
            v-for="link in officeLinks"
            :key="link.name"
            :to="{ name: link.name }"
            class="group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition"
            :class="isCurrent(link) ? 'bg-white/10 text-white' : 'text-navy-100/80 hover:bg-white/5 hover:text-white'"
          >
            <span v-if="isCurrent(link)" class="absolute inset-y-1.5 left-0 w-1 rounded-r bg-saffron-400" aria-hidden="true" />
            <component :is="link.icon" class="h-5 w-5 shrink-0" :class="isCurrent(link) ? 'text-saffron-300' : 'text-navy-200/60 group-hover:text-navy-100'" aria-hidden="true" />
            <span class="flex-1">{{ link.label }}</span>
            <span v-if="link.badge" class="rounded-full bg-saffron-400 px-2 py-0.5 text-xs font-bold text-navy-950" :aria-label="`${link.badge} waiting`">{{ link.badge }}</span>
          </RouterLink>
        </div>
      </nav>

      <div class="border-t border-white/10 p-3">
        <div class="flex items-center gap-3 rounded-lg px-2 py-2">
          <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-saffron-400 text-sm font-bold text-navy-950">{{ initials }}</span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-white">{{ auth.user?.full_name }}</p>
            <p class="truncate text-xs text-navy-200/70">{{ auth.user?.role_label }} · {{ auth.user?.account_no }}</p>
          </div>
        </div>
        <div class="mt-1 grid grid-cols-2 gap-1">
          <RouterLink to="/change-password" class="flex items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-xs font-medium text-navy-100/80 hover:bg-white/5 hover:text-white">
            <KeyIcon class="h-4 w-4" aria-hidden="true" /> Password
          </RouterLink>
          <button type="button" class="flex items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-xs font-medium text-navy-100/80 hover:bg-white/5 hover:text-white" @click="signOut">
            <ArrowLeftStartOnRectangleIcon class="h-4 w-4" aria-hidden="true" /> Sign out
          </button>
        </div>
      </div>
    </aside>

    <!-- Top bar -->
    <header class="sticky top-0 z-30 border-b border-line bg-paper/90 backdrop-blur">
      <div class="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
        <button type="button" class="-ml-1 rounded-lg p-2 text-gray-700 hover:bg-gray-200/60 lg:hidden" aria-label="Open menu" @click="drawer = true">
          <Bars3Icon class="h-6 w-6" />
        </button>
        <p class="truncate text-sm font-semibold text-gray-500 lg:hidden">{{ title }}</p>
        <div v-if="auth.isAdmin" class="ml-auto hidden flex-1 justify-end sm:flex lg:ml-0 lg:justify-start">
          <MemberSearch />
        </div>
        <span class="ml-auto hidden text-sm text-gray-500 lg:inline">{{ new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) }}</span>
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
