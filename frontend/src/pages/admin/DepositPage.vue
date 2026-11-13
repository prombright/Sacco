<script setup lang="ts">
import AmountField from '@/components/AmountField.vue'
import { toast } from '@/toast'
import MoneyAccountSelect from '@/components/MoneyAccountSelect.vue'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { formatMoney } from '@/money'
import type { Balances, PickedMember } from '@/types'
import type { Report } from '@/reports'
import { useAuth } from '@/stores/auth'
import { downloadReceiptNo } from '@/receipts'
import { coins } from '@/motion'
import MemberPicker from '@/components/MemberPicker.vue'
import ReportView from '@/components/ReportView.vue'

/** Money a member brings in outside the payroll: cash or a bank slip, into savings or shares */
const member = ref<PickedMember | null>(null)
const balances = ref<Balances | null>(null)
const today = new Date().toLocaleDateString('en-CA')
const form = reactive({ account: 'savings', amount: '', value_date: today, reference: '', narration: '', money_account_id: '' })
const error = ref('')
const done = ref('')
const busy = ref(false)
const lastReceipt = ref('')
const auth = useAuth()
const receipt = () => downloadReceiptNo(lastReceipt.value, { sacco: auth.sacco, user: auth.user?.full_name ?? null })

watch(member, async (m) => {
  balances.value = m ? (await api.get<{ balances: Balances }>(`/members/${m.id}`)).balances : null
})

async function save() {
  error.value = ''
  done.value = ''
  busy.value = true
  try {
    const res = await api.post<{ balances: Balances; transaction: { id: number } }>(`/members/${member.value!.id}/transactions`, { ...form, type: 'deposit' })
    coins()
    lastReceipt.value = 'RCT-T' + String(res.transaction.id).padStart(6, '0')
    done.value = `${formatMoney(form.amount)} added to ${member.value!.full_name}'s ${form.account}. They have been told.`
    toast(`Deposit recorded: ${formatMoney(form.amount)} to ${member.value!.full_name}`)
    balances.value = res.balances
    Object.assign(form, { amount: '', reference: '', narration: '' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t record the deposit.'
  } finally {
    busy.value = false
  }
}

// Deposits recorded by hand in the last 30 days
const recent = ref<{ value_date: string; full_name: string; account_no: string; account: string; amount: string; reference: string | null; created_by_name: string | null; batch_id: number | null }[]>([])
async function load() {
  const since = new Date(Date.now() - 30 * 86400000).toLocaleDateString('en-CA')
  const res = await api.get<{ transactions: typeof recent.value }>('/transactions', { from: since, to: today, type: 'deposit' })
  recent.value = res.transactions.filter((t) => !t.batch_id)
}
onMounted(load)
const report = computed<Report>(() => ({
  title: 'Deposits recorded by hand',
  subtitle: 'The last 30 days (payroll deposits are on Payroll deductions)',
  columns: [
    { key: 'value_date', label: 'Date', type: 'date' },
    { key: 'full_name', label: 'Member', type: 'text' },
    { key: 'account_no', label: 'Account no.', type: 'text' },
    { key: 'account', label: 'Into', type: 'text' },
    { key: 'reference', label: 'Reference', type: 'text' },
    { key: 'created_by_name', label: 'Recorded by', type: 'text' },
    { key: 'amount', label: 'Amount', type: 'money' },
  ],
  rows: recent.value,
  totals: { amount: String(recent.value.reduce((s, r) => s + Number(r.amount), 0)) },
}))
</script>

<template>
  <div>
    <p class="eyebrow">Savings &amp; shares</p>
    <h1 class="mt-1 text-2xl font-bold">Deposit</h1>
    <p class="mt-1 text-gray-600">Money a member pays in outside the payroll, in cash or by bank slip. It goes to the bank account in the books.</p>

    <form v-can="'money'" class="card mt-5 grid gap-4 p-5 lg:grid-cols-2" @submit.prevent="save">
      <div class="space-y-2 lg:col-span-2">
        <p class="label">Member</p>
        <div v-if="member" class="flex items-center justify-between rounded-xl border border-line p-3">
          <div>
            <p class="font-semibold">{{ member.full_name }} <span class="font-mono text-xs text-gray-500">{{ member.account_no }}</span></p>
            <p v-if="balances" class="text-xs text-gray-600">Savings {{ formatMoney(balances.savings) }} · Shares {{ formatMoney(balances.shares) }}</p>
          </div>
          <button type="button" class="rounded-lg p-1 text-gray-500 hover:bg-gray-100" aria-label="Change member" @click="member = null"><XMarkIcon class="h-5 w-5" /></button>
        </div>
        <MemberPicker v-else placeholder="Search by name or account number" @pick="(m) => (member = m)" />
      </div>
      <fieldset>
        <legend class="label">Into</legend>
        <div class="flex gap-2">
          <label v-for="a in ['savings', 'shares']" :key="a" class="flex flex-1 cursor-pointer items-center gap-2 rounded-xl border p-3 text-sm font-medium capitalize" :class="form.account === a ? 'border-brand bg-navy-50/60' : 'border-line'">
            <input v-model="form.account" type="radio" :value="a" class="accent-brand" />{{ a }}
          </label>
        </div>
      </fieldset>
      <AmountField id="d-amount" v-model="form.amount">
        <p v-if="balances && Number(form.amount) > 0" class="mt-2 text-sm text-gray-600">
          {{ form.account === 'savings' ? 'Savings' : 'Shares' }} will be <span class="font-semibold text-emerald-700 tabular-nums">{{ formatMoney(String(Number(balances[form.account as 'savings' | 'shares']) + Number(String(form.amount).replace(/[,\s]/g, '')))) }}</span>
        </p>
      </AmountField>
      <div><label for="d-date" class="label">Date received</label><input id="d-date" v-model="form.value_date" type="date" :max="today" class="input" /></div>
      <MoneyAccountSelect id="d-money" v-model="form.money_account_id" label="Received into" />
      <div><label for="d-ref" class="label">Receipt or bank slip no.</label><input id="d-ref" v-model="form.reference" class="input" /></div>
      <div class="lg:col-span-2"><label for="d-note" class="label">Note <span class="font-normal text-gray-500">(optional)</span></label><input id="d-note" v-model="form.narration" class="input" /></div>
      <div class="form-actions flex flex-wrap items-center gap-3 lg:col-span-2">
        <button type="submit" class="btn-primary max-lg:flex-1" :disabled="busy || !member || !form.amount">{{ busy ? 'Saving…' : 'Record the deposit' }}</button>
        <p v-if="done" class="text-sm text-emerald-700">{{ done }}</p>
        <button v-if="done && lastReceipt" type="button" class="btn-secondary" @click="receipt">Download receipt</button>
        <p v-if="error" class="text-sm text-red-700" role="alert">{{ error }}</p>
      </div>
    </form>

    <ReportView class="mt-6" :report="report"><template #empty>No deposits recorded by hand in the last 30 days.</template></ReportView>
  </div>
</template>
