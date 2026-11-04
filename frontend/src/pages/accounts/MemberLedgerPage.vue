<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { DocumentTextIcon } from '@heroicons/vue/24/outline'
import type { PickedMember } from '@/types'
import MemberPicker from '@/components/MemberPicker.vue'

/** A member's ledger: every savings and shares entry and their loans, for a period, ready to print */
const router = useRouter()
const today = new Date().toLocaleDateString('en-CA')
const from = ref(`${today.slice(0, 4)}-01-01`)
const to = ref(today)
const member = ref<PickedMember | null>(null)

function open() {
  if (member.value) router.push({ name: 'member-statement', params: { id: member.value.id }, query: { from: from.value, to: to.value } })
}
</script>

<template>
  <div>
    <p class="eyebrow">Member reports</p>
    <h1 class="mt-1 text-2xl font-bold">Member ledger</h1>
    <p class="mt-1 max-w-3xl text-gray-600">A member's savings and shares, entry by entry with running balances, and their loans. It opens ready to print or save as PDF.</p>

    <form class="card mt-5 grid max-w-3xl gap-4 p-5 sm:grid-cols-2" @submit.prevent="open">
      <div class="sm:col-span-2">
        <p class="label">Member</p>
        <p v-if="member" class="flex items-center justify-between rounded-xl border border-line p-3">
          <span class="font-semibold">{{ member.full_name }} <span class="font-mono text-xs text-gray-500">{{ member.account_no }}</span></span>
          <button type="button" class="text-sm font-semibold text-brand hover:underline" @click="member = null">Change</button>
        </p>
        <MemberPicker v-else placeholder="Search by name or account number" @pick="(m) => (member = m)" />
      </div>
      <div><label for="l-from" class="label">From</label><input id="l-from" v-model="from" type="date" class="input" /></div>
      <div><label for="l-to" class="label">To</label><input id="l-to" v-model="to" type="date" class="input" /></div>
      <button type="submit" class="btn-primary sm:col-span-2 sm:w-max" :disabled="!member"><DocumentTextIcon class="h-4 w-4" />Open the ledger</button>
    </form>
  </div>
</template>
