<script setup lang="ts">
import SaccoMark from '@/components/SaccoMark.vue'
import { onMounted, ref } from 'vue'
import { api, ApiError, http } from '@/api'
import { useAuth } from '@/stores/auth'
import LoanProducts from './LoanProducts.vue'

interface Setting {
  key: string
  label: string
  value: string
  /** A choice from a list instead of a number */
  options: Record<string, string> | null
}

const auth = useAuth()
const settings = ref<Setting[]>([])
const values = ref<Record<string, string>>({})
const errors = ref<Record<string, string>>({})
const message = ref('')
const error = ref('')
const busy = ref(false)

function apply(list: Setting[]) {
  settings.value = list
  values.value = Object.fromEntries(list.map((s) => [s.key, s.value]))
}

onMounted(async () => {
  try {
    apply((await api.get<{ settings: Setting[] }>('/settings')).settings)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the settings.'
  }
})

async function save() {
  errors.value = {}
  error.value = ''
  message.value = ''
  busy.value = true
  try {
    apply((await api.patch<{ settings: Setting[] }>('/settings', values.value)).settings)
    message.value = 'Saved. New loan applications use these rules; existing loans keep theirs.'
  } catch (e) {
    if (e instanceof ApiError) {
      errors.value = e.errors
      error.value = e.message
    }
  } finally {
    busy.value = false
  }
}

// The SACCO's logo
const logoError = ref('')
async function uploadLogo(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  logoError.value = ''
  const body = new FormData()
  body.append('logo', file)
  try {
    const res = await http.post('/settings/logo', body, { headers: { 'Content-Type': 'multipart/form-data' } })
    auth.sacco && (auth.sacco.logo_url = res.data.data.logo_url)
  } catch (err) {
    logoError.value = (err as { response?: { data?: { message?: string } } }).response?.data?.message ?? 'The logo couldn\'t be uploaded.'
  }
}
async function removeLogo() {
  await api.delete('/settings/logo')
  auth.sacco && (auth.sacco.logo_url = null)
}
</script>

<template>
  <div class="max-w-4xl space-y-6">
    <h1 class="text-2xl font-bold">SACCO rules</h1>
    <p class="mt-1 text-sm text-gray-600">
      Set by the committee.
      <template v-if="!auth.isSuperAdmin">Only a super admin can change them.</template>
    </p>

    <section v-if="auth.isSuperAdmin" class="card mb-6 flex flex-wrap items-center gap-4 p-5">
      <SaccoMark :sacco="auth.sacco" size="lg" />
      <div class="min-w-[14rem] flex-1">
        <h2 class="font-semibold">The SACCO's logo</h2>
        <p class="text-sm text-gray-600">Shown in the app and on every letterhead, receipt, card and certificate. A PNG or JPG under 1 MB, ideally square.</p>
        <p v-if="logoError" class="mt-1 text-sm text-red-700" role="alert">{{ logoError }}</p>
      </div>
      <label class="btn-primary cursor-pointer">
        {{ auth.sacco?.logo_url ? 'Change the logo' : 'Upload the logo' }}
        <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="uploadLogo" />
      </label>
      <button v-if="auth.sacco?.logo_url" type="button" class="btn-ghost" @click="removeLogo">Remove</button>
    </section>

    <LoanProducts />

    <form class="card space-y-4 p-5" novalidate @submit.prevent="save">
      <h2 class="font-semibold">General rules</h2>
      <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
      <div v-if="message" class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800" role="status">{{ message }}</div>

      <div v-for="s in settings" :key="s.key" class="grid items-center gap-2 sm:grid-cols-[1fr_20rem]">
        <label :for="s.key" class="text-sm font-medium text-gray-700">{{ s.label }}</label>
        <div>
          <select v-if="s.options" :id="s.key" v-model="values[s.key]" class="input" :disabled="!auth.isSuperAdmin">
            <option v-for="(label, value) in s.options" :key="value" :value="value">{{ label }}</option>
          </select>
          <input v-else :id="s.key" v-model="values[s.key]" class="input tabular-nums" inputmode="decimal" :disabled="!auth.isSuperAdmin" />
          <p v-if="errors[s.key]" class="field-error">{{ errors[s.key] }}</p>
        </div>
      </div>

      <div v-if="auth.isSuperAdmin" class="flex justify-end pt-2">
        <button type="submit" class="btn-primary" :disabled="busy">{{ busy ? 'Saving…' : 'Save rules' }}</button>
      </div>
    </form>
  </div>
</template>
