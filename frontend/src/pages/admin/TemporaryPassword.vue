<script setup lang="ts">
import { ref } from 'vue'
import type { User } from '@/types'
import Modal from '@/components/Modal.vue'

/** Shows a new temporary password once - it can't be looked up again later */
const props = defineProps<{ user: User; password: string }>()
const emit = defineEmits<{ close: [] }>()

const copied = ref(false)
async function copy() {
  const login = props.user.email ?? props.user.employee_no
  await navigator.clipboard.writeText(`Sign in: ${login}\nTemporary password: ${props.password}`)
  copied.value = true
}
</script>

<template>
  <Modal title="Temporary password" @close="emit('close')">
    <p class="text-sm text-gray-700">
      Give this to <strong class="font-semibold">{{ user.full_name }}</strong> privately. They'll be asked to choose their own password the first time they sign in.
    </p>

    <dl class="mt-4 space-y-3 rounded-xl bg-gray-50 p-4">
      <div>
        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Sign in with</dt>
        <dd class="mt-0.5 text-sm">{{ user.email ?? user.employee_no }}<template v-if="user.email && user.employee_no"> or {{ user.employee_no }}</template></dd>
      </div>
      <div>
        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Temporary password</dt>
        <dd class="mt-0.5 select-all font-mono text-2xl font-semibold tracking-wider">{{ password }}</dd>
      </div>
    </dl>

    <p class="mt-3 text-sm text-amber-700">This password won't be shown again. If it's lost, reset the password to get a new one.</p>

    <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
      <button type="button" class="btn-secondary" @click="copy">{{ copied ? 'Copied' : 'Copy' }}</button>
      <button type="button" class="btn-primary" @click="emit('close')">Done</button>
    </div>
  </Modal>
</template>
