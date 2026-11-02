<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { formatDate, formatMoney } from '@/money'
import { type Withdrawal, type WithdrawalStatus, withdrawalStatus } from '@/withdrawals'
import StatusBadge from '@/components/StatusBadge.vue'
import Modal from '@/components/Modal.vue'

const auth = useAuth()
const tabs: { status: WithdrawalStatus; label: string }[] = [
  { status: 'pending', label: 'To approve' },
  { status: 'approved', label: 'To issue cheque' },
  { status: 'paid', label: 'Paid' },
  { status: 'rejected', label: 'Rejected' },
  { status: 'cancelled', label: 'Cancelled' },
]
const status = ref<WithdrawalStatus>('pending')
const requests = ref<Withdrawal[]>([])
const counts = ref<Record<string, number>>({})
const error = ref('')

const today = new Date().toLocaleDateString('en-CA')
const dialog = ref<{ kind: 'reject' | 'pay'; request: Withdrawal } | null>(null)
const form = reactive({ note: '', cheque_no: '', paid_on: today })
const formError = ref('')
const busy = ref(false)

async function load() {
  try {
    const res = await api.get<{ requests: Withdrawal[]; counts: Record<string, number> }>('/withdrawals', { status: status.value })
    requests.value = res.requests
    counts.value = res.counts
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load requests.'
  }
}
watch(status, load)
onMounted(load)

function open(kind: 'reject' | 'pay', request: Withdrawal) {
  Object.assign(form, { note: '', cheque_no: '', paid_on: today })
  formError.value = ''
  dialog.value = { kind, request }
}

async function act(path: string, body: Record<string, unknown> = {}) {
  error.value = ''
  formError.value = ''
  busy.value = true
  try {
    await api.post(path, body)
    dialog.value = null
    await load()
  } catch (e) {
    const message = e instanceof ApiError ? e.message : 'Something went wrong. Please try again.'
    if (dialog.value) formError.value = message
    else error.value = message
  } finally {
    busy.value = false
  }
}

async function approve(r: Withdrawal) {
  if (confirm(`Approve ${r.full_name}'s withdrawal of ${formatMoney(r.amount)}?`)) await act(`/withdrawals/${r.id}/approve`)
}
</script>

<template>
  <div>
    <h1 class="text-xl font-semibold">Withdrawal requests</h1>
    <p class="mt-1 text-sm text-gray-600">Approve requests, then record the cheque. Savings go down when the cheque is recorded.</p>

    <div class="mt-5 flex gap-1 overflow-x-auto pb-1" role="tablist">
      <button
        v-for="tab in tabs"
        :key="tab.status"
        type="button"
        role="tab"
        :aria-selected="status === tab.status"
        class="whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium"
        :class="status === tab.status ? 'bg-brand text-white' : 'text-gray-600 hover:bg-gray-100'"
        @click="status = tab.status"
      >
        {{ tab.label }}<span v-if="counts[tab.status]" class="ml-1 opacity-75">{{ counts[tab.status] }}</span>
      </button>
    </div>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <ul class="card mt-4 divide-y divide-gray-100">
      <li v-for="r in requests" :key="r.id" class="p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium">{{ r.full_name }} <span class="font-mono text-xs font-normal text-gray-500">{{ r.account_no }}</span></p>
            <p class="text-sm text-gray-600">{{ r.request_no }} · asked {{ formatDate(r.created_at) }}<template v-if="r.reason"> · {{ r.reason }}</template></p>
            <p v-if="r.available_now !== undefined" class="mt-1 text-sm" :class="Number(r.available_now) < Number(r.amount) ? 'font-medium text-red-700' : 'text-gray-600'">
              Free savings now {{ formatMoney(r.available_now) }}
            </p>
            <p v-if="r.status === 'paid'" class="mt-1 text-sm text-gray-600">Cheque {{ r.cheque_no }} · {{ formatDate(r.paid_on) }} · by {{ r.paid_by_name }}</p>
            <p v-if="r.status === 'rejected'" class="mt-1 text-sm text-gray-600">{{ r.decision_note }}</p>
          </div>
          <div class="text-right">
            <p class="text-lg font-semibold tabular-nums">{{ formatMoney(r.amount) }}</p>
            <StatusBadge :label="withdrawalStatus[r.status].label" :style-class="withdrawalStatus[r.status].style" />
          </div>
        </div>
        <div class="mt-3 flex flex-wrap gap-2">
          <template v-if="r.user_id !== auth.user?.id">
            <button v-if="r.status === 'pending'" type="button" class="btn-primary px-3 py-1.5" :disabled="busy" @click="approve(r)">Approve</button>
            <button v-if="r.status === 'approved'" type="button" class="btn-primary px-3 py-1.5" @click="open('pay', r)">Record cheque</button>
            <button v-if="r.status === 'pending' || r.status === 'approved'" type="button" class="btn-secondary px-3 py-1.5" @click="open('reject', r)">Reject</button>
          </template>
          <p v-else-if="r.status === 'pending' || r.status === 'approved'" class="text-sm text-gray-500">Your own request: another admin handles it.</p>
          <RouterLink v-if="r.status === 'paid' || r.status === 'approved'" :to="{ name: 'withdrawal-slip', params: { id: r.id } }" class="btn-secondary px-3 py-1.5">Slip</RouterLink>
        </div>
      </li>
      <li v-if="!requests.length" class="px-4 py-10 text-center text-sm text-gray-500">Nothing here.</li>
    </ul>

    <Modal v-if="dialog?.kind === 'reject'" title="Reject withdrawal" @close="dialog = null">
      <form class="space-y-4" @submit.prevent="act(`/withdrawals/${dialog.request.id}/reject`, { note: form.note })">
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <div><label for="note" class="label">Reason (the member will see this)</label><input id="note" v-model="form.note" class="input" maxlength="255" /></div>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="dialog = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !form.note.trim()">Reject</button>
        </div>
      </form>
    </Modal>

    <Modal v-if="dialog?.kind === 'pay'" title="Record cheque" @close="dialog = null">
      <form class="space-y-4" @submit.prevent="act(`/withdrawals/${dialog.request.id}/pay`, { cheque_no: form.cheque_no, paid_on: form.paid_on })">
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <p class="text-sm text-gray-700">Cheque to {{ dialog.request.full_name }} for <strong class="font-semibold">{{ formatMoney(dialog.request.amount) }}</strong>. This takes the amount off their savings.</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <div><label for="cheque" class="label">Cheque number</label><input id="cheque" v-model="form.cheque_no" class="input" maxlength="80" /></div>
          <div><label for="pdate" class="label">Cheque date</label><input id="pdate" v-model="form.paid_on" type="date" :max="today" class="input" /></div>
        </div>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="dialog = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !form.cheque_no.trim()">Record cheque</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
