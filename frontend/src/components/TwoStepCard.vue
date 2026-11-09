<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { CheckCircleIcon, DevicePhoneMobileIcon, ShieldCheckIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { confetti } from '@/motion'
import Modal from '@/components/Modal.vue'
import PasswordInput from '@/components/PasswordInput.vue'

/** Turning two-step sign-in on and off for your own account */
const status = ref<{ enabled: boolean; enabled_at: string | null; recovery_left: number } | null>(null)
async function load() {
  status.value = await api.get('/me/two-step')
}
onMounted(load)

const setup = ref<{ secret: string; qr: string } | null>(null)
const code = ref('')
const error = ref('')
const recovery = ref<string[] | null>(null)
const disabling = ref(false)
const password = ref('')

async function start() {
  error.value = ''
  code.value = ''
  try {
    const res = await api.post<{ secret: string; uri: string }>('/me/two-step/start')
    const QR = (await import('qrcode')).default
    setup.value = { secret: res.secret, qr: await QR.toDataURL(res.uri, { width: 220, margin: 1 }) }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t start.'
  }
}
async function confirm() {
  error.value = ''
  try {
    const res = await api.post<{ recovery_codes: string[] }>('/me/two-step/confirm', { code: code.value })
    setup.value = null
    recovery.value = res.recovery_codes
    confetti(90)
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'That didn\'t work.'
  }
}
async function disable() {
  error.value = ''
  try {
    await api.post('/me/two-step/disable', { password: password.value })
    disabling.value = false
    password.value = ''
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'That didn\'t work.'
  }
}
function saveCodes() {
  const blob = new Blob([`Two-step sign-in recovery codes. Each works once.\n\n${recovery.value!.join('\n')}\n`], { type: 'text/plain' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = 'recovery-codes.txt'
  a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 2000)
}
</script>

<template>
  <section v-if="status" class="card p-5">
    <div class="flex flex-wrap items-center gap-4">
      <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl" :class="status.enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-saffron-100 text-saffron-700'"><ShieldCheckIcon class="h-6 w-6" aria-hidden="true" /></span>
      <div class="min-w-0 flex-1">
        <h2 class="font-semibold">Two-step sign-in</h2>
        <p class="text-sm text-gray-600">
          <template v-if="status.enabled">On for your account. {{ status.recovery_left }} recovery code{{ status.recovery_left === 1 ? '' : 's' }} left.</template>
          <template v-else>Off. Add a code from an app on your phone, so a stolen password alone can't open your account.</template>
        </p>
      </div>
      <button v-if="!status.enabled" type="button" class="btn-primary" @click="start"><DevicePhoneMobileIcon class="h-4 w-4" />Turn on</button>
      <button v-else type="button" class="btn-secondary" @click="disabling = true; error = ''">Turn off</button>
    </div>
    <p v-if="error && !setup && !disabling" class="mt-3 text-sm text-red-700" role="alert">{{ error }}</p>

    <Modal v-if="setup" title="Turn on two-step sign-in" @close="setup = null">
      <form class="space-y-4" @submit.prevent="confirm">
        <p class="text-sm text-gray-700">Open an authenticator app (Google Authenticator, Microsoft Authenticator or similar) and scan this code.</p>
        <img :src="setup.qr" alt="QR code to scan with your authenticator app" class="mx-auto h-52 w-52 rounded-xl border border-line bg-white p-2" />
        <p class="text-center text-xs text-gray-500">Can't scan? Enter this key: <span class="break-all font-mono font-semibold text-ink">{{ setup.secret }}</span></p>
        <div><label for="ts-code" class="label">The six digits your app shows</label><input id="ts-code" v-model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="input text-center font-mono text-xl tracking-[0.3em]" /></div>
        <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="code.length !== 6">Confirm and turn on</button>
      </form>
    </Modal>

    <Modal v-if="recovery" title="Save your recovery codes" @close="recovery = null">
      <p class="flex items-center gap-2 text-sm font-semibold text-emerald-700"><CheckCircleIcon class="h-5 w-5" aria-hidden="true" />Two-step sign-in is on.</p>
      <p class="mt-2 text-sm text-gray-700">If you lose your phone, each of these codes gets you in once. They are shown only now: save or print them and keep them somewhere safe.</p>
      <ul class="mt-4 grid grid-cols-2 gap-2 rounded-xl border border-line p-4 font-mono text-sm">
        <li v-for="c in recovery" :key="c">{{ c }}</li>
      </ul>
      <div class="mt-4 flex gap-2">
        <button type="button" class="btn-secondary flex-1" @click="saveCodes">Save as a file</button>
        <button type="button" class="btn-primary flex-1" @click="recovery = null">I've saved them</button>
      </div>
    </Modal>

    <Modal v-if="disabling" title="Turn off two-step sign-in" @close="disabling = false">
      <form class="space-y-4" @submit.prevent="disable">
        <div><label for="ts-pass" class="label">Your password</label><PasswordInput id="ts-pass" v-model="password" autocomplete="current-password" /></div>
        <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="!password">Turn off</button>
      </form>
    </Modal>
  </section>
</template>
