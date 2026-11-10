import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api'

export interface Message {
  id: number
  title: string
  body: string
  link: string | null
  kind: string
  read_at: string | null
  created_at: string
  email_status: 'none' | 'queued' | 'sending' | 'sent' | 'failed'
  emailed_at: string | null
}

/**
 * The member's messages from the SACCO, shared by the bell, the Messages page and the
 * text-message banner: checked every 30 seconds, and a new one pops up like an SMS on a phone.
 */
export const useMessages = defineStore('messages', () => {
  const items = ref<Message[]>([])
  const unread = ref(0)
  /** The one showing as a banner */
  const banner = ref<Message | null>(null)
  // The newest message already seen; 0 until the first check
  let seen = 0
  let timer: ReturnType<typeof setInterval> | undefined

  async function load() {
    try {
      const res = await api.get<{ notifications: Message[]; unread: number }>('/me/notifications?limit=30')
      items.value = res.notifications
      unread.value = res.unread
      const newest = res.notifications[0]
      if (newest) {
        // New since the last check, or (on signing in) one that arrived in the last ten minutes
        const fresh = seen
          ? res.notifications.find((m) => m.id > seen && !m.read_at)
          : !newest.read_at && Date.now() - new Date(newest.created_at.replace(' ', 'T')).getTime() < 10 * 60_000 ? newest : undefined
        if (fresh) banner.value = fresh
        seen = Math.max(seen, newest.id)
      } else if (!seen) {
        seen = -1
      }
    } catch {
      // Messages are extra; pages work without them
    }
  }

  function start() {
    if (timer) return
    load()
    timer = setInterval(load, 30_000)
  }

  function stop() {
    clearInterval(timer)
    timer = undefined
    seen = 0
    items.value = []
    unread.value = 0
    banner.value = null
  }

  async function markRead(ids?: number[]) {
    await api.post('/me/notifications/read', ids ? { ids } : {})
    await load()
  }

  return { items, unread, banner, load, start, stop, markRead }
})

/** Which group a message belongs to, for the filters */
export function messageGroup(kind: string): 'money' | 'loans' | 'guarantees' | 'office' {
  if (kind.startsWith('guarantee') || kind.startsWith('guarantor')) return 'guarantees'
  if (kind.startsWith('loan') || kind === 'slow_payer' || kind === 'credit_up') return 'loans'
  if (kind === 'accountability') return 'office'
  return 'money'
}
