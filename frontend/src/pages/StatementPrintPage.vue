<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { PrinterIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { formatDate, formatMoney, transactionTypeLabel } from '@/money'
import SaccoMark from '@/components/SaccoMark.vue'

interface AccountStatement {
  opening: string
  money_in: string
  money_out: string
  closing: string
  rows: { date: string; type: string; details: string; amount: string; balance: string }[]
}
interface Statement {
  member: { full_name: string; account_no: string; employee_no: string | null; email: string | null }
  from: string
  to: string
  accounts: { savings: AccountStatement; shares: AccountStatement }
  loans: { loan_no: string; amount: string; months: number; disbursed_on: string; status: string; paid: string; total_left: string; next_due_date: string | null }[]
  printed_at: string
}

/** A member's statement for a period, laid out for printing. /statement/print or /members/:id/statement */
const route = useRoute()
const auth = useAuth()
const memberId = route.params.id ? Number(route.params.id) : null

const today = new Date()
const from = ref(String(route.query.from ?? `${today.getFullYear()}-01-01`))
const to = ref(String(route.query.to ?? today.toLocaleDateString('en-CA')))
const data = ref<Statement | null>(null)
const error = ref('')

async function load() {
  error.value = ''
  try {
    data.value = await api.get<Statement>(memberId ? `/members/${memberId}/statement` : '/me/statement', { from: from.value, to: to.value })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the statement.'
  }
}
onMounted(load)

const sections = computed(() =>
  data.value ? ([['Savings', data.value.accounts.savings], ['Share capital', data.value.accounts.shares]] as const) : [],
)

function printIt() {
  window.print()
}
</script>

<template>
  <div class="force-light min-h-screen bg-paper px-4 py-6 text-ink print:bg-white print:p-0">
    <div class="mx-auto mb-4 flex max-w-4xl flex-wrap items-end gap-3 print:hidden">
      <button type="button" class="btn-secondary" @click="$router.back()">← Back</button>
      <div><label for="from" class="label">From</label><input id="from" v-model="from" type="date" class="input" /></div>
      <div><label for="to" class="label">To</label><input id="to" v-model="to" type="date" class="input" /></div>
      <button type="button" class="btn-secondary" @click="load">Show</button>
      <button type="button" class="btn-primary ml-auto" :disabled="!data" @click="printIt"><PrinterIcon class="h-4 w-4" />Print or save as PDF</button>
    </div>

    <div v-if="error" class="mx-auto max-w-4xl rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</div>

    <article v-if="data" class="mx-auto max-w-4xl rounded-2xl bg-surface p-8 shadow-card print:max-w-none print:rounded-none print:p-0 print:shadow-none">
      <header class="flex flex-wrap items-start gap-4 border-b-2 border-navy-900 pb-5">
        <SaccoMark :sacco="auth.sacco" size="lg" />
        <div>
          <p class="text-xl font-bold">{{ auth.sacco?.name }}</p>
          <p class="text-sm font-semibold uppercase tracking-wider text-gray-500">Member statement</p>
        </div>
        <div class="ml-auto text-right text-sm">
          <p class="font-semibold">{{ formatDate(data.from) }} – {{ formatDate(data.to) }}</p>
          <p class="text-gray-500">Printed {{ formatDate(data.printed_at) }}</p>
        </div>
      </header>

      <dl class="mt-5 grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
        <div><dt class="text-gray-500">Member</dt><dd class="font-semibold">{{ data.member.full_name }}</dd></div>
        <div><dt class="text-gray-500">Account no.</dt><dd class="font-mono">{{ data.member.account_no }}</dd></div>
        <div v-if="data.member.employee_no"><dt class="text-gray-500">Employee no.</dt><dd>{{ data.member.employee_no }}</dd></div>
        <div v-if="data.member.email"><dt class="text-gray-500">Email</dt><dd>{{ data.member.email }}</dd></div>
      </dl>

      <section v-for="[title, acc] in sections" :key="title" class="mt-7 break-inside-avoid-page">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <h2 class="text-lg font-bold">{{ title }}</h2>
          <p class="text-sm text-gray-600">
            Opening <span class="font-semibold tabular-nums text-ink">{{ formatMoney(acc.opening) }}</span> ·
            In <span class="tabular-nums text-ink">{{ formatMoney(acc.money_in) }}</span> ·
            Out <span class="tabular-nums text-ink">{{ formatMoney(acc.money_out) }}</span> ·
            Closing <span class="font-semibold tabular-nums text-ink">{{ formatMoney(acc.closing) }}</span>
          </p>
        </div>
        <table class="mt-2 w-full text-sm">
          <thead class="border-y border-gray-300 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
            <tr><th class="py-2 pr-3">Date</th><th class="py-2 pr-3">Entry</th><th class="py-2 pr-3">Details</th><th class="py-2 pr-3 text-right">Amount</th><th class="py-2 text-right">Balance</th></tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr class="text-gray-500"><td class="py-1.5 pr-3">{{ formatDate(data.from) }}</td><td class="py-1.5 pr-3" colspan="3">Balance brought forward</td><td class="py-1.5 text-right tabular-nums">{{ formatMoney(acc.opening) }}</td></tr>
            <tr v-for="(r, i) in acc.rows" :key="i">
              <td class="whitespace-nowrap py-1.5 pr-3">{{ formatDate(r.date) }}</td>
              <td class="py-1.5 pr-3">{{ transactionTypeLabel[r.type] ?? r.type }}</td>
              <td class="py-1.5 pr-3 text-gray-600">{{ r.details }}</td>
              <td class="whitespace-nowrap py-1.5 pr-3 text-right tabular-nums">{{ formatMoney(r.amount, { sign: true }) }}</td>
              <td class="whitespace-nowrap py-1.5 text-right tabular-nums">{{ formatMoney(r.balance) }}</td>
            </tr>
            <tr class="border-t border-gray-300 font-semibold"><td class="py-2 pr-3" colspan="4">Closing balance</td><td class="py-2 text-right tabular-nums">{{ formatMoney(acc.closing) }}</td></tr>
          </tbody>
        </table>
      </section>

      <section v-if="data.loans.length" class="mt-7 break-inside-avoid-page">
        <h2 class="text-lg font-bold">Loans</h2>
        <table class="mt-2 w-full text-sm">
          <thead class="border-y border-gray-300 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
            <tr><th class="py-2 pr-3">Loan</th><th class="py-2 pr-3">Paid out</th><th class="py-2 pr-3 text-right">Amount</th><th class="py-2 pr-3 text-right">Repaid</th><th class="py-2 pr-3 text-right">Still owed</th><th class="py-2 text-right">Next due</th></tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="l in data.loans" :key="l.loan_no">
              <td class="py-1.5 pr-3 font-mono">{{ l.loan_no }}</td>
              <td class="py-1.5 pr-3">{{ formatDate(l.disbursed_on) }} · {{ l.months }} months</td>
              <td class="py-1.5 pr-3 text-right tabular-nums">{{ formatMoney(l.amount) }}</td>
              <td class="py-1.5 pr-3 text-right tabular-nums">{{ formatMoney(l.paid) }}</td>
              <td class="py-1.5 pr-3 text-right font-semibold tabular-nums">{{ l.status === 'closed' ? 'Repaid' : formatMoney(l.total_left) }}</td>
              <td class="py-1.5 text-right">{{ l.next_due_date ? formatDate(l.next_due_date) : '' }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <footer class="mt-10 border-t border-gray-200 pt-3 text-xs text-gray-500">
        This statement is produced by the {{ auth.sacco?.name }} system. Please report any entry you don't recognise to the SACCO office.
      </footer>
    </article>
  </div>
</template>
