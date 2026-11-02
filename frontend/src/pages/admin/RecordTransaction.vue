<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { api, ApiError } from '@/api'
import type { Account, Balances, User } from '@/types'
import { formatMoney } from '@/money'
import Modal from '@/components/Modal.vue'

const props = defineProps<{ member: User; account: Account; balances: Balances }>()
const emit = defineEmits<{ close: []; saved: [balances: Balances] }>()

const today = new Date().toLocaleDateString('en-CA') // YYYY-MM-DD in local time

const form = reactive({
  account: props.account,
  type: 'deposit',
  direction: 'in',
  amount: '',
  value_date: today,
  reference: '',
  narration: '',
})
const errors = ref<Record<string, string>>({})
const error = ref('')
const busy = ref(false)

const types = [
  { value: 'deposit', label: 'Deposit', hint: 'Money paid in (cash, bank, payroll).' },
  { value: 'withdrawal', label: 'Withdrawal', hint: 'Money paid out to the member.' },
  { value: 'adjustment', label: 'Adjustment', hint: 'Corrects a balance. Say why in the note.' },
  { value: 'opening_balance', label: 'Opening balance', hint: 'Their balance from the old records. Only on an account with no entries yet.' },
]
const hint = computed(() => types.find((t) => t.value === form.type)?.hint)

async function save() {
  errors.value = {}
  error.value = ''
  busy.value = true
  try {
    const res = await api.post<{ balances: Balances }>(`/members/${props.member.id}/transactions`, form)
    emit('saved', res.balances)
  } catch (e) {
    if (e instanceof ApiError) {
      errors.value = e.errors
      error.value = Object.keys(e.errors).length ? '' : e.message
    } else {
      error.value = 'Something went wrong. Please try again.'
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :title="`Record an entry for ${member.full_name}`" @close="emit('close')">
    <form class="space-y-4" novalidate @submit.prevent="save">
      <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label for="account" class="label">Account</label>
          <select id="account" v-model="form.account" class="input">
            <option value="savings">Savings · {{ formatMoney(balances.savings) }}</option>
            <option value="shares">Shares · {{ formatMoney(balances.shares) }}</option>
          </select>
        </div>
        <div>
          <label for="type" class="label">Entry</label>
          <select id="type" v-model="form.type" class="input">
            <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
        </div>
      </div>
      <p class="-mt-2 text-xs text-gray-500">{{ hint }}</p>

      <fieldset v-if="form.type === 'adjustment'">
        <legend class="label">The adjustment</legend>
        <div class="flex gap-4 text-sm">
          <label class="flex items-center gap-2"><input v-model="form.direction" type="radio" value="in" class="text-brand focus:ring-brand" /> Adds to the balance</label>
          <label class="flex items-center gap-2"><input v-model="form.direction" type="radio" value="out" class="text-brand focus:ring-brand" /> Takes from it</label>
        </div>
      </fieldset>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label for="amount" class="label">Amount</label>
          <input id="amount" v-model="form.amount" class="input tabular-nums" inputmode="decimal" autocomplete="off" placeholder="0" />
          <p v-if="errors.amount" class="field-error">{{ errors.amount }}</p>
        </div>
        <div>
          <label for="value_date" class="label">Date</label>
          <input id="value_date" v-model="form.value_date" type="date" :max="today" class="input" />
          <p v-if="errors.value_date" class="field-error">{{ errors.value_date }}</p>
        </div>
      </div>

      <div>
        <label for="reference" class="label">Reference <span class="font-normal text-gray-400">(optional)</span></label>
        <input id="reference" v-model="form.reference" class="input" maxlength="80" placeholder="Receipt, bank slip or payroll month" />
      </div>
      <div>
        <label for="narration" class="label">Note <span v-if="form.type !== 'adjustment'" class="font-normal text-gray-400">(optional)</span></label>
        <input id="narration" v-model="form.narration" class="input" maxlength="255" />
        <p v-if="errors.narration" class="field-error">{{ errors.narration }}</p>
      </div>

      <p class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">
        Entries can't be edited or deleted. A mistake is put right by reversing the entry, which stays in the history.
      </p>

      <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <button type="button" class="btn-secondary" @click="emit('close')">Cancel</button>
        <button type="submit" class="btn-primary" :disabled="busy || !form.amount">{{ busy ? 'Saving…' : 'Record entry' }}</button>
      </div>
    </form>
  </Modal>
</template>
