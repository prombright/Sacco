import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api'

/** What's waiting for an admin, for the counts in the menu. Refreshed as admins move around. */
export interface Waiting {
  loans_to_approve: number
  loans_to_pay_out: number
  loans_waiting_guarantors: number
  withdrawals_to_approve: number
  withdrawals_to_pay: number
  members_not_signed_in: number
}

export const useOffice = defineStore('office', () => {
  const waiting = ref<Waiting | null>(null)
  let last = 0

  async function refresh(force = false) {
    // At most every 20 seconds unless something just changed
    if (!force && Date.now() - last < 20_000) return
    last = Date.now()
    try {
      waiting.value = await api.get<Waiting>('/office/counts')
    } catch {
      // The counts are a convenience; the pages themselves show the real lists
    }
  }

  return { waiting, refresh }
})
