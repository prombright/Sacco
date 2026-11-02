<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import SaccoMark from '@/components/SaccoMark.vue'
import PasswordInput from '@/components/PasswordInput.vue'

const auth = useAuth()
const route = useRoute()
const router = useRouter()

const login = ref('')
const password = ref('')
// Only needed when this web address doesn't already belong to the SACCO they want
const otherSacco = ref(!auth.sacco)
const saccoCode = ref('')
const error = ref('')
const busy = ref(false)

async function submit() {
  error.value = ''
  busy.value = true
  try {
    await auth.login(login.value.trim(), password.value, otherSacco.value ? saccoCode.value.trim() : undefined)
    const next = typeof route.query.next === 'string' && route.query.next.startsWith('/') ? route.query.next : '/'
    await router.replace(next)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Something went wrong. Please try again.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="flex min-h-screen items-center justify-center px-4 py-10">
    <div class="w-full max-w-sm">
      <div class="mb-8 flex flex-col items-center text-center">
        <SaccoMark :sacco="auth.sacco" size="lg" />
        <h1 class="mt-4 text-xl font-semibold">{{ auth.sacco?.name ?? 'SACCO' }}</h1>
        <p class="mt-1 text-sm text-gray-600">Sign in to see your savings, shares and loans.</p>
      </div>

      <form class="card space-y-4 p-6" novalidate @submit.prevent="submit">
        <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

        <div v-if="otherSacco">
          <label for="sacco" class="label">SACCO code</label>
          <input id="sacco" v-model="saccoCode" class="input" autocomplete="organization" placeholder="e.g. smacon" />
        </div>

        <div>
          <label for="login" class="label">Email or employee number</label>
          <input id="login" v-model="login" class="input" autocomplete="username" autocapitalize="none" spellcheck="false" />
        </div>

        <div>
          <label for="password" class="label">Password</label>
          <PasswordInput id="password" v-model="password" autocomplete="current-password" />
        </div>

        <button type="submit" class="btn-primary w-full" :disabled="busy || !login || !password">
          {{ busy ? 'Signing in…' : 'Sign in' }}
        </button>
      </form>

      <p class="mt-6 text-center text-sm text-gray-600">
        Forgot your password? Ask your SACCO office to reset it.
      </p>
      <p v-if="!otherSacco" class="mt-2 text-center text-sm">
        <button type="button" class="font-medium text-gray-600 underline hover:text-gray-900" @click="otherSacco = true">
          Signing in to a different SACCO?
        </button>
      </p>
    </div>
  </main>
</template>
