<script setup lang="ts">
import { reactive, ref } from 'vue'
import { api, ApiError } from '@/api'
import type { Balances, Transaction } from '@/types'
import { accountLabel, formatDate, formatMoney, transactionTypeLabel } from '@/money'
import Modal from '@/components/Modal.vue'

/**
 * The super admin corrects an entry recorded by mistake (the member is told), or deletes it as if
 * it had never been made (the member isn't told; the audit report keeps it). Later balances are
 * worked out again either way.
 */
const props = defineProps<{ transaction: Transaction; mode: 'edit' | 'delete' }>()
const emit = defineEmits<{ close: []; saved: [balances: Balances] }>()

const t = props.transaction
const form = reactive({
  amount: String(Math.abs(Number(t.amount))),
  value_date: t.value_date,
  reference: t.reference ?? '',
  narration: t.narration ?? '',
  reason: '',
})
const error = ref('')
const busy = ref(false)

async function save() {
  error.value = ''
  busy.value = true
  try {
    const res = props.mode === 'edit'
      ? await api.patch<{ balances: Balances; pending_approval?: unknown }>(`/transactions/${t.id}`, form)
      : await api.delete<{ balances: Balances; pending_approval?: unknown }>(`/transactions/${t.id}`, { reason: form.reason })
    if (res.pending_approval) {
      emit('close')
      return
    }
    emit('saved', res.balances)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Something went wrong. Please try again.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :title="mode === 'edit' ? 'Correct an entry' : 'Delete an entry'" @close="emit('close')">
    <form class="space-y-4" novalidate @submit.prevent="save">
      <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

      <div class="rounded-xl bg-gray-50 p-4 text-sm">
        <p class="font-semibold">{{ transactionTypeLabel[t.type] ?? t.type }} · {{ accountLabel[t.account] ?? t.account }}</p>
        <p class="mt-1 text-gray-600">{{ formatMoney(t.amount.replace('-', '')) }} on {{ formatDate(t.value_date) }}<template v-if="t.reference"> · {{ t.reference }}</template></p>
      </div>

      <template v-if="mode === 'edit'">
        <div class="grid gap-4 sm:grid-cols-2">
          <div><label for="fx-amount" class="label">Right amount</label><input id="fx-amount" v-model="form.amount" class="input tabular-nums" inputmode="decimal" /></div>
          <div><label for="fx-date" class="label">Date</label><input id="fx-date" v-model="form.value_date" type="date" class="input" :max="new Date().toLocaleDateString('en-CA')" /></div>
          <div><label for="fx-ref" class="label">Reference</label><input id="fx-ref" v-model="form.reference" class="input" maxlength="80" /></div>
          <div><label for="fx-note" class="label">Details</label><input id="fx-note" v-model="form.narration" class="input" maxlength="255" /></div>
        </div>
        <div><label for="fx-why" class="label">What was wrong</label><input id="fx-why" v-model="form.reason" class="input" maxlength="255" placeholder="e.g. typed 100,000 instead of 10,000" /></div>
        <p class="text-xs text-gray-500">The member is told what changed and why. Every balance after this entry is worked out again.</p>
      </template>
      <template v-else>
        <div><label for="fx-why" class="label">Why it is being deleted</label><input id="fx-why" v-model="form.reason" class="input" maxlength="255" placeholder="e.g. recorded twice" /></div>
        <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
          The entry disappears from the member's account and their balance goes back to what it was without it. <strong>The member is not told.</strong>
          The office is, and the audit report keeps what was deleted, by whom and why.
        </p>
      </template>

      <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <button type="button" class="btn-secondary" @click="emit('close')">Cancel</button>
        <button type="submit" :class="mode === 'edit' ? 'btn-primary' : 'btn bg-red-600 text-white hover:bg-red-700'" :disabled="busy || !form.reason.trim()">{{ busy ? 'Saving…' : mode === 'edit' ? 'Save the correction' : 'Delete the entry' }}</button>
      </div>
    </form>
  </Modal>
</template>
