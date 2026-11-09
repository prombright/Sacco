<script setup lang="ts">
import { computed, ref } from 'vue'
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
// Only asked for when this web address doesn't belong to a SACCO (each SACCO has its own address)
const otherSacco = ref(!auth.sacco)
const saccoCode = ref('')
const error = ref('')
const busy = ref(false)

/** The SACCO's own photo (e.g. its members with the banner), if it has one */
const photo = computed(() => (auth.sacco?.login_image ? import.meta.env.BASE_URL + auth.sacco.login_image : null))
const slogan = computed(() => auth.sacco?.slogan || 'Save together. Grow together.')

const needCode = ref(false)
const code = ref('')

// Admins start at the office overview, members at their own dashboard (or the page they asked for)
async function goIn() {
  const home = auth.isAdmin ? '/office' : '/'
  const next = typeof route.query.next === 'string' && route.query.next.startsWith('/') && !route.query.next.startsWith('//') ? route.query.next : home
  await router.replace(next)
}

async function submitCode() {
  error.value = ''
  busy.value = true
  try {
    await auth.twoStep(code.value.trim())
    await goIn()
  } catch (e) {
    const err = e as ApiError
    error.value = err.message
    code.value = ''
    if (err.errors?.restart) {
      needCode.value = false
      password.value = ''
    }
  } finally {
    busy.value = false
  }
}

async function submit() {
  error.value = ''
  busy.value = true
  try {
    needCode.value = await auth.login(login.value.trim(), password.value, otherSacco.value ? saccoCode.value.trim() : undefined)
    if (!needCode.value) await goIn()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Something went wrong. Please try again.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="grid min-h-screen lg:grid-cols-[1.15fr_1fr]">
    <!-- The SACCO's photo, with its name and slogan -->
    <section class="relative hidden overflow-hidden bg-navy-950 lg:block" aria-label="Welcome">
      <img v-if="photo" :src="photo" alt="" class="absolute inset-0 h-full w-full object-cover object-[68%_center]" />
      <div v-else class="absolute -right-28 -top-28 h-[28rem] w-[28rem] rounded-full border-[3px] border-saffron-400/90" aria-hidden="true" />
      <!-- Deep blue from the bottom so the words read over the photo -->
      <div class="absolute inset-0 bg-gradient-to-t from-navy-950 from-[18%] via-navy-950/80 via-[48%] to-navy-950/0 to-[78%]" aria-hidden="true" />
      <div class="absolute inset-x-0 top-0 h-36 bg-gradient-to-b from-navy-950/70 to-transparent" aria-hidden="true" />
      <div class="relative flex h-full flex-col p-10 xl:p-12">
        <div class="flex items-center gap-3">
          <SaccoMark :sacco="auth.sacco" />
          <p class="text-lg font-bold text-white drop-shadow">{{ auth.sacco?.name }}</p>
        </div>
        <div class="mt-auto max-w-xl text-white">
          <p class="text-sm font-semibold uppercase tracking-[0.2em] text-saffron-300">Savings &amp; credit</p>
          <h2 class="mt-3 text-4xl font-bold leading-tight xl:text-5xl">{{ slogan }}</h2>
          <p class="mt-4 max-w-md text-navy-100/90">
            See your savings and shares, apply for a loan, guarantee a colleague and ask for a withdrawal, any time, from your phone or computer.
          </p>
          <ul class="mt-8 flex flex-wrap gap-3 text-sm">
            <li class="rounded-full bg-white/10 px-4 py-2 backdrop-blur"><span class="font-bold text-saffron-300">24/7</span> your balances</li>
            <li class="rounded-full bg-white/10 px-4 py-2 backdrop-blur"><span class="font-bold text-saffron-300">Online</span> loan requests</li>
            <li class="rounded-full bg-white/10 px-4 py-2 backdrop-blur"><span class="font-bold text-saffron-300">Every</span> entry recorded</li>
          </ul>
        </div>
      </div>
    </section>

    <!-- Sign in -->
    <section class="flex flex-col">
      <!-- Phones: the photo as a band at the top -->
      <div class="relative h-48 overflow-hidden bg-navy-950 sm:h-60 lg:hidden">
        <img v-if="photo" :src="photo" alt="" class="absolute inset-0 h-full w-full object-cover object-[50%_35%]" />
        <div class="absolute inset-0 bg-gradient-to-t from-navy-950/90 via-navy-950/30 to-transparent" aria-hidden="true" />
        <div class="absolute inset-x-0 bottom-0 flex items-end gap-3 p-5">
          <SaccoMark :sacco="auth.sacco" />
          <div class="text-white">
            <p class="font-bold leading-tight">{{ auth.sacco?.name }}</p>
            <p class="text-sm text-navy-100/90">{{ slogan }}</p>
          </div>
        </div>
      </div>

      <div class="flex flex-1 items-start justify-center px-4 py-8 sm:py-12 lg:items-center">
        <div class="w-full max-w-sm">
          <h1 class="text-3xl font-bold">Welcome back</h1>
          <p class="mt-1 text-gray-600">Sign in to {{ auth.sacco?.name ?? 'your SACCO' }}.</p>

          <form v-if="needCode" class="mt-8 space-y-4" novalidate @submit.prevent="submitCode">
            <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
            <div>
              <label for="code" class="label">Code from your authenticator app</label>
              <input id="code" v-model="code" class="input py-3 text-center font-mono text-xl tracking-[0.25em]" autocomplete="one-time-code" autofocus placeholder="000000" />
              <p class="mt-1.5 text-xs text-gray-500">Lost your phone? Enter one of your recovery codes instead.</p>
            </div>
            <button type="submit" class="btn-primary w-full py-3 text-base" :disabled="busy || !code">{{ busy ? 'Checking…' : 'Continue' }}</button>
            <button type="button" class="w-full text-sm font-semibold text-gray-600 hover:text-ink" @click="needCode = false; code = ''; error = ''">Back</button>
          </form>

          <form v-else class="mt-8 space-y-4" novalidate @submit.prevent="submit">
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

            <button type="submit" class="btn-primary w-full py-3 text-base" :disabled="busy || !login || !password">
              {{ busy ? 'Signing in…' : 'Sign in' }}
            </button>
          </form>

          <p class="mt-6 text-sm text-gray-600">Forgot your password? Ask your SACCO office to reset it.</p>
          <RouterLink :to="{ name: 'home' }" class="mt-2 inline-block text-sm font-semibold text-brand hover:underline">About the SACCO, rates and loan calculator</RouterLink>
        </div>
      </div>
    </section>
  </main>
</template>
