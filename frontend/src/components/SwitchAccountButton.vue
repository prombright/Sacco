<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowsRightLeftIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { toast } from '@/toast'

/**
 * The round switch in the top bar: office staff who also save go to their own member account and
 * back without logging out. Until an office login is linked to its owner's member account (only the
 * system's developer links them), it says how to get there.
 */
const auth = useAuth()
const router = useRouter()
const busy = ref(false)
const panel = ref(false)

const target = computed(() => auth.user?.switch_to ?? null)
/** Shown to office accounts (linked or not) and to a member account reached from the office */
const shown = computed(() => !!target.value || auth.isAdmin)
const label = computed(() =>
  target.value ? (target.value.to === 'member' ? 'Switch to my member account' : target.value.label) : 'Switch to my member account',
)

async function go() {
  if (!target.value) {
    panel.value = !panel.value
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
        <p class="mt-1 text-sm text-gray-600">
          This office login (<span class="font-medium text-ink">{{ auth.user?.full_name }}</span>) isn't anyone's member account, so there is
          nothing to switch to.
        </p>
        <p class="mt-2 text-sm text-gray-600">
          If you also save with the SACCO, log in with <strong>your own member login</strong> instead. Once the system's developer gives
          you office rights, this button takes you between your savings and the office.
        </p>
      </div>
    </template>
  </div>
</template>
