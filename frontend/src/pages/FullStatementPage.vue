<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api'
import { formatDate, formatMoney } from '@/money'
import type { Report, ReportRow } from '@/reports'
import PageHeader from '@/components/PageHeader.vue'
import ReportView from '@/components/ReportView.vue'

interface Row { date: string; ref: string | null; details: string; shares: string | null; savings: string | null; savings_balance: string; savings_interest: string | null; loan: string | null; loan_interest: string | null; loan_balance: string; other: string | null; _old: boolean }
interface Data {
  member: { full_name: string; account_no: string | null; employee_no: string | null; joined_on: string | null }
  from: string | null
  to: string
  cutoff: string | null
  has_other: boolean
  opening: { savings: string; shares: string; loan: string; held: string }
  closing: { savings: string; shares: string; loan: string; held: string; share_count: number | null }
  totals: Record<string, string>
  flows: Record<string, string>
  rows: Row[]
}

/**
 * A member's full statement, as detailed as the old system's: shares, savings and their running
 * balance, savings interest, loans, loan interest and what is owed, one line per entry, from the
 * old system's history on into this system; any period; Excel, PDF and print. The member sees
 * their own; the office opens any member's.
 */
const route = useRoute()
const memberId = computed(() => (route.params.id ? Number(route.params.id) : null))
const today = new Date().toLocaleDateString('en-CA')
const preset = ref<'all' | 'year' | '12m' | 'custom'>('year')
const from = ref(`${new Date().getFullYear()}-01-01`)
const to = ref(today)
const data = ref<Data | null>(null)
const error = ref('')
const busy = ref(false)

watch(preset, (p) => {
  const d = new Date()
  if (p === 'year') from.value = `${d.getFullYear()}-01-01`
  if (p === '12m') { d.setFullYear(d.getFullYear() - 1); d.setDate(d.getDate() + 1); from.value = d.toLocaleDateString('en-CA') }
  if (p !== 'custom') to.value = today
})
async function load() {
  busy.value = true
  error.value = ''
  try {
    const url = memberId.value ? `/members/${memberId.value}/full-statement` : '/me/full-statement'
    data.value = await api.get<Data>(url, { from: preset.value === 'all' ? undefined : from.value, to: to.value })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t make the statement.'
  } finally {
    busy.value = false
  }
}
onMounted(load)
watch([preset, from, to], load)

