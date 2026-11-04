<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api'
import type { Report, ReportRow } from '@/reports'
import ReportView from '@/components/ReportView.vue'

/**
 * The statements worked out from the books: trial balance, balance sheet, income statement,
 * account ledger and monthly loan interest. Clicking an account opens its ledger.
 */
const route = useRoute()
const router = useRouter()
const name = computed(() => String(route.meta.report))
const kind = computed(() => ({ trial_balance: 'as_of', balance_sheet: 'as_of', income_statement: 'range', account_ledger: 'range', loan_interest: 'month' })[name.value] ?? 'as_of')

const today = new Date().toLocaleDateString('en-CA')
const asOf = ref(String(route.query.as_of ?? today))
const from = ref(String(route.query.from ?? `${new Date().getFullYear()}-01-01`))
const to = ref(String(route.query.to ?? today))
const month = ref(String(route.query.month ?? today.slice(0, 7)))
const accountId = ref(String(route.query.account_id ?? ''))
const accounts = ref<{ id: number; code: string; name: string; type: string }[]>([])

const report = ref<Report | null>(null)
const busy = ref(false)
const error = ref('')

async function load() {
  if (name.value === 'account_ledger' && !accountId.value) {
    report.value = null
    return
  }
  busy.value = true
  error.value = ''
  try {
    const params: Record<string, string> =
      kind.value === 'as_of' ? { as_of: asOf.value } : kind.value === 'month' ? { month: month.value } : { from: from.value, to: to.value }
    if (name.value === 'account_ledger') params.account_id = accountId.value
    report.value = await api.get<Report>(`/accounting/reports/${name.value}`, params)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t work out the report.'
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  if (name.value === 'account_ledger') {
    accounts.value = (await api.get<{ accounts: typeof accounts.value }>('/accounting/accounts')).accounts
  }
  load()
})
watch(name, () => {
  report.value = null
  load()
})
watch(accountId, load)

function openLedger(row: ReportRow) {
  router.push({ name: 'account-ledger', query: { account_id: row._account_id, from: kind.value === 'range' ? from.value : `${(kind.value === 'as_of' ? asOf.value : today).slice(0, 4)}-01-01`, to: kind.value === 'as_of' ? asOf.value : to.value } })
}

const explain: Record<string, string> = {
  trial_balance: 'Every account\'s balance. Debits must equal credits.',
  balance_sheet: 'What the SACCO owns, what it owes members and others, and members\' equity. Both sides must be equal.',
  income_statement: 'What the SACCO earned and spent in the period, and the surplus.',
  account_ledger: 'Every entry in one account, with the running balance.',
  loan_interest: 'Interest charged each month at the loan\'s rate on what the borrower still owed, what was paid, and what is still owed.',
}
</script>

<template>
  <div>
    <p class="eyebrow">{{ name === 'loan_interest' ? 'Administration' : 'Financial reports' }}</p>
    <h1 class="mt-1 text-2xl font-bold">{{ route.meta.title }}</h1>
    <p class="mt-1 max-w-3xl text-gray-600">{{ explain[name] }}<template v-if="name !== 'account_ledger' && name !== 'loan_interest'"> Click an account to see its ledger.</template></p>

    <ReportView
      class="mt-5"
      :report="report"
      :busy="busy"
      :error="error"
      :clickable="(r) => !!r._account_id"
      @row-click="openLedger"
    >
      <template #controls>
        <div v-if="name === 'account_ledger'" class="min-w-[16rem]">
          <label for="r-acct" class="label">Account</label>
          <select id="r-acct" v-model="accountId" class="input">
            <option value="">Choose an account…</option>
            <option v-for="a in accounts" :key="a.id" :value="String(a.id)">{{ a.code }} · {{ a.name }}</option>
          </select>
        </div>
        <div v-if="kind === 'as_of'"><label for="r-asof" class="label">As at</label><input id="r-asof" v-model="asOf" type="date" class="input" @change="load" /></div>
        <template v-else-if="kind === 'range'">
          <div><label for="r-from" class="label">From</label><input id="r-from" v-model="from" type="date" class="input" @change="load" /></div>
          <div><label for="r-to" class="label">To</label><input id="r-to" v-model="to" type="date" class="input" @change="load" /></div>
        </template>
        <div v-else><label for="r-month" class="label">Month</label><input id="r-month" v-model="month" type="month" class="input" @change="load" /></div>
      </template>
      <template v-if="name === 'account_ledger' && !accountId" #empty>Choose an account.</template>
    </ReportView>
    <p v-if="name === 'account_ledger' && !accountId" class="mt-4 text-sm text-gray-500">Choose an account above to see its entries.</p>
  </div>
</template>
