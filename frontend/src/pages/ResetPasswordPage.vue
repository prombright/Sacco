<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CheckCircleIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { t } from '@/i18n'
import { useAuth } from '@/stores/auth'
import SaccoMark from '@/components/SaccoMark.vue'
import PasswordInput from '@/components/PasswordInput.vue'

/** The page the reset link in the email opens: choose a new password */
const auth = useAuth()
const route = useRoute()
const router = useRouter()
const token = String(route.query.token ?? '')
const sacco = String(route.query.sacco ?? '')
const state = ref<'checking' | 'ready' | 'expired' | 'done'>('checking')
const firstName = ref('')
const password = ref('')
const again = ref('')
const busy = ref(false)
const error = ref('')
const mismatch = computed(() => again.value !== '' && again.value !== password.value)

onMounted(async () => {
  try {
    const res = await api.get<{ valid: boolean; first_name: string | null }>(`/auth/reset?token=${encodeURIComponent(token)}&sacco=${encodeURIComponent(sacco)}`)
    state.value = res.valid ? 'ready' : 'expired'
    firstName.value = res.first_name ?? ''
  } catch {
    state.value = 'expired'
  }
})

async function submit() {
  if (mismatch.value) return
  error.value = ''
  busy.value = true
  try {
    await api.post('/auth/reset', { token, sacco: sacco || undefined, password: password.value })
    state.value = 'done'
  } catch (e) {
    if (e instanceof ApiError && e.status === 410) state.value = 'expired'
    else error.value = e instanceof ApiError ? e.message : 'Something went wrong. Please try again.'
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
        <p v-if="state === 'checking'" class="py-8 text-center text-sm text-gray-500">{{ t('Loading…') }}</p>

        <template v-else-if="state === 'ready'">
          <h1 class="text-2xl font-bold">{{ t('Choose a new password') }}</h1>
          <p class="mt-2 text-sm text-gray-600"><template v-if="firstName">Hello {{ firstName }}. </template>At least 8 characters, with letters and numbers.</p>
          <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
              <label for="new-password" class="label">{{ t('New password') }}</label>
              <PasswordInput id="new-password" v-model="password" autocomplete="new-password" />
            </div>
            <div>
              <label for="again" class="label">{{ t('Type it again') }}</label>
              <PasswordInput id="again" v-model="again" autocomplete="new-password" :invalid="mismatch" />
              <p v-if="mismatch" class="mt-1 text-sm text-red-700">The two don't match.</p>
            </div>
            <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
            <button type="submit" class="btn-primary w-full py-3" :disabled="busy || !password || !again || mismatch">{{ busy ? t('Saving…') : t('Save my new password') }}</button>
          </form>
        </template>

        <template v-else-if="state === 'done'">
          <CheckCircleIcon class="h-10 w-10 text-emerald-600" aria-hidden="true" />
          <h1 class="mt-3 text-2xl font-bold">{{ t('Password changed') }}</h1>
          <p class="mt-2 text-sm text-gray-600">Log in with your new password. You've been logged out everywhere else.</p>
          <button type="button" class="btn-primary mt-6 w-full py-3" @click="router.push({ name: 'login' })">{{ t('Log in') }}</button>
        </template>

        <template v-else>
          <h1 class="text-2xl font-bold">This link no longer works</h1>
          <p class="mt-2 text-sm text-gray-600">Reset links work once, for an hour. Ask for a new one, or ask your SACCO office for a temporary password.</p>
          <RouterLink :to="{ name: 'forgot-password', query: sacco ? { sacco } : {} }" class="btn-primary mt-6 w-full py-3">{{ t('Send me a new link') }}</RouterLink>
        </template>
      </div>
    </div>
  </main>
</template>
