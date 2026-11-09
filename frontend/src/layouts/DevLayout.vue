<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowRightOnRectangleIcon, CommandLineIcon, KeyIcon } from '@heroicons/vue/24/outline'
import { ApiError } from '@/api'
import { useDev } from '@/stores/dev'
import ThemeSwitch from '@/components/ThemeSwitch.vue'
import Modal from '@/components/Modal.vue'
import PasswordInput from '@/components/PasswordInput.vue'

/** The developer portal's frame: its own bar and menu, apart from any SACCO's app */
const dev = useDev()
const router = useRouter()

async function signOut() {
  await dev.logout()
  router.push({ name: 'dev-login' })
}

const changing = ref(false)
const current = ref('')
const next = ref('')
const problem = ref('')
const done = ref(false)
async function change() {
  problem.value = ''
  try {
    await dev.changePassword(current.value, next.value)
    done.value = true
    current.value = next.value = ''
  } catch (e) {
    problem.value = e instanceof ApiError ? e.message : 'Couldn\'t change it.'
  }
}
</script>

<template>
  <div class="min-h-screen bg-paper">
    <header class="sticky top-0 z-20 bg-navy-950 text-white shadow-lift">
      <div class="mx-auto flex h-16 max-w-7xl items-center gap-3 px-4 sm:px-6 lg:px-8">
        <RouterLink :to="{ name: 'dev-home' }" class="flex items-center gap-3">
          <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-saffron-400 text-navy-950"><CommandLineIcon class="h-5 w-5" aria-hidden="true" /></span>
          <span class="leading-tight">
            <span class="block font-bold">SACCO platform</span>
            <span class="block text-xs text-navy-200/70">Developer portal</span>
          </span>
        </RouterLink>
        <nav class="ml-6 hidden items-center gap-1 text-sm font-medium sm:flex" aria-label="Developer portal">
          <RouterLink :to="{ name: 'dev-home' }" class="rounded-lg px-3 py-2 text-navy-100/80 hover:bg-white/10 hover:text-white" exact-active-class="!bg-white/10 !text-white">All SACCOs</RouterLink>
          <RouterLink :to="{ name: 'dev-log' }" class="rounded-lg px-3 py-2 text-navy-100/80 hover:bg-white/10 hover:text-white" active-class="!bg-white/10 !text-white">Portal log</RouterLink>
        </nav>
        <div class="ml-auto flex items-center gap-1">
          <ThemeSwitch compact />
          <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-navy-100/80 hover:bg-white/10 hover:text-white" title="Change my password" aria-label="Change my password" @click="changing = true; done = false">
            <KeyIcon class="h-5 w-5" />
          </button>
          <span class="mx-2 hidden text-sm text-navy-100/80 md:inline">{{ dev.developer?.full_name }}</span>
          <button type="button" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold text-navy-100 hover:bg-white/10 hover:text-white" @click="signOut">
            <ArrowRightOnRectangleIcon class="h-5 w-5" aria-hidden="true" /><span class="hidden sm:inline">Sign out</span>
          </button>
        </div>
      </div>
      <nav class="flex gap-1 px-4 pb-2 text-sm font-medium sm:hidden" aria-label="Developer portal">
        <RouterLink :to="{ name: 'dev-home' }" class="rounded-lg px-3 py-1.5 text-navy-100/80" exact-active-class="!bg-white/10 !text-white">All SACCOs</RouterLink>
        <RouterLink :to="{ name: 'dev-log' }" class="rounded-lg px-3 py-1.5 text-navy-100/80" active-class="!bg-white/10 !text-white">Portal log</RouterLink>
      </nav>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
      <RouterView v-slot="{ Component, route: page }">
        <Transition name="page" mode="out-in">
          <component :is="Component" :key="page.path" />
        </Transition>
      </RouterView>
    </main>

    <Modal v-if="changing" title="Change my password" @close="changing = false">
      <p v-if="done" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">Your password has been changed.</p>
      <form v-else class="space-y-4" @submit.prevent="change">
        <div><label for="dp-cur" class="label">Current password</label><PasswordInput id="dp-cur" v-model="current" autocomplete="current-password" /></div>
        <div><label for="dp-new" class="label">New password</label><PasswordInput id="dp-new" v-model="next" autocomplete="new-password" /></div>
        <p v-if="problem" class="text-sm text-red-700" role="alert">{{ problem }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="!current || !next">Change password</button>
      </form>
    </Modal>
  </div>
</template>
