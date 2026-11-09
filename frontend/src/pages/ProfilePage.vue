<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, ApiError } from '@/api'
import type { NextOfKin, User } from '@/types'
import MemberDetails from '@/components/MemberDetails.vue'
import NextOfKinEditor from '@/components/NextOfKinEditor.vue'
import TwoStepCard from '@/components/TwoStepCard.vue'

/** A member's own details and next of kin */
const user = ref<User | null>(null)
const kin = ref<NextOfKin[]>([])
const error = ref('')

onMounted(async () => {
  try {
    const res = await api.get<{ user: User; next_of_kin: NextOfKin[] }>('/me/profile')
    user.value = res.user
    kin.value = res.next_of_kin
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load your profile.'
  }
})
</script>

<template>
  <div class="max-w-4xl">
    <h1 class="text-2xl font-bold">My profile</h1>
    <p class="mt-1 text-sm text-gray-600">Your details as the SACCO has them. If something is wrong, tell the SACCO office.</p>
    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
    <div v-if="user" class="mt-6 grid gap-6 lg:grid-cols-2">
      <MemberDetails :user="user" />
      <NextOfKinEditor :people="kin" save-url="/me/next-of-kin" @saved="kin = $event" />
    </div>
    <TwoStepCard class="mt-6" />
  </div>
</template>
