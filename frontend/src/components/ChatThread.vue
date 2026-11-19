<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'

/**
 * A conversation as bubbles: the viewer's own side on the right, the other side on the left,
 * a day line when the date changes, and a box to write in (Enter sends, Shift+Enter a new line).
 */
export interface ChatMessage { id: number; from_office: boolean; body: string; at: string; read: boolean; sender: string }

const props = defineProps<{ messages: ChatMessage[]; mineIsOffice: boolean; busy?: boolean; placeholder?: string; disabled?: string | null }>()
const emit = defineEmits<{ send: [body: string] }>()

const text = ref('')
const box = ref<HTMLDivElement | null>(null)
const mine = (m: ChatMessage) => m.from_office === props.mineIsOffice
const day = (at: string) => new Date(at.replace(' ', 'T')).toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' })
const time = (at: string) => new Date(at.replace(' ', 'T')).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
const newDay = (i: number) => i === 0 || day(props.messages[i].at) !== day(props.messages[i - 1].at)

function send() {
  const body = text.value.trim()
  if (!body || props.busy) return
  emit('send', body)
  text.value = ''
}
function key(e: KeyboardEvent) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    send()
  }
}
// Always open at the newest message
watch(() => props.messages.length, async () => {
  await nextTick()
  if (box.value) box.value.scrollTop = box.value.scrollHeight
}, { immediate: true })
</script>

<template>
  <div class="flex min-h-0 flex-col">
    <div ref="box" class="min-h-[18rem] flex-1 space-y-2 overflow-y-auto p-4 sm:p-5" aria-live="polite">
      <p v-if="!messages.length" class="py-12 text-center text-sm text-gray-500"><slot name="empty">No messages yet.</slot></p>
      <template v-for="(m, i) in messages" :key="m.id">
        <p v-if="newDay(i)" class="py-2 text-center text-xs font-semibold text-gray-500">{{ day(m.at) }}</p>
        <div class="flex" :class="mine(m) ? 'justify-end' : 'justify-start'">
          <div
            class="max-w-[85%] rounded-2xl px-4 py-2.5 text-sm shadow-sm sm:max-w-[70%]"
            :class="mine(m) ? 'rounded-br-md bg-brand text-white' : 'rounded-bl-md border border-line bg-surface text-ink'"
          >
            <p v-if="!mine(m)" class="mb-0.5 text-xs font-semibold" :class="m.from_office ? 'text-brand' : 'text-gray-600'">{{ m.sender }}</p>
            <p class="whitespace-pre-wrap break-words">{{ m.body }}</p>
            <p class="mt-1 text-right text-[11px]" :class="mine(m) ? 'text-white/70' : 'text-gray-400'">
              {{ time(m.at) }}<template v-if="mine(m)"> · {{ m.read ? 'Read' : 'Sent' }}</template><template v-if="mine(m) && mineIsOffice"> · {{ m.sender }}</template>
            </p>
          </div>
        </div>
      </template>
    </div>
    <p v-if="disabled" class="border-t border-line px-4 py-3 text-sm text-gray-600">{{ disabled }}</p>
    <form v-else class="flex items-end gap-2 border-t border-line p-3" @submit.prevent="send">
      <label for="chat-text" class="sr-only">Your message</label>
      <textarea
        id="chat-text"
        v-model="text"
        rows="2"
        maxlength="2000"
        class="input min-h-[2.75rem] flex-1 resize-none"
        :placeholder="placeholder ?? 'Write a message…'"
        @keydown="key"
      />
      <button type="submit" class="btn-primary h-11 shrink-0" :disabled="busy || !text.trim()">{{ busy ? 'Sending…' : 'Send' }}</button>
    </form>
  </div>
</template>
