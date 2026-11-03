<script setup lang="ts">
import { computed } from 'vue'
import type { User } from '@/types'
import { formatDate } from '@/money'

/** A member's personal details (KYC), read-only */
const props = defineProps<{ user: User }>()

const age = computed(() => {
  if (!props.user.date_of_birth) return null
  const b = new Date(props.user.date_of_birth + 'T00:00:00')
  const now = new Date()
  return now.getFullYear() - b.getFullYear() - (now < new Date(now.getFullYear(), b.getMonth(), b.getDate()) ? 1 : 0)
})

const rows = computed<[string, string | null][]>(() => [
  ['Account number', props.user.account_no],
  ['Member since', props.user.joined_on ? formatDate(props.user.joined_on) : null],
  ['Employee number', props.user.employee_no],
  ['Email', props.user.email],
  ['Phone', props.user.phone],
  ['National ID', props.user.national_id],
  ['Date of birth', props.user.date_of_birth ? `${formatDate(props.user.date_of_birth)} (${age.value})` : null],
  ['Gender', props.user.gender ? props.user.gender[0].toUpperCase() + props.user.gender.slice(1) : null],
  ['Department', props.user.department],
  ['Position', props.user.position],
  ['Address', props.user.address],
])
const missing = computed(() => rows.value.filter(([, v]) => !v).length)
</script>

<template>
  <section class="card p-5">
    <h2 class="font-semibold">Details</h2>
    <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
      <div v-for="[label, value] in rows" :key="label">
        <dt class="text-xs text-gray-500">{{ label }}</dt>
        <dd :class="value ? 'font-medium' : 'text-gray-400'">{{ value || 'Not recorded' }}</dd>
      </div>
    </dl>
    <p v-if="missing > 3" class="mt-4 text-xs text-gray-500">{{ missing }} details aren't recorded yet. An admin can add them with "Edit details".</p>
  </section>
</template>
