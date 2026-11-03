<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ArrowDownLeftIcon, ArrowUpRightIcon, PlusIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { formatDate, formatMoney } from '@/money'
import Modal from '@/components/Modal.vue'

interface Entry {
  id: number
  kind: 'income' | 'expense'
  category: string
  amount: string
  entry_date: string
  description: string
  reference: string | null
  reversal_of: number | null
  reversed_by: number | null
  created_by_name: string | null
}
interface Book {
  entries: Entry[]
  income: string
  expenses: string
  categories: { income: string[]; expense: string[] }
  loan_income: { interest: string; penalties: string; processing_fees: string; withdrawal_fees: string }
}

/** The SACCO's own income and expenses, with what the loans earned counted automatically */
const today = new Date()
const from = ref(new Date(today.getFullYear(), today.getMonth(), 1).toLocaleDateString('en-CA'))
const to = ref(today.toLocaleDateString('en-CA'))
const book = ref<Book | null>(null)
const error = ref('')

const adding = ref<'income' | 'expense' | null>(null)
const form = reactive({ category: '', amount: '', entry_date: today.toLocaleDateString('en-CA'), description: '', reference: '' })
const formError = ref('')
const busy = ref(false)

async function load() {
  error.value = ''
  try {
    book.value = await api.get<Book>('/cashbook', { from: from.value, to: to.value })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the cash book.'
  }
}
onMounted(load)

const loanIncome = computed(() => {
  const l = book.value?.loan_income
  return l ? Number(l.interest) + Number(l.penalties) + Number(l.processing_fees) + Number(l.withdrawal_fees) : 0
})
const totalIncome = computed(() => loanIncome.value + Number(book.value?.income ?? 0))
const surplus = computed(() => totalIncome.value - Number(book.value?.expenses ?? 0))

function open(kind: 'income' | 'expense') {
  Object.assign(form, { category: '', amount: '', entry_date: today.toLocaleDateString('en-CA'), description: '', reference: '' })
  formError.value = ''
  adding.value = kind
}

async function save() {
  formError.value = ''
  busy.value = true
  try {
    await api.post('/cashbook', { kind: adding.value, ...form })
    adding.value = null
    await load()
  } catch (e) {
    formError.value = e instanceof ApiError ? e.message : 'Couldn\'t save.'
  } finally {
    busy.value = false
  }
}

async function reverse(e: Entry) {
  const reason = prompt(`Why reverse "${e.description}" (${formatMoney(e.amount)})?`)
  if (!reason) return
  try {
    await api.post(`/cashbook/${e.id}/reverse`, { reason })
    await load()
  } catch (err) {
    alert(err instanceof ApiError ? err.message : 'Couldn\'t reverse it.')
  }
}

const money = (v: number) => formatMoney(String(v))
</script>

