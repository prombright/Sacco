<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowsRightLeftIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { toast } from '@/toast'
import MemberPicker from '@/components/MemberPicker.vue'
import type { PickedMember } from '@/types'

/**
 * The round switch in the top bar: office staff who also save go to their own member account and
 * back without logging out. Until an office account is linked to its owner's member account, it
 * says how: the super admin links their own here, an admin asks the super admin.
 */
const auth = useAuth()
const router = useRouter()
const busy = ref(false)
const panel = ref(false)
const error = ref('')

const target = computed(() => auth.user?.switch_to ?? null)
/** Shown to office accounts (linked or not) and to a member account reached from the office */
const shown = computed(() => !!target.value || auth.isAdmin)
const label = computed(() =>
  target.value ? (target.value.to === 'member' ? 'Switch to my member account' : target.value.label) : 'Switch to my member account',
)

async function go() {
  if (!target.value) {
    panel.value = !panel.value
    error.value = ''
    return
  }
  busy.value = true
  try {
    const to = target.value.to
    await auth.switchAccount()
    await router.replace(to === 'member' ? { name: 'dashboard' } : { name: 'overview' })
    toast(to === 'member' ? 'Your member account' : 'Back in the office')
  } catch (e) {
    toast(e instanceof ApiError ? e.message : 'Couldn\'t switch. Please try again.', 'error')
  } finally {
    busy.value = false
  }
}

/** The super admin links their own office account to their member account, then switches */
async function link(m: PickedMember) {
  if (!auth.user) return
  busy.value = true
  error.value = ''
  try {
    await api.post(`/users/${auth.user.id}/link-member`, { member_id: m.id })
    await auth.load()
    panel.value = false
    toast(`Linked to ${m.full_name}. Tap the round button any time to switch.`)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t link the accounts.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div v-if="shown" class="relative">
    <button
      type="button"
      class="tap group inline-flex h-10 w-10 items-center justify-center rounded-full border shadow-sm transition"
      :class="
        target
          ? 'border-saffron-400 bg-saffron-400 text-navy-950 hover:bg-saffron-300'
          : 'border-line bg-surface text-gray-500 hover:border-navy-100 hover:text-navy-800'
      "
      :aria-label="label"
      :title="target ? `${label} (${target.full_name})` : label"
      :aria-expanded="target ? undefined : panel"
      :disabled="busy"
      @click="go"
    >
      <ArrowsRightLeftIcon class="h-5 w-5 transition duration-300 group-hover:rotate-180" :class="{ 'animate-spin': busy }" />
    </button>

    <!-- Not linked yet: how to make the switch work -->
    <template v-if="panel && !target">
      <div class="fixed inset-0 z-30" aria-hidden="true" @click="panel = false" />
      <div class="card absolute right-0 z-40 mt-2 w-[min(22rem,calc(100vw-2rem))] p-4 shadow-lift" role="dialog" aria-label="Switch to my member account">
        <div class="flex items-start justify-between gap-3">
          <p class="font-semibold">Switch to your member account</p>
          <button type="button" class="-mr-1 -mt-1 rounded p-1 text-gray-400 hover:text-ink" aria-label="Close" @click="panel = false"><XMarkIcon class="h-4 w-4" /></button>
        </div>
        <template v-if="auth.isSuperAdmin">
          <p class="mt-1 text-sm text-gray-600">Choose your own member account once. After that, this button switches between the office and your savings without logging out.</p>
          <p class="mt-1 text-xs text-gray-500">For other office staff who save: open their member account › Edit details › Role: Admin. They then use their own login and this button.</p>
          <p v-if="error" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
          <div class="mt-3"><MemberPicker @pick="link" /></div>
        </template>
        <template v-else>
          <p class="mt-1 text-sm text-gray-600">
            This office login (<span class="font-medium text-ink">{{ auth.user?.full_name }}</span>) isn't anyone's member account, so there is
            nothing to switch to.
          </p>
          <p class="mt-2 text-sm text-gray-600">
            If you also save with the SACCO, log in with <strong>your own member login</strong> instead. Once the super admin sets your role to
            Admin on your member account (Edit details › Role), this button takes you between your savings and the office.
          </p>
        </template>
      </div>
    </template>
  </div>
</template>
