<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { MagnifyingGlassIcon, PaperAirplaneIcon, PrinterIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { ask } from '@/confirm'
import { formatDate, formatMoney } from '@/money'
import { toast } from '@/toast'
import { useAuth } from '@/stores/auth'
import SaccoMark from '@/components/SaccoMark.vue'
import EmptyState from '@/components/EmptyState.vue'

/** Every member's month on one page each: to print and hand out, or to send to their phones */

interface Row {
  user_id: number
  full_name: string
  account_no: string | null
  employee_no: string | null
  savings_end: string
  savings_in: string
  savings_out: string
  shares_end: string
  repaid: string
  loan_left: string
  next_due_date: string | null
  next_due_amount: string
}
interface Data { month: string; members: Row[]; automatic: boolean; sent_for: string | null }

const auth = useAuth()
const last = new Date(new Date().getFullYear(), new Date().getMonth() - 1, 1)
const month = ref(`${last.getFullYear()}-${String(last.getMonth() + 1).padStart(2, '0')}`)
const data = ref<Data | null>(null)
const q = ref('')
const error = ref('')
const busy = ref(false)

async function load() {
  error.value = ''
  try {
    data.value = await api.get<Data>('/statements/monthly', { month: month.value })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the statements.'
  }
}
onMounted(load)
watch(month, load)

const label = computed(() => new Date(`${month.value}-01T00:00:00`).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }))
const shown = computed(() => {
  const term = q.value.trim().toLowerCase()
  return (data.value?.members ?? []).filter((m) => !term || m.full_name.toLowerCase().includes(term) || (m.account_no ?? '').includes(term) || (m.employee_no ?? '').toLowerCase().includes(term))
})

async function print() {
  document.body.classList.add('printing-report')
  await nextTick()
  const done = () => {
    document.body.classList.remove('printing-report')
    window.removeEventListener('afterprint', done)
  }
  window.addEventListener('afterprint', done)
  window.print()
}

async function send() {
  if (!data.value || !await ask(`Send the ${label.value} statement to all ${data.value.members.length} members now? Each gets it under Messages, with an email copy where they have an email.`)) return
  busy.value = true
  try {
    const res = await api.post<{ sent: number }>('/statements/monthly/send', { month: month.value })
    toast(`${res.sent} statements sent for ${label.value}`)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t send them.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <div class="print:hidden">
      <p class="eyebrow">Reports</p>
      <h1 class="mt-1 text-2xl font-bold">Monthly statements</h1>
      <p class="mt-1 max-w-3xl text-gray-600">
        Each member's month in a few lines. Print them to hand out (one member per page), or send them to members' phones.
        <template v-if="data?.automatic"> They also go out by themselves on the 1st of each month.</template>
        <template v-else> To send them by themselves on the 1st of each month, turn on "Monthly statement" under <RouterLink :to="{ name: 'settings' }" class="font-medium text-brand hover:underline">Setup &amp; rules</RouterLink>.</template>
      </p>

      <div class="mt-5 flex flex-wrap items-end gap-3">
        <div><label for="ms-month" class="label">Month</label><input id="ms-month" v-model="month" type="month" class="input" :max="new Date().toISOString().slice(0, 7)" /></div>
        <div class="relative min-w-[14rem] flex-1 sm:max-w-xs">
          <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" aria-hidden="true" />
          <input v-model="q" type="search" class="input pl-10" placeholder="Find a member" aria-label="Find a member" />
        </div>
        <button type="button" class="btn-secondary" :disabled="!shown.length" @click="print"><PrinterIcon class="h-4 w-4" />Print {{ q ? shown.length : 'all' }}</button>
        <button v-if="auth.isSuperAdmin" type="button" class="btn-primary" :disabled="busy || !data?.members.length" @click="send"><PaperAirplaneIcon class="h-4 w-4" />{{ busy ? 'Sending…' : 'Send to members now' }}</button>
      </div>
      <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
    </div>

    <div class="print-area mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3 print:mt-0 print:block">
      <article v-for="m in shown" :key="m.user_id" class="card p-5 print:mb-0 print:break-after-page print:rounded-none print:border-0 print:p-0 print:shadow-none">
        <div class="hidden items-center gap-3 border-b-2 border-gray-800 pb-3 print:flex">
          <SaccoMark :sacco="auth.sacco" />
          <div>
            <p class="text-lg font-bold">{{ auth.sacco?.name }}</p>
            <p class="text-xs text-gray-600">Monthly statement · {{ label }}</p>
          </div>
        </div>
        <div class="flex items-start justify-between gap-3 print:mt-4">
          <div class="min-w-0">
            <p class="truncate font-semibold">{{ m.full_name }}</p>
            <p class="text-xs text-gray-500">{{ m.employee_no }}<template v-if="m.account_no"> · Acc <span class="font-mono">{{ m.account_no }}</span></template></p>
          </div>
          <span class="shrink-0 rounded-full bg-navy-50 px-2 py-0.5 text-xs font-semibold text-navy-800 print:hidden">{{ label }}</span>
        </div>
        <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
          <div><dt class="text-xs text-gray-500">Savings at month end</dt><dd class="font-semibold tabular-nums">{{ formatMoney(m.savings_end) }}</dd></div>
          <div><dt class="text-xs text-gray-500">Shares</dt><dd class="font-semibold tabular-nums">{{ formatMoney(m.shares_end) }}</dd></div>
          <div><dt class="text-xs text-gray-500">Saved this month</dt><dd class="tabular-nums" :class="Number(m.savings_in) ? 'text-emerald-700' : 'text-gray-500'">{{ Number(m.savings_in) ? '+' + formatMoney(m.savings_in) : 'Nothing' }}</dd></div>
          <div><dt class="text-xs text-gray-500">Taken out</dt><dd class="tabular-nums" :class="Number(m.savings_out) ? 'text-red-700' : 'text-gray-500'">{{ Number(m.savings_out) ? '−' + formatMoney(m.savings_out) : 'Nothing' }}</dd></div>
          <template v-if="Number(m.loan_left) > 0 || Number(m.repaid) > 0">
            <div><dt class="text-xs text-gray-500">Repaid this month</dt><dd class="tabular-nums">{{ formatMoney(m.repaid) }}</dd></div>
            <div><dt class="text-xs text-gray-500">Loan left</dt><dd class="font-semibold tabular-nums">{{ formatMoney(m.loan_left) }}</dd></div>
          </template>
        </dl>
        <p v-if="m.next_due_date" class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-700 print:bg-white print:px-0">
          Next payment <span class="font-semibold tabular-nums">{{ formatMoney(m.next_due_amount) }}</span> by {{ formatDate(m.next_due_date) }}
        </p>
        <p class="mt-6 hidden text-[11px] text-gray-500 print:block">Balances at the end of {{ label }}; the loan as it stands today. Questions: the SACCO office.</p>
      </article>
    </div>
    <div v-if="data && !shown.length" class="card"><EmptyState :title="q ? 'No member matches' : 'No members'" :text="q ? `Nobody matches “${q}”.` : ''" /></div>
    <div v-if="!data && !error" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" aria-hidden="true">
      <div v-for="n in 6" :key="n" class="h-48 animate-pulse rounded-2xl bg-surface" />
    </div>
  </div>
</template>
