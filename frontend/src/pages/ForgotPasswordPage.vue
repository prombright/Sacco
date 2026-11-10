<script setup lang="ts">
import { ref } from 'vue'
import { useRoute } from 'vue-router'
import { EnvelopeIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { t } from '@/i18n'
import { useAuth } from '@/stores/auth'
import SaccoMark from '@/components/SaccoMark.vue'

/** "Forgot password": a link to the email on the account, or ask the office */
const auth = useAuth()
const route = useRoute()
const email = ref('')
const saccoCode = ref(String(route.query.sacco ?? ''))
const busy = ref(false)
const done = ref('')
const error = ref('')

async function submit() {
  error.value = ''
  busy.value = true
  try {
    const res = await api.post<{ message: string }>('/auth/forgot', { email: email.value.trim(), sacco: saccoCode.value.trim() || undefined })
    done.value = res.message
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Something went wrong. Please try again.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="flex min-h-screen items-center justify-center bg-paper px-4 py-10">
    <div class="w-full max-w-md">
      <div class="mb-6 flex items-center gap-3">
        <SaccoMark :sacco="auth.sacco" />
        <p class="font-semibold">{{ auth.sacco?.name ?? 'SACCO' }}</p>
      </div>
      <div class="card p-6 sm:p-8">
        <h1 class="text-2xl font-bold">{{ t('Forgot your password?') }}</h1>
        <template v-if="!done">
          <p class="mt-2 text-sm text-gray-600">Enter the email on your account. We'll send it a link to choose a new password.</p>
          <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
              <label for="email" class="label">{{ t('Email') }}</label>
              <input id="email" v-model="email" type="email" class="input" autocomplete="email" required />
            </div>
            <div v-if="!auth.sacco || route.query.sacco">
              <label for="sacco" class="label">SACCO code</label>
              <input id="sacco" v-model="saccoCode" class="input" autocomplete="organization" placeholder="e.g. smacon" />
            </div>
            <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
            <button type="submit" class="btn-primary w-full py-3" :disabled="busy || !email">{{ busy ? t('Sending…') : t('Send me a link') }}</button>
          </form>
        </template>
        <div v-else class="mt-4 flex gap-3 rounded-xl bg-navy-50 p-4 text-sm text-navy-900" role="status">
          <EnvelopeIcon class="h-6 w-6 shrink-0" aria-hidden="true" />
          <p>{{ done }}</p>
        </div>
        <div class="mt-6 rounded-xl border border-line p-4 text-sm text-gray-600">
          <p class="font-medium text-ink">{{ t('No email on your account?') }}</p>
          <p class="mt-1">Ask your SACCO office or the system admin. They'll give you a temporary password, and you choose your own when you sign in.</p>
        </div>
        <RouterLink :to="{ name: 'login' }" class="mt-6 inline-block text-sm font-semibold text-brand hover:underline">← {{ t('Back to sign in') }}</RouterLink>
      </div>
    </div>
  </main>
</template>
