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
    // Admins start at the office overview, members at their own dashboard
    const home = auth.isAdmin ? '/office' : '/'
    const next = typeof route.query.next === 'string' && route.query.next.startsWith('/') ? route.query.next : home
    await router.replace(next)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Something went wrong. Please try again.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="grid min-h-screen lg:grid-cols-2">
    <!-- Welcome panel -->
    <section class="relative hidden overflow-hidden bg-navy-950 p-12 text-white lg:flex lg:flex-col">
      <div class="absolute -right-28 -top-28 h-[28rem] w-[28rem] rounded-full border-[3px] border-saffron-400/90" aria-hidden="true" />
      <div class="absolute -bottom-32 -left-20 h-80 w-80 rounded-full bg-navy-800" aria-hidden="true" />
      <div class="relative flex items-center gap-3">
        <SaccoMark :sacco="auth.sacco" />
        <p class="text-lg font-bold">{{ auth.sacco?.short_name || auth.sacco?.name }}</p>
      </div>
      <div class="relative mt-auto max-w-md">
        <p class="text-sm font-semibold uppercase tracking-widest text-saffron-300">Savings &amp; credit</p>
        <h2 class="mt-3 text-4xl font-bold leading-tight">Save together.<br />Grow together.</h2>
        <p class="mt-4 text-navy-100/80">
          See your savings and shares, apply for a loan, guarantee a colleague and ask for a withdrawal: any time, from your phone or computer.
        </p>
        <ul class="mt-8 grid grid-cols-3 gap-4 text-sm">
          <li class="rounded-xl bg-white/5 p-3"><span class="block text-xl font-bold text-saffron-300">24/7</span>Your balances</li>
          <li class="rounded-xl bg-white/5 p-3"><span class="block text-xl font-bold text-saffron-300">Online</span>Loan requests</li>
          <li class="rounded-xl bg-white/5 p-3"><span class="block text-xl font-bold text-saffron-300">Every</span>entry recorded</li>
        </ul>
      </div>
      <p class="relative mt-12 text-xs text-navy-200/60">{{ auth.sacco?.name }}</p>
    </section>

    <!-- Sign in -->
    <section class="flex items-center justify-center px-4 py-10">
      <div class="w-full max-w-sm">
        <div class="mb-8 lg:hidden">
          <SaccoMark :sacco="auth.sacco" size="lg" />
        </div>
        <h1 class="text-2xl font-bold">Welcome back</h1>
        <p class="mt-1 text-gray-600">Sign in to {{ auth.sacco?.name ?? 'your SACCO' }}.</p>

        <form class="mt-8 space-y-4" novalidate @submit.prevent="submit">
          <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

          <div v-if="otherSacco">
            <label for="sacco" class="label">SACCO code</label>
            <input id="sacco" v-model="saccoCode" class="input" autocomplete="organization" placeholder="e.g. smacon" />
          </div>

          <div>
            <label for="login" class="label">Email or employee number</label>
            <input id="login" v-model="login" class="input py-2.5" autocomplete="username" autocapitalize="none" spellcheck="false" />
          </div>

          <div>
            <label for="password" class="label">Password</label>
            <PasswordInput id="password" v-model="password" autocomplete="current-password" />
          </div>

          <button type="submit" class="btn-primary w-full py-2.5" :disabled="busy || !login || !password">
            {{ busy ? 'Signing in…' : 'Sign in' }}
          </button>
        </form>

        <p class="mt-6 text-sm text-gray-600">Forgot your password? Ask your SACCO office to reset it.</p>
        <p v-if="!otherSacco" class="mt-2 text-sm">
          <button type="button" class="font-medium text-gray-600 underline hover:text-gray-900" @click="otherSacco = true">
            Signing in to a different SACCO?
          </button>
        </p>
      </div>
    </section>
  </main>
</template>
