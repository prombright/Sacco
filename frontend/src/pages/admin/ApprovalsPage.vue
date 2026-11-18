<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { CheckIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { formatMoney } from '@/money'
import { ask } from '@/confirm'
import { toast } from '@/toast'
import { useOffice } from '@/stores/office'
import PageHeader from '@/components/PageHeader.vue'
import EmptyState from '@/components/EmptyState.vue'
import SkeletonRows from '@/components/SkeletonRows.vue'
import Modal from '@/components/Modal.vue'

/**
 * Two people for big money: what is waiting for a second office person, and what was decided.
 * Approving carries the request out exactly as it was asked, in the approver's name.
 */
interface Approval {
  id: number
  kind: string
  amount: string
  summary: string
  status: 'pending' | 'approved' | 'rejected' | 'cancelled'
  requested_at: string
  requested_by_name: string
  decided_at: string | null
  decided_by_name: string | null
  note: string | null
  mine: boolean
  cannot: string | null
}

const office = useOffice()
const tab = ref<'pending' | 'done'>('pending')
const rows = ref<Approval[] | null>(null)
const limit = ref('0')
const error = ref('')
const busy = ref(0)

async function load() {
  rows.value = null
  error.value = ''
  try {
    const res = await api.get<{ approvals: Approval[]; limit: string }>('/approvals', { status: tab.value })
    rows.value = res.approvals
    limit.value = res.limit
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the requests.'
    rows.value = []
  }
}
watch(tab, load)
onMounted(load)

const when = (at: string) => new Date(at.replace(' ', 'T')).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
const KIND: Record<string, string> = {
  entry: 'Entry on a member',
  reversal: 'Reversal',
  transfer: 'Transfer',
  transfer_reversal: 'Transfer reversal',
  cashbook: 'Cash book',
  journal: 'Journal entry',
}

async function approve(a: Approval) {
  if (!(await ask(`Approve and carry out: ${a.summary}?`))) return
  busy.value = a.id
  try {
    await api.post(`/approvals/${a.id}/approve`)
    toast('Approved and done')
    office.refresh(true)
    await load()
  } catch (e) {
    toast(e instanceof ApiError ? e.message : 'Couldn\'t approve it.', 'error')
  } finally {
    busy.value = 0
  }
}

const rejecting = ref<Approval | null>(null)
const note = ref('')
const rejectError = ref('')
async function reject() {
  if (!rejecting.value) return
  rejectError.value = ''
  try {
    await api.post(`/approvals/${rejecting.value.id}/reject`, { note: note.value })
    toast('Rejected; the person who asked has been told')
    rejecting.value = null
    office.refresh(true)
    await load()
  } catch (e) {
    rejectError.value = e instanceof ApiError ? e.message : 'Couldn\'t reject it.'
  }
}

async function takeBack(a: Approval) {
  if (!(await ask('Take this request back? Nothing will be done.'))) return
  try {
    await api.post(`/approvals/${a.id}/cancel`)
    toast('Taken back')
    office.refresh(true)
    await load()
  } catch (e) {
    toast(e instanceof ApiError ? e.message : 'Couldn\'t take it back.', 'error')
  }
}

const STATUS: Record<string, { label: string; style: string }> = {
  approved: { label: 'Approved', style: 'bg-emerald-50 text-emerald-700' },
  rejected: { label: 'Rejected', style: 'bg-red-50 text-red-700' },
  cancelled: { label: 'Taken back', style: 'bg-gray-100 text-gray-600' },
}
</script>

<template>
  <div class="max-w-4xl">
    <PageHeader title="Waiting for approval">
      Big money actions need two people: one asks, another approves. Approving carries the request out exactly as it was asked.
    </PageHeader>

    <p v-if="Number(limit) > 0" class="mt-3 rounded-lg bg-navy-50 px-4 py-2.5 text-sm text-navy-900">
      Second approval from <strong>{{ formatMoney(limit) }}</strong>: deposits, adjustments, reversals, transfers, cash book and journal entries.
      Withdrawal cheques and loan payouts of that size need two different people for their two steps.
    </p>
    <p v-else class="mt-3 rounded-lg bg-amber-50 px-4 py-2.5 text-sm text-amber-900">
      Second approval is off. The super admin turns it on under Setup &amp; rules › Second approval from.
    </p>

    <div class="mt-5 flex gap-1 border-b border-line" role="tablist">
      <button
        v-for="t in ([['pending', 'Waiting'], ['done', 'Decided']] as const)"
        :key="t[0]"
        type="button"
        role="tab"
        :aria-selected="tab === t[0]"
        class="-mb-px border-b-2 px-3 py-2.5 text-sm font-semibold"
        :class="tab === t[0] ? 'border-brand text-brand' : 'border-transparent text-gray-500 hover:text-ink'"
        @click="tab = t[0]"
      >{{ t[1] }}</button>
    </div>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
    <div v-if="!rows" class="card mt-4"><SkeletonRows :rows="3" :amount="false" /></div>
    <div v-else-if="!rows.length" class="card mt-4">
      <EmptyState :title="tab === 'pending' ? 'Nothing waiting' : 'Nothing decided yet'" :text="tab === 'pending' ? 'Big money actions waiting for a second person show here.' : 'Approved, rejected and taken-back requests show here.'" compact />
    </div>
    <ul v-else class="mt-4 space-y-3">
      <li v-for="a in rows" :key="a.id" class="card p-4 sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0 flex-1">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">{{ KIND[a.kind] ?? a.kind }}</p>
            <p class="mt-1 font-semibold leading-snug">{{ a.summary }}</p>
            <p class="mt-1 text-sm text-gray-600">Asked by {{ a.requested_by_name }} · {{ when(a.requested_at) }}</p>
            <p v-if="a.status !== 'pending'" class="mt-1 text-sm text-gray-600">
              <span class="mr-1 rounded-full px-2 py-0.5 text-xs font-semibold" :class="STATUS[a.status]?.style">{{ STATUS[a.status]?.label }}</span>
              <template v-if="a.decided_by_name">by {{ a.decided_by_name }}</template><template v-if="a.decided_at"> · {{ when(a.decided_at) }}</template><template v-if="a.note"> · “{{ a.note }}”</template>
            </p>
          </div>
          <p class="shrink-0 text-lg font-bold tabular-nums">{{ formatMoney(a.amount) }}</p>
        </div>
        <div v-if="a.status === 'pending'" class="mt-3 flex flex-wrap items-center gap-2 border-t border-line pt-3">
          <template v-if="!a.cannot">
            <button type="button" class="btn-primary" :disabled="busy === a.id" @click="approve(a)"><CheckIcon class="h-4 w-4" />{{ busy === a.id ? 'Approving…' : 'Approve and do it' }}</button>
            <button type="button" class="btn-secondary" @click="rejecting = a; note = ''; rejectError = ''"><XMarkIcon class="h-4 w-4" />Reject</button>
          </template>
          <p v-else class="text-sm text-gray-500">{{ a.cannot }}</p>
          <button v-if="a.mine" type="button" class="btn-ghost ml-auto text-sm" @click="takeBack(a)">Take it back</button>
        </div>
      </li>
    </ul>

    <Modal v-if="rejecting" title="Reject this request" @close="rejecting = null">
      <form class="space-y-4" @submit.prevent="reject">
        <p class="text-sm text-gray-700">{{ rejecting.summary }}</p>
        <div v-if="rejectError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ rejectError }}</div>
        <div>
          <label for="rej-note" class="label">Why</label>
          <input id="rej-note" v-model="note" class="input" maxlength="255" placeholder="e.g. The bank slip shows 1,500,000, not 2,000,000" />
          <p class="mt-1 text-xs text-gray-500">{{ rejecting.requested_by_name }} is told, with your reason.</p>
        </div>
        <div class="sheet-actions flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="rejecting = null">Cancel</button>
          <button type="submit" class="btn-primary !bg-red-700 hover:!bg-red-800" :disabled="!note.trim()">Reject</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
