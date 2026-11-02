<script setup lang="ts">
import { ref } from 'vue'
import { api, ApiError } from '@/api'
import type { Balances, Transaction } from '@/types'
import { accountLabel, formatDate, formatMoney, transactionTypeLabel } from '@/money'
import Modal from '@/components/Modal.vue'

const props = defineProps<{ transaction: Transaction }>()
const emit = defineEmits<{ close: []; saved: [balances: Balances] }>()

const reason = ref('')
const error = ref('')
const busy = ref(false)

async function save() {
  error.value = ''
  busy.value = true
  try {
    const res = await api.post<{ balances: Balances }>(`/transactions/${props.transaction.id}/reverse`, { reason: reason.value })
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
        that cancels it. Both stay in the history.
      </p>

      <div>
        <label for="reason" class="label">Why is it being reversed?</label>
        <input id="reason" v-model="reason" class="input" maxlength="255" placeholder="e.g. Entered twice" />
      </div>

      <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <button type="button" class="btn-secondary" @click="emit('close')">Cancel</button>
        <button type="submit" class="btn-primary" :disabled="busy || !reason.trim()">{{ busy ? 'Reversing…' : 'Reverse entry' }}</button>
      </div>
    </form>
  </Modal>
</template>
