<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { CommandLineIcon } from '@heroicons/vue/24/outline'
import { ApiError } from '@/api'
import { useDev } from '@/stores/dev'
import PasswordInput from '@/components/PasswordInput.vue'

/** Log in to the developer portal; a temporary password is changed here first */
const dev = useDev()
const router = useRouter()
const email = ref('')
const password = ref('')
const newPassword = ref('')
const busy = ref(false)
const error = ref('')

async function submit() {
  busy.value = true
  error.value = ''
  try {
    if (dev.developer?.must_change_password) {
      await dev.changePassword(password.value, newPassword.value)
    } else {
      await dev.login(email.value, password.value)
    }
    if (!dev.developer?.must_change_password) router.push({ name: 'dev-home' })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t log in.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="flex min-h-screen items-center justify-center bg-navy-950 px-4 py-12">
    <div class="absolute inset-0 overflow-hidden" aria-hidden="true">
      <div class="absolute -left-32 -top-32 h-96 w-96 rounded-full border-2 border-saffron-400/30" />
      <div class="absolute -bottom-40 -right-24 h-[28rem] w-[28rem] rounded-full bg-navy-800/50" />
    </div>
    <div class="relative w-full max-w-sm">
      <div class="flex items-center gap-3 text-white">
        <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-saffron-400 text-navy-950"><CommandLineIcon class="h-6 w-6" aria-hidden="true" /></span>
        <div>
          <p class="text-lg font-bold">SACCO platform</p>
          <p class="text-sm text-navy-200/70">Developer portal</p>
        </div>
      </div>
      <form class="card mt-8 space-y-4 p-6" novalidate @submit.prevent="submit">
        <template v-if="dev.developer?.must_change_password">
          <h1 class="text-xl font-bold">Choose your own password</h1>
          <p class="text-sm text-gray-600">Logged in as {{ dev.developer.email }} with a temporary password. Choose a new one to continue.</p>
          <div><label for="d-cur" class="label">Temporary password</label><PasswordInput id="d-cur" v-model="password" autocomplete="current-password" /></div>
          <div><label for="d-new" class="label">New password</label><PasswordInput id="d-new" v-model="newPassword" autocomplete="new-password" /></div>
        </template>
        <template v-else>
          <h1 class="text-xl font-bold">Log in</h1>
          <div><label for="d-email" class="label">Email</label><input id="d-email" v-model="email" type="email" autocomplete="username" class="input" /></div>
          <div><label for="d-pass" class="label">Password</label><PasswordInput id="d-pass" v-model="password" autocomplete="current-password" /></div>
        </template>
        <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
        <button type="submit" class="btn-primary w-full py-2.5" :disabled="busy">{{ busy ? 'Please wait…' : dev.developer?.must_change_password ? 'Save and continue' : 'Log in' }}</button>
      </form>
      <p class="mt-6 text-center text-xs text-navy-200/60">For the people who run this installation. SACCO members and office staff log in on their SACCO's page.</p>
    </div>
  </main>
</template>
