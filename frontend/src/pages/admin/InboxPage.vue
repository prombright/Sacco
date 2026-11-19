<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api'
import { useOffice } from '@/stores/office'
import PageHeader from '@/components/PageHeader.vue'
import UserAvatar from '@/components/UserAvatar.vue'
import ChatThread, { type ChatMessage } from '@/components/ChatThread.vue'

interface Conversation { member_id: number; full_name: string; account_no: string | null; photo_url: string | null; last: string; last_from_office: boolean; last_at: string; total: number; unread: number }

/**
 * Members' messages to the office: every conversation (newest first, unread marked), and the one
 * open beside it to read and answer. Members see the answers as from "SACCO office".
 */
const route = useRoute()
const router = useRouter()
const office = useOffice()
const conversations = ref<Conversation[] | null>(null)
const open = ref<number | null>(route.query.member ? Number(route.query.member) : null)
const thread = ref<{ member: { id: number; full_name: string; account_no: string | null; phone: string | null }; messages: ChatMessage[] } | null>(null)
const q = ref('')
const busy = ref(false)
const error = ref('')
let timer: ReturnType<typeof setInterval> | undefined

async function loadList() {
  try {
    conversations.value = (await api.get<{ conversations: Conversation[] }>('/office-chat')).conversations
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the messages.'
  }
}
async function loadThread() {
  if (!open.value) return
  try {
    thread.value = await api.get(`/office-chat/${open.value}`)
    const c = conversations.value?.find((x) => x.member_id === open.value)
    if (c) c.unread = 0
    office.refresh(true)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t open the conversation.'
  }
}
async function reply(body: string) {
  if (!open.value) return
  busy.value = true
  error.value = ''
  try {
    const res = await api.post<{ messages: ChatMessage[] }>(`/office-chat/${open.value}`, { body })
    if (thread.value) thread.value.messages = res.messages
    loadList()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t send the answer.'
  } finally {
    busy.value = false
  }
}
watch(open, (id) => {
  thread.value = null
  router.replace({ query: id ? { member: String(id) } : {} })
  loadThread()
})
onMounted(() => {
  loadList()
  loadThread()
  timer = setInterval(() => {
    if (document.visibilityState !== 'visible') return
    loadList()
    loadThread()
  }, 20000)
})
onBeforeUnmount(() => clearInterval(timer))

const shown = computed(() => {
  const term = q.value.trim().toLowerCase()
  return (conversations.value ?? []).filter((c) => !term || c.full_name.toLowerCase().includes(term) || (c.account_no ?? '').includes(term))
})
const unreadTotal = computed(() => (conversations.value ?? []).reduce((n, c) => n + c.unread, 0))
const when = (at: string) => {
  const d = new Date(at.replace(' ', 'T'))
  return d.toDateString() === new Date().toDateString() ? d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
}
</script>

<template>
  <div>
    <PageHeader title="Members' messages">
      What members have written to the office{{ unreadTotal ? ` · ${unreadTotal} unread` : '' }}. Members see your answers as from "SACCO office"; here you see who answered.
    </PageHeader>
    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <div class="mt-5 grid gap-4 lg:grid-cols-[22rem_1fr]">
      <!-- Conversations -->
      <section class="card flex flex-col overflow-hidden lg:h-[calc(100vh-15rem)]" :class="{ 'max-lg:hidden': open }">
        <div class="border-b border-line p-3">
          <label for="inbox-q" class="sr-only">Find a member</label>
          <input id="inbox-q" v-model="q" type="search" class="input" placeholder="Find a member" />
        </div>
        <ul class="flex-1 divide-y divide-line overflow-y-auto">
          <li v-for="c in shown" :key="c.member_id">
            <button type="button" class="flex w-full items-start gap-3 px-3 py-3 text-left transition hover:bg-gray-50" :class="open === c.member_id ? 'bg-navy-50/60' : ''" @click="open = c.member_id">
              <UserAvatar :name="c.full_name" :photo-url="c.photo_url" size="h-10 w-10 text-sm" />
              <span class="min-w-0 flex-1">
                <span class="flex items-baseline justify-between gap-2">
                  <span class="truncate text-sm" :class="c.unread ? 'font-bold text-ink' : 'font-semibold'">{{ c.full_name }}</span>
                  <span class="shrink-0 text-xs text-gray-500">{{ when(c.last_at) }}</span>
                </span>
                <span class="mt-0.5 flex items-center gap-2">
                  <span class="truncate text-xs" :class="c.unread ? 'font-semibold text-ink' : 'text-gray-500'">{{ c.last_from_office ? 'You: ' : '' }}{{ c.last }}</span>
                  <span v-if="c.unread" class="ml-auto shrink-0 rounded-full bg-brand px-1.5 py-0.5 text-[11px] font-bold leading-none text-white">{{ c.unread }}</span>
                </span>
              </span>
            </button>
          </li>
          <li v-if="conversations && !shown.length" class="p-6 text-center text-sm text-gray-500">{{ q ? 'No one matches.' : 'No member has written yet.' }}</li>
          <li v-if="!conversations" class="p-6 text-sm text-gray-500">Loading…</li>
        </ul>
      </section>

      <!-- The open conversation -->
      <section class="card flex min-h-[26rem] flex-col overflow-hidden lg:h-[calc(100vh-15rem)]" :class="{ 'max-lg:hidden': !open }">
        <template v-if="open && thread">
          <div class="flex items-center gap-3 border-b border-line px-4 py-3">
            <button type="button" class="btn-ghost -ml-2 px-2 lg:hidden" aria-label="Back to the list" @click="open = null">←</button>
            <div class="min-w-0 flex-1">
              <RouterLink :to="{ name: 'member', params: { id: thread.member.id } }" class="font-semibold hover:underline">{{ thread.member.full_name }}</RouterLink>
              <p class="text-xs text-gray-500">Account {{ thread.member.account_no ?? '—' }}<template v-if="thread.member.phone"> · <a :href="`tel:${thread.member.phone}`" class="hover:underline">{{ thread.member.phone }}</a></template></p>
            </div>
          </div>
          <ChatThread class="min-h-0 flex-1" :messages="thread.messages" :mine-is-office="true" :busy="busy" placeholder="Answer as the SACCO office…" @send="reply" />
        </template>
        <p v-else-if="open" class="p-6 text-sm text-gray-500">Loading…</p>
        <p v-else class="m-auto p-6 text-center text-sm text-gray-500">Choose a conversation to read and answer it.</p>
      </section>
    </div>
  </div>
</template>
