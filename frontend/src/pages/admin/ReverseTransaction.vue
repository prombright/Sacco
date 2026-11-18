<script setup lang="ts">
import { ref } from 'vue'
import { api, ApiError } from '@/api'
import type { Balances, Transaction } from '@/types'
import { accountLabel, formatDate, formatMoney, transactionTypeLabel } from '@/money'
import Modal from '@/components/Modal.vue'

const props = defineProps<{ transaction: Transaction }>()
const emit = defineEmits<{ close: []; saved: [balances: Balances] }>()

const reason = ref('')
/** On the wrong member or a typing slip: the entry and its correction are hidden from the member */
const inError = ref(false)
const error = ref('')
const busy = ref(false)

async function save() {
  error.value = ''
  busy.value = true
  try {
    const res = await api.post<{ balances: Balances; pending_approval?: unknown }>(`/transactions/${props.transaction.id}/reverse`, { reason: reason.value, entered_in_error: inError.value })
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
  <Modal title="Reverse an entry" @close="emit('close')">
    <form class="space-y-4" novalidate @submit.prevent="save">
      <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

      <div class="rounded-xl bg-gray-50 p-4 text-sm">
        <p class="font-medium">{{ transactionTypeLabel[transaction.type] }} · {{ accountLabel[transaction.account] }}</p>
        <p class="mt-1 text-gray-600">{{ formatDate(transaction.value_date) }} · {{ formatMoney(transaction.amount, { sign: true }) }}</p>
      </div>
      <p class="text-sm text-gray-700">
        This adds an entry of <strong class="font-semibold">{{ formatMoney(String(-Number(transaction.amount)), { sign: true }) }}</strong>
        that cancels it.
      </p>

      <fieldset class="space-y-2">
        <legend class="sr-only">What kind of mistake</legend>
        <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 text-sm" :class="!inError ? 'border-brand bg-navy-50/60' : 'border-line'">
          <input v-model="inError" type="radio" :value="false" class="mt-0.5 accent-brand" />
          <span><span class="font-medium">Reverse it</span><span class="block text-gray-600">The member sees the entry and the reversal on their statement, with your reason.</span></span>
        </label>
        <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 text-sm" :class="inError ? 'border-brand bg-navy-50/60' : 'border-line'">
          <input v-model="inError" type="radio" :value="true" class="mt-0.5 accent-brand" />
          <span><span class="font-medium">Entered in error</span><span class="block text-gray-600">On the wrong member, or a typing slip. The member sees neither the entry nor the correction, only a message that a mistake was removed and nothing was taken. The office and the books keep both.</span></span>
        </label>
      </fieldset>

      <div>
        <label for="reason" class="label">Why is it being reversed?</label>
        <input id="reason" v-model="reason" class="input" maxlength="255" :placeholder="inError ? 'e.g. Meant for account 104, not 014' : 'e.g. Entered twice'" />
      </div>

      <div class="sheet-actions flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <button type="button" class="btn-secondary" @click="emit('close')">Cancel</button>
        <button type="submit" class="btn-primary" :disabled="busy || !reason.trim()">{{ busy ? 'Saving…' : inError ? 'Remove the mistake' : 'Reverse entry' }}</button>
      </div>
    </form>
  </Modal>
</template>
