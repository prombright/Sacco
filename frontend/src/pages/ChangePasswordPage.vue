<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import SaccoMark from '@/components/SaccoMark.vue'
import PasswordInput from '@/components/PasswordInput.vue'

const auth = useAuth()
const router = useRouter()

// First login (temporary password) or a change they chose to make
const firstTime = computed(() => auth.user?.must_change_password ?? false)

const current = ref('')
const next = ref('')
const confirm = ref('')
const error = ref('')
const busy = ref(false)

// Shown live as they type; the server checks the same rules (and their old passwords)
const rules = computed(() => [
  { label: 'At least 8 characters', ok: next.value.length >= 8 },
  { label: 'Letters and numbers', ok: /[a-z]/i.test(next.value) && /\d/.test(next.value) },
  { label: 'Both new passwords match', ok: next.value !== '' && next.value === confirm.value },
])
const ready = computed(() => current.value !== '' && rules.value.every((r) => r.ok))

async function submit() {
  error.value = ''
  busy.value = true
  try {
    await auth.changePassword(current.value, next.value)
    await router.replace('/')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Something went wrong. Please try again.'
  } finally {
    busy.value = false
  }
}

async function signOut() {
  await auth.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <main class="flex min-h-screen items-center justify-center px-4 py-10">
    <div class="w-full max-w-sm">
      <div class="mb-8 flex flex-col items-center text-center">
        <SaccoMark :sacco="auth.sacco" size="lg" />
        <h1 class="mt-4 text-xl font-semibold">{{ firstTime ? 'Choose your own password' : 'Change your password' }}</h1>
        <p v-if="firstTime" class="mt-1 text-sm text-gray-600">
          Welcome, {{ auth.user?.full_name }}. You logged in with a temporary password; replace it with one only you know.
        </p>
      </div>

      <form class="card space-y-4 p-6" novalidate @submit.prevent="submit">
        <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

        <div>
          <label for="current" class="label">{{ firstTime ? 'Temporary password' : 'Current password' }}</label>
          <PasswordInput id="current" v-model="current" autocomplete="current-password" />
        </div>
        <div>
          <label for="new" class="label">New password</label>
          <PasswordInput id="new" v-model="next" autocomplete="new-password" />
        </div>
        <div>
          <label for="confirm" class="label">New password again</label>
          <PasswordInput id="confirm" v-model="confirm" autocomplete="new-password" />
        </div>

        <ul class="space-y-1 text-sm" aria-live="polite">
          <li v-for="rule in rules" :key="rule.label" class="flex items-center gap-2" :class="rule.ok ? 'text-green-700' : 'text-gray-500'">
            <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
              <path v-if="rule.ok" fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" />
              <circle v-else cx="10" cy="10" r="3" />
            </svg>
            {{ rule.label }}
          </li>
        </ul>

        <button type="submit" class="btn-primary w-full" :disabled="busy || !ready">
          {{ busy ? 'Saving…' : 'Save password' }}
        </button>
      </form>

      <p class="mt-6 text-center text-sm">
        <RouterLink v-if="!firstTime" to="/" class="font-medium text-gray-600 underline hover:text-gray-900">Back</RouterLink>
        <button v-else type="button" class="font-medium text-gray-600 underline hover:text-gray-900" @click="signOut">Log out</button>
      </p>
    </div>
  </main>
</template>
