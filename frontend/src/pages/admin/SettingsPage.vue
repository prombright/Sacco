<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'

interface Setting {
  key: string
  label: string
  value: string
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
</script>

<template>
  <div class="max-w-2xl">
    <h1 class="text-xl font-semibold">SACCO rules</h1>
    <p class="mt-1 text-sm text-gray-600">
      Set by the committee.
      <template v-if="!auth.isSuperAdmin">Only a super admin can change them.</template>
    </p>

    <form class="card mt-5 space-y-4 p-5" novalidate @submit.prevent="save">
      <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
      <div v-if="message" class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800" role="status">{{ message }}</div>

      <div v-for="s in settings" :key="s.key" class="grid items-center gap-2 sm:grid-cols-[1fr_12rem]">
        <label :for="s.key" class="text-sm font-medium text-gray-700">{{ s.label }}</label>
        <div>
          <input :id="s.key" v-model="values[s.key]" class="input tabular-nums" inputmode="decimal" :disabled="!auth.isSuperAdmin" />
          <p v-if="errors[s.key]" class="field-error">{{ errors[s.key] }}</p>
        </div>
      </div>

      <div v-if="auth.isSuperAdmin" class="flex justify-end pt-2">
        <button type="submit" class="btn-primary" :disabled="busy">{{ busy ? 'Saving…' : 'Save rules' }}</button>
      </div>
    </form>
  </div>
</template>
