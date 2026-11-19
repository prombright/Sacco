<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api'
import PageHeader from '@/components/PageHeader.vue'
import ChatThread, { type ChatMessage } from '@/components/ChatThread.vue'

/** A member writes to the SACCO office and reads its answers (checked again every 20 seconds) */
const messages = ref<ChatMessage[]>([])
const loaded = ref(false)
const busy = ref(false)
const error = ref('')
let timer: ReturnType<typeof setInterval> | undefined

async function load() {
  try {
    messages.value = (await api.get<{ messages: ChatMessage[] }>('/me/office-chat')).messages
    loaded.value = true
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load your messages.'
  }
}
async function send(body: string) {
  busy.value = true
  error.value = ''
  try {
    messages.value = (await api.post<{ messages: ChatMessage[] }>('/me/office-chat', { body })).messages
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t send it. Please try again.'
  } finally {
    busy.value = false
  }
}
onMounted(() => {
  load()
  timer = setInterval(() => document.visibilityState === 'visible' && load(), 20000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <div>
    <PageHeader eyebrow="My account" title="Write to the office">
      Ask the SACCO office anything: your savings, a loan, a deduction that looks wrong. The super admin reads it and answers here, and you get a message when they do.
    </PageHeader>
    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
    <div class="card mt-5 flex h-[calc(100vh-17rem)] min-h-[26rem] flex-col overflow-hidden">
      <ChatThread v-if="loaded" class="h-full" :messages="messages" :mine-is-office="false" :busy="busy" placeholder="Write to the office…" @send="send">
        <template #empty>No messages yet. Write your question below; the office will answer here.</template>
      </ChatThread>
      <p v-else class="p-6 text-sm text-gray-500">Loading…</p>
    </div>
  </div>
</template>
