<script setup lang="ts">
import { accountLabel } from '@/account'
import BottomTabs from '@/components/BottomTabs.vue'
import ToastHost from '@/components/ToastHost.vue'
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
  PencilSquareIcon,
  BanknotesIcon,
  Bars3Icon,
  ChevronLeftIcon,
  ChevronDownIcon,
  DocumentTextIcon,
  HomeIcon,
  KeyIcon,
  ReceiptPercentIcon,
  UserCircleIcon,
  WalletIcon,
  XMarkIcon,
  QuestionMarkCircleIcon,
  ArrowsRightLeftIcon,
  MagnifyingGlassIcon,
  MoonIcon,
  SunIcon,
} from '@heroicons/vue/24/outline'
import { useTheme } from '@/stores/theme'
import { useAuth } from '@/stores/auth'
import { useOffice } from '@/stores/office'
import { removePhoto, uploadPhoto } from '@/photos'
import { toast } from '@/toast'
import { CameraIcon, TrashIcon } from '@heroicons/vue/24/outline'
import SaccoMark from '@/components/SaccoMark.vue'
import CommandPalette from '@/components/CommandPalette.vue'
import SwitchAccountButton from '@/components/SwitchAccountButton.vue'
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
  /** smaller links (the quick links) */
  small?: boolean
}

const auth = useAuth()
const messages = useMessages()
messages.start()
const office = useOffice()
const route = useRoute()
const theme = useTheme()
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
 * no savings or loans of their own - staff who are also members log in with their member account.
 */
