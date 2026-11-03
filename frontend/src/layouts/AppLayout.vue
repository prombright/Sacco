<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuth } from '@/stores/auth'
import SaccoMark from '@/components/SaccoMark.vue'

const auth = useAuth()
const router = useRouter()
const menuOpen = ref(false)

// Everyone's own money
const links = [
  { to: { name: 'dashboard' }, label: 'Dashboard' },
  { to: { name: 'statement' }, label: 'My statement' },
  { to: { name: 'loans' }, label: 'Loans' },
  { to: { name: 'withdrawals' }, label: 'Withdraw' },
]

// Running the SACCO: a second row, admins only
const officeLinks = computed(() => auth.isAdmin ? [
  { to: { name: 'users' }, label: 'Members' },
  { to: { name: 'payroll' }, label: 'Payroll' },
  { to: { name: 'admin-loans' }, label: 'Loan applications' },
  { to: { name: 'admin-withdrawals' }, label: 'Withdrawals' },
  { to: { name: 'dividends' }, label: 'Dividends' },
  { to: { name: 'reports' }, label: 'Reports' },
  { to: { name: 'settings' }, label: 'Rules' },
] : [])

// Pages inside a section light up that section's link
const sections: Record<string, string[]> = {
  users: ['member'],
  loans: ['loan-apply', 'loan'],
  payroll: ['payroll-batch'],
  dividends: ['dividend-run'],
}
const route = useRoute()
function isCurrent(name: string) {
  return route.name === name || (sections[name] ?? []).includes(String(route.name))
}

async function signOut() {
  menuOpen.value = false
  await auth.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen">
    <header class="border-b border-gray-200 bg-white">
      <div class="mx-auto flex max-w-6xl items-center gap-4 px-4 py-3">
        <RouterLink to="/" class="flex min-w-0 items-center gap-3">
          <SaccoMark :sacco="auth.sacco" />
          <span class="truncate font-semibold">{{ auth.sacco?.short_name || auth.sacco?.name }}</span>
        </RouterLink>

        <nav class="ml-4 hidden gap-1 md:flex" aria-label="Main">
          <RouterLink
            v-for="link in links"
            :key="link.label"
            :to="link.to"
            class="rounded-lg px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900"
            :class="{ '!bg-brand/10 !text-brand': isCurrent(link.to.name) }"
          >
            {{ link.label }}
          </RouterLink>
        </nav>

        <div class="relative ml-auto">
          <button
            type="button"
            class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-left hover:bg-gray-100"
            :aria-expanded="menuOpen"
            aria-haspopup="menu"
            @click="menuOpen = !menuOpen"
          >
            <span class="hidden text-right sm:block">
              <span class="block text-sm font-medium leading-tight">{{ auth.user?.full_name }}</span>
              <span class="block text-xs text-gray-500">{{ auth.user?.role_label }}</span>
            </span>
            <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-gray-900 text-xs font-semibold text-white">
              {{ auth.user?.full_name.split(' ').map((p) => p[0]).slice(0, 2).join('') }}
            </span>
          </button>
          <div v-if="menuOpen" class="fixed inset-0 z-10" @click="menuOpen = false" />
          <div v-if="menuOpen" class="card absolute right-0 z-20 mt-2 w-52 p-1 shadow-lg" role="menu">
            <p class="px-3 py-2 text-xs text-gray-500 sm:hidden">{{ auth.user?.full_name }} · {{ auth.user?.role_label }}</p>
            <RouterLink to="/change-password" class="block rounded-md px-3 py-2 text-sm hover:bg-gray-100" role="menuitem" @click="menuOpen = false">
              Change password
            </RouterLink>
            <button type="button" class="block w-full rounded-md px-3 py-2 text-left text-sm hover:bg-gray-100" role="menuitem" @click="signOut">
              Sign out
            </button>
          </div>
        </div>
      </div>

      <!-- Phones: the same links in a row under the header -->
      <nav class="flex gap-1 overflow-x-auto border-t border-gray-100 px-4 py-2 md:hidden" aria-label="Main">
        <RouterLink
          v-for="link in links"
          :key="link.label"
          :to="link.to"
          class="whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium text-gray-600"
          :class="{ '!bg-brand/10 !text-brand': isCurrent(link.to.name) }"
        >
          {{ link.label }}
        </RouterLink>
      </nav>

      <nav v-if="officeLinks.length" class="border-t border-gray-100 bg-gray-50" aria-label="SACCO office">
        <div class="mx-auto flex max-w-6xl items-center gap-1 overflow-x-auto px-4 py-1.5">
          <span class="mr-2 whitespace-nowrap text-xs font-semibold uppercase tracking-wide text-gray-500">Office</span>
          <RouterLink
            v-for="link in officeLinks"
            :key="link.label"
            :to="link.to"
            class="whitespace-nowrap rounded-md px-2.5 py-1 text-sm font-medium text-gray-600 hover:bg-white hover:text-gray-900"
            :class="{ '!bg-white !text-brand shadow-sm': isCurrent(link.to.name) }"
          >
            {{ link.label }}
          </RouterLink>
        </div>
      </nav>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-6">
      <RouterView />
    </main>
  </div>
</template>