const period = computed(() => (data.value ? (data.value.from ? `${formatDate(data.value.from)} to ${formatDate(data.value.to)}` : `Everything to ${formatDate(data.value.to)}`) : ''))
const report = computed<Report | null>(() => {
  const d = data.value
  if (!d) return null
  const cols: Report['columns'] = [
    { key: 'date', label: 'Date', type: 'date' },
    { key: 'ref', label: 'Ref', type: 'text' },
    { key: 'details', label: 'Details', type: 'text' },
    { key: 'shares', label: 'Shares', type: 'money' },
    { key: 'savings', label: 'Savings', type: 'money' },
    { key: 'savings_balance', label: 'Savings balance', type: 'money' },
    { key: 'savings_interest', label: 'Savings interest', type: 'money' },
    { key: 'loan', label: 'Loan', type: 'money' },
    { key: 'loan_interest', label: 'Loan interest', type: 'money' },
    { key: 'loan_balance', label: 'Loan + interest owed', type: 'money' },
  ]
  if (d.has_other) cols.push({ key: 'other', label: 'Other', type: 'money' })
  const rows: ReportRow[] = []
  if (d.from) {
    rows.push({ date: d.from, details: 'Brought forward', savings_balance: d.opening.savings, loan_balance: d.opening.loan, shares: d.opening.shares, savings_interest: d.opening.held, _kind: 'subtotal' })
  }
  let section = ''
  for (const r of d.rows) {
    const here = r._old ? 'old' : 'new'
    if (here !== section && d.cutoff) {
      rows.push({ details: r._old ? 'From the old system\'s records' : `In this system (balances brought in on ${formatDate(d.cutoff)})`, _kind: 'heading' })
      section = here
    }
    const { _old, ...rest } = r
    void _old
    rows.push(rest)
  }
  const m = d.member
  return {
    title: `Statement for ${m.full_name}`,
    subtitle: `Account ${m.account_no ?? '—'} · ${m.employee_no ?? ''} · ${period.value}`,
    columns: cols,
    rows,
    totals: { shares: d.totals.shares, savings: d.totals.savings, savings_interest: d.totals.savings_interest, loan: d.totals.loan, loan_interest: d.totals.loan_interest, other: d.totals.other },
  }
})
/** The summary under the statement, as the old one had */
const summary = computed<Report | null>(() => {
  const d = data.value
  if (!d) return null
  const f = d.flows
  return {
    title: `Summary, ${period.value}`,
    subtitle: `${d.member.full_name} · account ${d.member.account_no ?? '—'}`,
    columns: [{ key: 'item', label: 'Item', type: 'text' }, { key: 'in', label: 'In / issued / earned', type: 'money' }, { key: 'out', label: 'Out / repaid / paid', type: 'money' }, { key: 'end', label: 'At the end', type: 'money' }],
    rows: [
      { item: 'Savings', in: f.savings_in, out: f.savings_out, end: d.closing.savings },
      { item: `Shares${d.closing.share_count !== null ? ` (${d.closing.share_count} share${d.closing.share_count === 1 ? '' : 's'})` : ''}`, in: d.totals.shares, out: null, end: d.closing.shares },
      { item: 'Savings interest', in: f.interest_earned, out: f.interest_paid_out, end: d.closing.held },
      { item: 'Loans', in: f.loan_issued, out: f.loan_repaid, end: null },
      { item: 'Loan interest', in: f.interest_charged, out: f.interest_paid, end: null },
      { item: 'Loan + interest owed', in: null, out: null, end: d.closing.loan, _kind: 'subtotal' },
    ],
    totals: null,
  }
})
const cards = computed(() => {
  const d = data.value
  if (!d) return []
  return [
    { label: 'Savings', value: d.closing.savings, note: 'Your savings balance' },
    { label: 'Shares', value: d.closing.shares, note: d.closing.share_count !== null ? `${d.closing.share_count} share${d.closing.share_count === 1 ? '' : 's'}` : 'Share capital' },
    { label: 'Savings interest held', value: d.closing.held, note: 'Earned and not yet paid out' },
    { label: 'Loan + interest owed', value: d.closing.loan, note: Number(d.closing.loan) > 0 ? 'Still to repay' : 'Nothing owed', warn: Number(d.closing.loan) > 0 },
  ]
})
</script>

<template>
  <div>
    <PageHeader :eyebrow="memberId ? undefined : 'My account'" :title="memberId && data ? `Full statement: ${data.member.full_name}` : 'My full statement'" :trail="memberId && data ? [{ label: data.member.full_name, to: { name: 'member', params: { id: memberId } } }] : undefined">
      Every entry on {{ memberId ? 'the member\'s' : 'your' }} shares, savings, savings interest and loans, one line each, with running balances{{ data?.cutoff ? `, from the old system's records on into this system` : '' }}.
    </PageHeader>

    <div class="mt-5 flex flex-wrap items-end gap-3">
      <div>
        <label for="fs-period" class="label">Period</label>
        <select id="fs-period" v-model="preset" class="input w-auto">
          <option value="year">This year</option>
          <option value="12m">The last 12 months</option>
          <option value="all">Everything</option>
          <option value="custom">Choose dates</option>
        </select>
      </div>
      <template v-if="preset === 'custom'">
        <div><label for="fs-from" class="label">From</label><input id="fs-from" v-model="from" type="date" class="input" :max="to" /></div>
        <div><label for="fs-to" class="label">To</label><input id="fs-to" v-model="to" type="date" class="input" :max="today" /></div>
      </template>
    </div>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <div v-if="data" class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
      <div v-for="(c, n) in cards" :key="c.label" class="card reveal p-4" :style="{ '--i': n }">
        <p class="eyebrow">{{ c.label }}</p>
        <p class="key-figure mt-1.5" :class="c.warn ? '!text-red-700' : ''">{{ formatMoney(c.value) }}</p>
        <p class="mt-0.5 text-xs text-gray-500">{{ c.note }} · {{ formatDate(data.to) }}</p>
      </div>
    </div>

    <div class="mt-5 space-y-5">
      <ReportView :report="report" :busy="busy && !data" :error="''">
        <template #empty>No entries in this period.</template>
      </ReportView>
      <ReportView v-if="summary" :report="summary" />
    </div>
  </div>
</template>