const groups = computed<NavGroup[]>(() => {
  if (!auth.isAdmin) {
    return [
      {
        title: '',
        links: [
          { name: 'dashboard', label: t('Dashboard'), icon: HomeIcon },
          { name: 'messages', label: t('Messages'), icon: ChatBubbleLeftRightIcon, badge: messages.unread },
          { name: 'office-chat', label: t('Write to the office'), icon: PencilSquareIcon },
          { name: 'loans', label: t('Loans'), icon: BanknotesIcon, also: ['loan'] },
          { name: 'receipts-mine', label: t('Transactions'), icon: ArrowsRightLeftIcon },
          { name: 'profile', label: t('Profile'), icon: UserCircleIcon },
          { name: 'help', label: t('Help'), icon: QuestionMarkCircleIcon },
        ],
      },
      {
        title: t('Quick links'),
        small: true,
        links: [
          { name: 'loan-apply', label: t('Apply for a loan'), icon: ReceiptPercentIcon },
          { name: 'withdrawals', label: t('Withdraw savings'), icon: WalletIcon, also: ['withdrawal-slip'] },
          { name: 'statement', label: t('My statement'), icon: DocumentTextIcon, also: ['statement-print'] },
        ],
      },
    ]
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

/** Quick search (Ctrl + K) */
const searchOpen = ref(false)

/** Office staff who also save: to their member account and back */
async function switchAccount() {
  const to = auth.user?.switch_to?.to
  await auth.switchAccount()
  drawer.value = false
  await router.replace(to === 'member' ? { name: 'dashboard' } : { name: 'overview' })
}

/** The profile photo, from the account menu: anyone signed in sets or removes their own */
const photoBusy = ref(false)
async function pickPhoto(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file || !auth.user) return
  photoBusy.value = true
  try {
    const u = await uploadPhoto(file)
    auth.user.photo_url = u.photo_url
    toast('Your photo is saved')
  } catch (err) {
    toast((err as { response?: { data?: { message?: string } } }).response?.data?.message ?? 'The photo couldn\'t be saved. Please try again.', 'error')
  } finally {
    photoBusy.value = false
  }
}
async function dropPhoto() {
  if (!auth.user) return
  photoBusy.value = true
  try {
    const u = await removePhoto()
    auth.user.photo_url = u.photo_url
    toast('Your photo is removed')
  } catch {
    toast('The photo couldn\'t be removed. Please try again.', 'error')
  } finally {
    photoBusy.value = false
  }
}

async function signOut() {
  messages.stop()
  await auth.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <div class="app-look min-h-screen transition-[padding] duration-200" :class="collapsed ? 'lg:pl-[76px]' : 'lg:pl-[264px]'">
    <!-- Sidebar: deep indigo with a soft violet wave at its foot, plain white icons, and the page
         you are on as a violet pill. Fixed on wide screens, a drawer on phones. -->
    <div v-if="drawer" class="fixed inset-0 z-40 bg-navy-950/60 backdrop-blur-sm lg:hidden" @click="drawer = false" />
    <aside
      data-print-hide
      class="app-sidebar fixed inset-y-0 left-0 z-50 flex flex-col overflow-hidden rounded-r-[1.75rem] text-white transition-all duration-200 lg:translate-x-0 lg:rounded-none"
      :class="[drawer ? 'translate-x-0' : '-translate-x-full', wide ? 'w-[264px]' : 'w-[76px]']"
      aria-label="Menu"
    >
      <!-- the violet wave at the foot of the sidebar -->
      <svg class="sidebar-wave" viewBox="0 0 264 300" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0 70C70 40 150 70 264 150V300H0Z" fill="#4a2bb8" opacity="0.5" />
        <path d="M0 150C80 115 170 150 264 215V300H0Z" fill="#7a3cf0" opacity="0.35" />
      </svg>
      <!-- Brand: logo, name and office -->
      <div class="relative z-[1] flex shrink-0 items-center gap-3 px-5 pb-7 pt-7" :class="{ 'justify-center !px-0': !wide }">
        <span class="sidebar-logo"><SaccoMark :sacco="auth.sacco" /></span>
        <div v-if="wide" class="min-w-0 flex-1">
          <p class="truncate text-[1.2rem] font-extrabold leading-tight tracking-[-0.01em] text-white">{{ auth.isAdmin ? (auth.sacco?.short_name || auth.sacco?.name) : (auth.sacco?.name || auth.sacco?.short_name) }}</p>
          <p class="mt-0.5 truncate text-[0.85rem] font-medium text-white/75">{{ auth.isAdmin ? (auth.isSuperAdmin ? 'Super Admin Office' : 'SACCO Office') : 'Savings & Credit' }}</p>
        </div>
        <button type="button" class="rounded-lg p-1.5 text-navy-200 hover:bg-white/10 lg:hidden" aria-label="Close menu" @click="drawer = false">
          <XMarkIcon class="h-5 w-5" />
        </button>
      </div>

      <!-- Links -->
      <nav class="sidebar-nav relative z-[1] min-h-0 flex-[0_1_auto] overflow-y-auto overflow-x-hidden px-3 pb-4 pt-3">
        <!-- Office: Dashboard, then the sections -->
        <template v-if="auth.isAdmin">
          <RouterLink
            :to="{ name: 'overview' }"
            class="nav-link group relative mb-2 flex items-center gap-4 rounded-2xl py-3 text-[1rem] transition"
            :class="[wide ? 'px-3.5' : 'justify-center px-0', route.name === 'overview' ? 'nav-on' : 'nav-off']"
            :aria-label="wide ? undefined : 'Dashboard'"
          >
            <span class="nav-icon"><component :is="dashboardIcon" class="h-[22px] w-[22px]" aria-hidden="true" /></span>
            <span v-if="wide" class="flex-1">Dashboard</span>
          </RouterLink>

          <div v-for="s in sections" :key="s.key" class="mb-2">
            <div class="group relative flex items-center rounded-xl transition" :class="[currentSection === s.key ? 'nav-on' : 'nav-off', 'rounded-2xl']">
              <RouterLink
                :to="{ name: s.hub }"
                class="nav-link flex min-w-0 flex-1 items-center gap-4 py-3 text-[1rem]"
                :class="[wide ? 'pl-3.5' : 'justify-center', { 'font-bold': currentSection === s.key }]"
                :aria-label="wide ? undefined : s.label"
              >
                <span class="nav-icon"><component :is="s.icon" class="h-[22px] w-[22px]" aria-hidden="true" /></span>
                <span v-if="wide" class="flex-1 truncate">{{ s.label }}</span>
                <span v-if="sectionBadge(s) && (!wide || !open.has(s.key))" class="rounded-full bg-rose-500 text-[11px] font-bold leading-none text-white" :class="wide ? 'px-2 py-1' : 'absolute right-1 top-0.5 px-1 py-0.5'">{{ sectionBadge(s) }}</span>
                <span v-if="!wide" class="pointer-events-none absolute left-full z-50 ml-3 whitespace-nowrap rounded-lg bg-navy-900 px-2.5 py-1.5 text-xs font-semibold text-white opacity-0 shadow-lift transition group-hover:opacity-100" aria-hidden="true">{{ s.label }}</span>
              </RouterLink>
              <button
                v-if="wide"
                type="button"
                class="mr-1 rounded-lg p-1.5 text-white/70 transition hover:bg-white/15 hover:text-white"
                :aria-expanded="open.has(s.key)"
                :aria-label="`${open.has(s.key) ? 'Hide' : 'Show'} ${s.label} pages`"
                @click="toggleSection(s.key)"
              >
                <ChevronDownIcon class="h-4 w-4 transition-transform" :class="{ 'rotate-180': open.has(s.key) }" />
              </button>
            </div>
            <div v-if="wide && open.has(s.key)" class="sub-links mb-2 ml-[1.45rem] mt-1 pl-3">
              <RouterLink
                v-for="i in visibleItems(s)"
                :key="i.name"
                :to="{ name: i.name }"
                class="sub-link flex items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-[13px] transition"
                :class="isItemCurrent(i) ? 'sub-on font-semibold text-white' : 'text-white/90 hover:bg-white/[0.07] hover:text-white'"
              >
                <span class="sub-dot" aria-hidden="true" />
                <span class="flex-1 truncate">{{ i.label }}</span>
                <span v-if="itemBadge(i)" class="rounded-full bg-rose-500 px-1.5 py-0.5 text-[11px] font-bold leading-none text-white">{{ itemBadge(i) }}</span>
              </RouterLink>
            </div>
          </div>
        </template>

        <div v-for="group in groups" :key="group.title || 'main'" class="mb-4" :class="{ 'mt-2 border-t border-white/15 pt-4': group.small }">
          <p v-if="group.title && wide" class="px-3 pb-2 text-[10.5px] font-semibold uppercase tracking-[0.16em] text-white/60">{{ group.title }}</p>
          <div v-else-if="group.title" class="mx-auto mb-2 h-px w-6 bg-white/10" aria-hidden="true" />
          <RouterLink
            v-for="link in group.links"
            :key="link.name"
            :to="{ name: link.name }"
            class="nav-link group relative mb-2 flex items-center gap-4 rounded-2xl py-3 text-[1rem] transition"
            :class="[
              wide ? 'px-3.5' : 'justify-center px-0',
              isCurrent(link) ? 'nav-on' : 'nav-off',
              group.small ? '!py-2 text-[0.9rem]' : '',
            ]"
            :aria-label="wide ? undefined : link.label"
          >
            <span class="nav-icon"><component :is="link.icon" class="h-[22px] w-[22px]" aria-hidden="true" /></span>
            <span v-if="wide" class="flex-1 truncate">{{ link.label }}</span>
            <span
              v-if="link.badge"
              class="rounded-full bg-rose-500 text-[11px] font-bold leading-none text-white"
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

      <!-- Log out (light / dark is in the top bar) -->
      <div class="relative z-[1] mt-auto shrink-0 p-3 pt-2">
        <button
          type="button"
          class="sidebar-logout group relative flex w-full items-center gap-2.5 rounded-xl py-2.5 text-sm font-semibold text-white transition"
          :class="wide ? 'justify-center px-3' : 'justify-center px-0'"
          :aria-label="t('Log out')"
          :title="wide ? undefined : t('Log out')"
          @click="signOut"
        >
          <ArrowLeftStartOnRectangleIcon class="h-5 w-5 shrink-0 transition group-hover:-translate-x-0.5" aria-hidden="true" />
          <span v-if="wide">{{ t('Log out') }}</span>
        </button>
      </div>
    </aside>

    <!-- the page's rounded corner against the sidebar (wide screens) -->
    <span class="sidebar-corner hidden lg:block print:!hidden" :style="{ left: (collapsed ? 76 : 264) + 'px' }" aria-hidden="true" />

    <!-- Shrink / expand: a small round arrow on the sidebar's right edge (wide screens) -->
    <button
      type="button"
      class="fixed top-1/2 z-[55] hidden h-6 w-6 print:!hidden -translate-y-1/2 items-center justify-center rounded-full border border-[#e5ddfd] bg-white text-[#5a1fd0] shadow-md transition-[left] duration-200 hover:bg-[#f2ecff] dark:border-white/15 dark:bg-navy-900 dark:text-white lg:flex"
      :style="{ left: (collapsed ? 76 : 264) - 12 + 'px' }"
      :aria-label="collapsed ? 'Expand the menu' : 'Shrink the menu'"
      :title="collapsed ? 'Expand the menu' : 'Shrink the menu'"
      @click="toggleCollapsed"
    >
      <ChevronLeftIcon class="h-3.5 w-3.5 transition" :class="{ 'rotate-180': collapsed }" aria-hidden="true" />
    </button>

    <!-- Top bar: sits on the page's sky blue -->
    <header class="app-topbar sticky top-0 z-30 backdrop-blur-md print:hidden" :class="{ 'member-hero-top': !auth.isAdmin && route.name === 'dashboard' }">
      <!-- three columns on wider screens: menu button, the search in the middle, the icons -->
      <div class="flex h-16 items-center gap-3 px-4 sm:grid sm:grid-cols-[1fr_minmax(0,30rem)_1fr] sm:px-6 lg:px-8">
        <div class="flex items-center">
          <!-- wide screens: shrink the menu to icons, or open it again -->
          <button type="button" class="-ml-1 hidden rounded-lg p-2 text-gray-700 hover:bg-gray-200/60 lg:inline-flex" :aria-label="collapsed ? 'Expand the menu' : 'Shrink the menu'" @click="toggleCollapsed">
            <Bars3Icon class="h-6 w-6" />
          </button>
          <button type="button" class="-ml-1 rounded-lg p-2 text-gray-700 hover:bg-gray-200/60 lg:hidden" aria-label="Open menu" @click="drawer = true">
            <Bars3Icon class="h-6 w-6" />
          </button>
          <!-- members' phones: the SACCO beside the menu button -->
          <div v-if="!auth.isAdmin" class="ml-1 flex min-w-0 items-center gap-2 lg:hidden">
            <SaccoMark :sacco="auth.sacco" size="sm" />
            <span class="min-w-0 leading-tight">
              <span class="block truncate text-sm font-extrabold text-ink">{{ auth.sacco?.name || auth.sacco?.short_name }}</span>
              <span class="block text-[11px] text-gray-500">Savings &amp; Credit</span>
            </span>
          </div>
        </div>
        <!-- Quick search: members, loans and pages (also Ctrl + K) -->
        <button
          type="button"
          class="hidden h-11 w-full items-center gap-3 rounded-full bg-[#f1f0f9] px-5 text-left text-sm text-gray-500 transition hover:bg-[#ebe9f7] dark:bg-white/[0.06] sm:flex"
          :aria-label="`${auth.isAdmin ? 'Search members, loans, reports' : 'Search for statements, loans, receipts'} (Ctrl + K)`"
          @click="searchOpen = true"
        >
          <MagnifyingGlassIcon class="h-5 w-5 shrink-0 text-gray-500" aria-hidden="true" />
          <span class="flex-1 truncate">{{ auth.isAdmin ? 'Search members, loans, reports…' : 'Search for statements, loans, receipts…' }}</span>
        </button>
        <div class="ml-auto flex items-center justify-end gap-3 sm:ml-0">
        <!-- Small phones: just the icon, so the page title keeps its room -->
        <button v-if="canInstall && !auth.isAdmin" type="button" class="tap inline-flex shrink-0 items-center gap-1.5 rounded-full bg-brand px-3 py-1.5 text-xs font-semibold text-white max-[420px]:px-2.5 lg:hidden" :aria-label="t('Install the app')" @click="install">
          <ArrowDownTrayIcon class="h-4 w-4" aria-hidden="true" /><span class="max-[420px]:hidden">{{ t('Install the app') }}</span>
        </button>
        <button v-if="auth.isAdmin" type="button" class="tap rounded-full p-2 text-gray-600 hover:bg-gray-200/60 sm:hidden" aria-label="Find a member, loan or page" @click="searchOpen = true">
          <MagnifyingGlassIcon class="h-6 w-6" />
        </button>
        <!-- Light / dark -->
        <ThemeSwitch v-if="auth.isAdmin" tone="light" class="tap !h-10 !w-10 rounded-full dark:text-gray-300 dark:hover:bg-white/10" />
        <!-- Messages: the full conversation (the bell beside it shows the newest) -->
        <RouterLink
          v-if="auth.isAdmin"
          :to="{ name: 'messages' }"
          class="tap rounded-full p-2 text-gray-600 transition hover:bg-gray-200/60 hover:text-ink"
          :class="{ '!bg-[#6a1ff5]/10 !text-[#6a1ff5]': route.name === 'messages' }"
          aria-label="Messages"
          title="Messages"
        >
          <ChatBubbleLeftRightIcon class="h-6 w-6" />
        </RouterLink>
        <!-- members: help, one tap away -->
        <RouterLink v-if="!auth.isAdmin" :to="{ name: 'help' }" class="tap hidden rounded-full p-2 text-gray-600 transition hover:bg-gray-200/60 hover:text-ink sm:inline-flex" aria-label="Help" title="Help">
          <QuestionMarkCircleIcon class="h-6 w-6" />
        </RouterLink>
        <NotificationBell />
        <!-- Office ⇄ my member account, without logging out -->
        <SwitchAccountButton />

        <!-- The account: profile picture, name and role; opens the account menu -->
        <div class="relative">
          <button
            type="button"
            class="flex items-center gap-2.5 rounded-full p-0.5 transition hover:bg-black/[0.03] dark:hover:bg-white/5 md:pr-2"
            :aria-expanded="accountMenu"
            aria-haspopup="menu"
            aria-label="My account"
            @click="accountMenu = !accountMenu"
          >
            <UserAvatar :name="auth.user?.full_name ?? ''" :photo-url="auth.user?.photo_url" size="h-10 w-10 text-sm ring-2 ring-white shadow-sm dark:ring-white/15" />
            <span class="hidden max-w-[13rem] truncate text-sm font-semibold text-ink md:block">{{ auth.isAdmin ? auth.user?.role_label : auth.user?.full_name }}</span>
            <ChevronDownIcon class="hidden h-4 w-4 text-gray-500 transition md:block" :class="{ 'rotate-180': accountMenu }" aria-hidden="true" />
          </button>
          <div v-if="accountMenu" class="fixed inset-0 z-10" @click="accountMenu = false" />
          <div
            v-if="accountMenu"
            class="absolute right-0 top-full z-20 mt-2 w-64 rounded-xl border border-line bg-surface p-1.5 text-sm shadow-lift"
            role="menu"
          >
            <!-- who is logged in, inside the menu (the top bar shows only the picture) -->
            <div class="mb-1 flex items-center gap-3 border-b border-line px-3 pb-3 pt-2">
              <!-- tap the picture to change it -->
              <label class="group relative shrink-0 cursor-pointer" :class="{ 'pointer-events-none opacity-60': photoBusy }" :title="auth.user?.photo_url ? 'Change photo' : 'Add a photo'">
                <UserAvatar :name="auth.user?.full_name ?? ''" :photo-url="auth.user?.photo_url" size="h-12 w-12 text-base" />
                <span class="absolute -bottom-0.5 -right-0.5 inline-flex h-5 w-5 items-center justify-center rounded-full bg-[#6a1ff5] text-white ring-2 ring-surface transition group-hover:scale-110" aria-hidden="true">
                  <CameraIcon class="h-3 w-3" />
                </span>
                <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" :disabled="photoBusy" :aria-label="auth.user?.photo_url ? 'Change photo' : 'Add a photo'" @change="pickPhoto" />
              </label>
              <span class="min-w-0">
                <span class="block truncate font-semibold">{{ auth.user?.full_name }}</span>
                <span class="block truncate text-xs text-gray-500">{{ auth.user?.role_label }}<template v-if="auth.viewOnly"> · view only</template><template v-if="!auth.isAdmin"> · {{ accountLabel(auth.user?.account_no, auth.sacco) }}</template></span>
              </span>
            </div>
            <RouterLink v-if="!auth.isAdmin" :to="{ name: 'profile' }" class="menu-item" role="menuitem">
              <UserCircleIcon class="h-4 w-4 text-gray-500" />My profile
            </RouterLink>
            <label class="menu-item cursor-pointer" role="menuitem" :class="{ 'pointer-events-none opacity-60': photoBusy }">
              <CameraIcon class="h-4 w-4 text-gray-500" />{{ photoBusy ? 'Saving…' : auth.user?.photo_url ? t('Change photo') : t('Add a photo') }}
              <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" :disabled="photoBusy" @change="pickPhoto" />
            </label>
            <button v-if="auth.user?.photo_url" type="button" class="menu-item" role="menuitem" :disabled="photoBusy" @click="dropPhoto">
              <TrashIcon class="h-4 w-4 text-gray-500" />{{ t('Remove photo') }}
            </button>
            <!-- light / dark (members have it here; the office also has it in the top bar) -->
            <button type="button" class="menu-item" role="menuitem" @click="theme.toggle()">
              <component :is="theme.dark ? SunIcon : MoonIcon" class="h-4 w-4 text-gray-500" />{{ theme.dark ? 'Light mode' : 'Dark mode' }}
            </button>
            <RouterLink to="/change-password" class="menu-item" role="menuitem">
              <KeyIcon class="h-4 w-4 text-gray-500" />Change password
            </RouterLink>
            <button v-if="auth.user?.switch_to" type="button" class="menu-item !bg-[#efe8fe] font-semibold !text-[#6a1ff5] hover:!bg-[#e0e8ff] dark:!bg-white/10 dark:!text-navy-200" role="menuitem" @click="switchAccount">
              <ArrowsRightLeftIcon class="h-4 w-4" />{{ auth.user.switch_to.to === 'member' ? 'Switch to my member account' : auth.user.switch_to.label }}
            </button>
            <button v-if="canInstall" type="button" class="menu-item" role="menuitem" @click="install">
              <ArrowDownTrayIcon class="h-4 w-4 text-gray-500" />{{ t('Install the app') }}
            </button>
            <div v-if="!auth.isAdmin" class="border-t border-line px-3 pb-1 pt-2"><LanguagePicker /></div>
            <div class="my-1 border-t border-line" />
            <button type="button" class="menu-item !text-red-700 hover:!bg-red-50" role="menuitem" @click="signOut">
              <ArrowLeftStartOnRectangleIcon class="h-4 w-4" />{{ t('Log out') }}
            </button>
          </div>
        </div>
        </div>
      </div>
    </header>
    <CommandPalette v-model="searchOpen" />

    <main class="mx-auto max-w-[112rem] px-4 pb-28 pt-4 sm:px-6 lg:px-8 lg:pb-8">
      <SmsToast />
      <RouterView v-slot="{ Component, route: page }">
        <Transition name="page" mode="out-in">
          <component :is="Component" :key="page.path" />
        </Transition>
      </RouterView>
    </main>
    <BottomTabs @more="drawer = true" />
    <ToastHost />
  </div>
</template>