<template>
  <div>
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold">Income &amp; expenses</h1>
        <p class="mt-1 text-sm text-gray-600">What the SACCO earns and spends. Loan interest, penalties and fees are counted automatically.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-secondary" @click="open('income')"><PlusIcon class="h-4 w-4" />Record income</button>
        <button type="button" class="btn-primary" @click="open('expense')"><PlusIcon class="h-4 w-4" />Record an expense</button>
      </div>
    </div>

    <div class="mt-5 flex flex-wrap items-end gap-2">
      <div><label for="from" class="label">From</label><input id="from" v-model="from" type="date" class="input" /></div>
      <div><label for="to" class="label">To</label><input id="to" v-model="to" type="date" class="input" /></div>
      <button type="button" class="btn-secondary" @click="load">Show</button>
      <RouterLink :to="{ name: 'reports' }" class="ml-auto text-sm font-semibold text-brand hover:underline">Income statement in Reports →</RouterLink>
    </div>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <template v-if="book">
      <section class="mt-5 grid gap-4 sm:grid-cols-3">
        <div class="card p-5">
          <p class="eyebrow flex items-center gap-1.5 !text-emerald-800"><ArrowDownLeftIcon class="h-4 w-4" />Income</p>
          <p class="figure mt-2 text-2xl">{{ money(totalIncome) }}</p>
          <ul class="mt-2 space-y-0.5 text-xs text-gray-600">
            <li class="flex justify-between"><span>Loan interest</span><span class="tabular-nums">{{ formatMoney(book.loan_income.interest) }}</span></li>
            <li class="flex justify-between"><span>Penalties</span><span class="tabular-nums">{{ formatMoney(book.loan_income.penalties) }}</span></li>
            <li class="flex justify-between"><span>Processing fees</span><span class="tabular-nums">{{ formatMoney(book.loan_income.processing_fees) }}</span></li>
            <li class="flex justify-between"><span>Withdrawal fees</span><span class="tabular-nums">{{ formatMoney(book.loan_income.withdrawal_fees) }}</span></li>
            <li class="flex justify-between"><span>Other income</span><span class="tabular-nums">{{ formatMoney(book.income) }}</span></li>
          </ul>
        </div>
        <div class="card p-5">
          <p class="eyebrow flex items-center gap-1.5 !text-red-800"><ArrowUpRightIcon class="h-4 w-4" />Expenses</p>
          <p class="figure mt-2 text-2xl">{{ formatMoney(book.expenses) }}</p>
        </div>
        <div class="relative overflow-hidden rounded-2xl p-5 text-white shadow-lift" :class="surplus >= 0 ? 'bg-navy-900' : 'bg-red-800'">
          <p class="text-xs font-semibold uppercase tracking-wider text-white/70">{{ surplus >= 0 ? 'Surplus' : 'Deficit' }}</p>
          <p class="mt-2 text-2xl font-bold tabular-nums">{{ money(Math.abs(surplus)) }}</p>
          <p class="mt-1 text-xs text-white/70">{{ formatDate(from) }} – {{ formatDate(to) }}</p>
        </div>
      </section>

      <section class="card mt-6 overflow-hidden">
        <h2 class="border-b border-line px-5 py-4 font-semibold">Entries</h2>
        <ul class="divide-y divide-line">
          <li v-for="e in book.entries" :key="e.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm" :class="{ 'text-gray-400': e.reversed_by }">
            <div class="min-w-0">
              <p class="font-medium" :class="{ 'line-through': e.reversed_by }">{{ e.description }}</p>
              <p class="text-gray-600">{{ formatDate(e.entry_date) }} · {{ e.category }}<template v-if="e.reference"> · {{ e.reference }}</template><template v-if="e.created_by_name"> · by {{ e.created_by_name }}</template></p>
            </div>
            <div class="flex items-center gap-3">
              <span class="font-semibold tabular-nums" :class="e.reversed_by ? '' : e.kind === 'income' ? 'text-emerald-700' : 'text-red-700'">
                {{ e.kind === 'income' ? '+' : '−' }}{{ formatMoney(String(Math.abs(Number(e.amount)))) }}
              </span>
              <button v-if="!e.reversal_of && !e.reversed_by" type="button" class="btn-ghost px-2 py-1" @click="reverse(e)">Reverse</button>
            </div>
          </li>
          <li v-if="!book.entries.length" class="px-5 py-10 text-center text-sm text-gray-500">No income or expenses recorded in this period.</li>
        </ul>
      </section>
    </template>

    <Modal v-if="adding && book" :title="adding === 'income' ? 'Record income' : 'Record an expense'" @close="adding = null">
      <form class="space-y-4" novalidate @submit.prevent="save">
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="cat" class="label">Category</label>
            <select id="cat" v-model="form.category" class="input">
              <option value="" disabled>Choose…</option>
              <option v-for="c in book.categories[adding]" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>
          <div><label for="amt" class="label">Amount</label><input id="amt" v-model="form.amount" class="input tabular-nums" inputmode="decimal" /></div>
        </div>
        <div><label for="desc" class="label">What was it?</label><input id="desc" v-model="form.description" class="input" maxlength="255" :placeholder="adding === 'income' ? 'e.g. Membership fees for new members' : 'e.g. Receipt books for the office'" /></div>
        <div class="grid gap-4 sm:grid-cols-2">
          <div><label for="edate" class="label">Date</label><input id="edate" v-model="form.entry_date" type="date" class="input" /></div>
          <div><label for="eref" class="label">Receipt / voucher no. <span class="font-normal text-gray-400">(optional)</span></label><input id="eref" v-model="form.reference" class="input" maxlength="80" /></div>
        </div>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="adding = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !form.category || !form.amount || !form.description.trim()">Save</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
