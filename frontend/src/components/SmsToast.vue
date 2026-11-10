<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'
import { useRouter } from 'vue-router'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import { useAuth } from '@/stores/auth'
import { useMessages, type Message } from '@/stores/messages'
import SaccoMark from '@/components/SaccoMark.vue'

/** A new message slides in from the top like a text message on a phone, and goes after a while */
const auth = useAuth()
const messages = useMessages()
const router = useRouter()
let hide: ReturnType<typeof setTimeout> | undefined

function pause() {
  clearTimeout(hide)
}
function schedule() {
  clearTimeout(hide)
  hide = setTimeout(() => (messages.banner = null), 8000)
}
watch(() => messages.banner, (m) => m && schedule())
onBeforeUnmount(() => clearTimeout(hide))

async function open(m: Message) {
  messages.banner = null
  await messages.markRead([m.id])
  router.push(m.link || { name: 'messages' })
}
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-from-class="-translate-y-full opacity-0"
      enter-active-class="transition duration-500 ease-out"
      leave-to-class="-translate-y-full opacity-0"
      leave-active-class="transition duration-300 ease-in"
    >
      <div
        v-if="messages.banner"
        class="fixed inset-x-0 top-0 z-50 flex justify-center px-3 pt-[max(0.75rem,env(safe-area-inset-top))]"
        role="status"
        aria-live="polite"
      >
        <div
          class="flex w-full max-w-md cursor-pointer items-start gap-3 rounded-2xl border border-line bg-surface/95 p-3.5 shadow-lift backdrop-blur"
          @mouseenter="pause"
          @mouseleave="schedule"
          @click="open(messages.banner!)"
        >
          <SaccoMark :sacco="auth.sacco" size="sm" class="shrink-0" />
          <div class="min-w-0 flex-1">
            <p class="flex items-center justify-between gap-2 text-xs text-gray-500">
              <span class="truncate font-semibold uppercase tracking-wide text-gray-700">{{ auth.sacco?.short_name || auth.sacco?.name || 'SACCO' }}</span>
              <span class="shrink-0">now</span>
            </p>
            <p class="mt-0.5 text-sm font-semibold text-ink">{{ messages.banner.title }}</p>
            <p class="line-clamp-2 text-sm text-gray-600">{{ messages.banner.body }}</p>
          </div>
          <button type="button" class="-m-1 shrink-0 rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700" aria-label="Close" @click.stop="messages.banner = null">
            <XMarkIcon class="h-4 w-4" />
          </button>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
