<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ArrowTrendingUpIcon, BanknotesIcon, PlusIcon, SparklesIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { formatDate, formatMoney } from '@/money'
import Modal from '@/components/Modal.vue'
import StatusBadge from '@/components/StatusBadge.vue'

interface Investment {
  id: number
  name: string
  kind: string
  kind_label: string
  institution: string | null
  amount: string
  started_on: string
  expected_return_percent: string
  status: 'active' | 'closed'
  closed_on: string | null
  closing_value: string | null
  notes: string | null
  profit_to_date: string
  expected_this_year: string
}
interface Return {
  id: number
  investment_id: number
  investment_name: string
  kind: 'income' | 'gain'
  amount: string
  received_on: string
  note: string | null
  reversal_of: number | null
  reversed_by: number | null
  created_by_name: string | null
}
interface Book {
  year: number
  kinds: Record<string, string>
  investments: Investment[]
  returns: Return[]
  summary: { invested: string; expected_profit: string; expected_so_far: string; actual_profit: string }
  profit_share_posted: { id: number; label: string; pool: string; total_dividend: string; member_count: number } | null
  basis: string
  credit_account: string
}
interface ShareRow { user_id: number; full_name: string; account_no: string; basis: string; percent: number; share: string }
interface Share { year: number; pool: string; basis: string; rows: ShareRow[]; member_count: number; total_basis: string; total_paid: string }

/** The SACCO's investments and sharing their profit with members (set up by the super admin) */
const auth = useAuth()
const thisYear = new Date().getFullYear()
const year = ref(thisYear)
const book = ref<Book | null>(null)
const error = ref('')
const today = new Date().toLocaleDateString('en-CA')

async function load() {
  error.value = ''
  try {
    book.value = await api.get<Book>('/investments', { year: year.value })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load investments.'
  }
}
watch(year, () => { share.value = null; load() })
onMounted(load)

const pct = (v: string) => Number(v).toLocaleString(undefined, { maximumFractionDigits: 3 })

// --- Dialogs ------------------------------------------------------------------------
type Dialog = 'add' | 'edit' | 'return' | 'close' | null
const dialog = ref<Dialog>(null)
const current = ref<Investment | null>(null)
const form = reactive({ name: '', kind: 'mutual_fund', institution: '', amount: '', started_on: today, expected_return_percent: '', notes: '', received: '', received_on: today, note: '', closing_value: '', closed_on: today })
const formError = ref('')
const busy = ref(false)

function open(d: Dialog, inv: Investment | null = null) {
  current.value = inv
  formError.value = ''
  Object.assign(form, {
    name: inv?.name ?? '', kind: inv?.kind ?? 'mutual_fund', institution: inv?.institution ?? '', amount: inv ? String(Number(inv.amount)) : '',
    started_on: inv?.started_on ?? today, expected_return_percent: inv ? pct(inv.expected_return_percent) : '', notes: inv?.notes ?? '',
    received: '', received_on: today, note: '', closing_value: '', closed_on: today,
  })
  dialog.value = d
}

async function save() {
  formError.value = ''
  busy.value = true
  try {
    const inv = current.value
    if (dialog.value === 'add') await api.post('/investments', form)
    else if (dialog.value === 'edit' && inv) await api.patch(`/investments/${inv.id}`, form)
    else if (dialog.value === 'return' && inv) await api.post(`/investments/${inv.id}/returns`, { amount: form.received, received_on: form.received_on, note: form.note })
    else if (dialog.value === 'close' && inv) await api.post(`/investments/${inv.id}/close`, { closing_value: form.closing_value, closed_on: form.closed_on })
    dialog.value = null
    share.value = null
    await load()
  } catch (e) {
    formError.value = e instanceof ApiError ? e.message : 'Couldn\'t save.'
  } finally {
    busy.value = false
  }
}

async function reverseReturn(r: Return) {
  const reason = prompt(`Why reverse this ${formatMoney(r.amount)} entry?`)
  if (!reason) return
  try {
    await api.post(`/investment-returns/${r.id}/reverse`, { reason })
    await load()
  } catch (e) {
    alert(e instanceof ApiError ? e.message : 'Couldn\'t reverse it.')
  }
}

// --- Profit share --------------------------------------------------------------------
const pool = ref('')
const share = ref<Share | null>(null)
const shareError = ref('')
const sharing = ref(false)
watch(book, (b) => { if (b) pool.value = String(Math.max(0, Number(b.summary.actual_profit))) })

async function previewShare() {
  shareError.value = ''
  try {
    share.value = await api.post<Share>('/profit-share/preview', { year: year.value, pool: pool.value })
  } catch (e) {
    shareError.value = e instanceof ApiError ? e.message : 'Couldn\'t work it out.'
  }
}
async function payShare() {
  if (!share.value || !confirm(`Credit ${formatMoney(share.value.total_paid)} to ${share.value.member_count} members' ${book.value?.credit_account}?`)) return
  sharing.value = true
  shareError.value = ''
  try {
    await api.post('/profit-share', { year: year.value, pool: pool.value })
    share.value = null
    await load()
  } catch (e) {
    shareError.value = e instanceof ApiError ? e.message : 'Couldn\'t pay the profit share.'
  } finally {
    sharing.value = false
  }
}

const progress = computed(() => {
  const s = book.value?.summary
  if (!s || !Number(s.expected_profit)) return 0
  return Math.min(100, (Number(s.actual_profit) / Number(s.expected_profit)) * 100)
})
</script>

<template>
  <div>
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold">Investments</h1>
        <p class="mt-1 text-sm text-gray-600">What the SACCO invests, the profit it brings, and sharing that profit with members by their savings.</p>
      </div>
      <div class="flex items-end gap-2">
        <div>
          <label for="year" class="label">Year</label>
          <select id="year" v-model.number="year" class="input">
            <option v-for="y in [thisYear, thisYear - 1, thisYear - 2]" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>
        <button v-if="auth.isSuperAdmin" type="button" class="btn-primary" @click="open('add')"><PlusIcon class="h-4 w-4" />Add an investment</button>
      </div>
    </div>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <template v-if="book">
      <!-- The year at a glance -->
      <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="relative overflow-hidden rounded-2xl bg-navy-900 p-5 text-white shadow-lift">
          <div class="absolute -right-14 -top-16 h-32 w-32 rounded-full border-2 border-saffron-400/90" aria-hidden="true" />
          <p class="text-xs font-semibold uppercase tracking-wider text-navy-200">Invested now</p>
          <p class="mt-2 text-2xl font-bold tabular-nums">{{ formatMoney(book.summary.invested) }}</p>
          <p class="mt-1 text-xs text-navy-200">{{ book.investments.filter((i) => i.status === 'active').length }} active investment(s)</p>
        </div>
        <div class="card p-5">
          <p class="eyebrow flex items-center gap-1.5"><ArrowTrendingUpIcon class="h-4 w-4" />Expected profit {{ book.year }}</p>
          <p class="figure mt-2 text-2xl">{{ formatMoney(book.summary.expected_profit) }}</p>
          <p class="mt-1 text-xs text-gray-500">{{ formatMoney(book.summary.expected_so_far) }} earned so far, by the expected returns</p>
        </div>
        <div class="card p-5">
          <p class="eyebrow flex items-center gap-1.5"><BanknotesIcon class="h-4 w-4" />Profit received {{ book.year }}</p>
          <p class="figure mt-2 text-2xl">{{ formatMoney(book.summary.actual_profit) }}</p>
          <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full bg-saffron-500" :style="{ width: progress + '%' }" /></div>
          <p class="mt-1 text-xs text-gray-500">{{ Math.round(progress) }}% of the year's expected profit</p>
        </div>
        <div class="card p-5">
          <p class="eyebrow flex items-center gap-1.5"><SparklesIcon class="h-4 w-4" />Shared with members</p>
          <template v-if="book.profit_share_posted">
            <p class="figure mt-2 text-2xl">{{ formatMoney(book.profit_share_posted.total_dividend) }}</p>
            <p class="mt-1 text-xs text-gray-500">Paid to {{ book.profit_share_posted.member_count }} members</p>
          </template>
          <template v-else>
            <p class="mt-2 text-2xl font-bold text-gray-300">Not yet</p>
            <p class="mt-1 text-xs text-gray-500">Shared at the end of the year, below</p>
          </template>
        </div>
      </section>

      <!-- Investments -->
      <section class="card mt-6 overflow-hidden">
        <h2 class="border-b border-line px-5 py-4 font-semibold">The SACCO's investments</h2>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
              <tr>
                <th class="px-5 py-3">Investment</th>
                <th class="px-4 py-3 text-right">Amount</th>
                <th class="px-4 py-3 text-right">Expected return</th>
                <th class="px-4 py-3 text-right">Expected in {{ book.year }}</th>
                <th class="px-4 py-3 text-right">Profit so far</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="inv in book.investments" :key="inv.id" :class="{ 'text-gray-500': inv.status === 'closed' }">
                <td class="px-5 py-3">
                  <p class="font-semibold">{{ inv.name }} <StatusBadge v-if="inv.status === 'closed'" class="ml-1" :label="`Sold ${formatDate(inv.closed_on)}`" style-class="bg-gray-100 text-gray-600" /></p>
                  <p class="text-xs text-gray-500">{{ inv.kind_label }}<template v-if="inv.institution"> · {{ inv.institution }}</template> · since {{ formatDate(inv.started_on) }}</p>
                </td>
                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(inv.amount) }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ pct(inv.expected_return_percent) }}% a year</td>
                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(inv.expected_this_year) }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums" :class="Number(inv.profit_to_date) < 0 ? 'text-red-700' : 'text-emerald-700'">{{ formatMoney(inv.profit_to_date) }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-right">
                  <template v-if="auth.isSuperAdmin && inv.status === 'active'">
                    <button type="button" class="btn-ghost px-2 py-1" @click="open('return', inv)">Record profit</button>
                    <button type="button" class="btn-ghost px-2 py-1" @click="open('close', inv)">Sell / cash in</button>
                    <button type="button" class="btn-ghost px-2 py-1" @click="open('edit', inv)">Edit</button>
                  </template>
                </td>
              </tr>
              <tr v-if="!book.investments.length"><td colspan="6" class="px-5 py-10 text-center text-gray-500">No investments recorded yet.</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Profit share -->
      <section class="card mt-6 p-5">
        <h2 class="font-semibold">Share the {{ book.year }} profit with members</h2>
        <p class="mt-1 text-sm text-gray-600">
          Each member gets a part in proportion to their
          {{ book.basis === 'average_savings' ? 'average savings over the year (month-end balances), so saving all year counts' : 'savings at the end of the year' }}.
          It is added to their {{ book.credit_account }}.
        </p>
        <p v-if="book.profit_share_posted" class="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
          The {{ book.year }} profit share has been paid: {{ formatMoney(book.profit_share_posted.total_dividend) }} to {{ book.profit_share_posted.member_count }} members.
          See it under Dividends &amp; profit to reverse it if needed.
        </p>
        <template v-else>
          <div class="mt-4 flex flex-wrap items-end gap-2">
            <div>
              <label for="pool" class="label">Profit to share</label>
              <input id="pool" v-model="pool" class="input w-48 tabular-nums" inputmode="decimal" @input="share = null" />
            </div>
            <button type="button" class="btn-ghost px-2 py-2 text-xs" @click="pool = String(Number(book.summary.actual_profit)); share = null">Use profit received ({{ formatMoney(book.summary.actual_profit) }})</button>
            <button type="button" class="btn-ghost px-2 py-2 text-xs" @click="pool = String(Number(book.summary.expected_profit)); share = null">Use expected ({{ formatMoney(book.summary.expected_profit) }})</button>
            <button type="button" class="btn-secondary" @click="previewShare">Work it out</button>
          </div>
          <div v-if="shareError" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ shareError }}</div>

          <div v-if="share" class="mt-5">
            <div class="max-h-96 overflow-auto rounded-xl border border-line">
              <table class="w-full text-sm tabular-nums">
                <thead class="sticky top-0 bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                  <tr><th class="px-3 py-2">Member</th><th class="px-3 py-2 text-right">{{ share.basis === 'average_savings' ? 'Average savings' : 'Savings at year end' }}</th><th class="px-3 py-2 text-right">Share</th><th class="px-3 py-2 text-right">Profit</th></tr>
                </thead>
                <tbody class="divide-y divide-line">
                  <tr v-for="r in share.rows" :key="r.user_id">
                    <td class="px-3 py-2">{{ r.full_name }} <span class="font-mono text-xs text-gray-500">{{ r.account_no }}</span></td>
                    <td class="px-3 py-2 text-right">{{ formatMoney(r.basis) }}</td>
                    <td class="px-3 py-2 text-right">{{ r.percent }}%</td>
                    <td class="px-3 py-2 text-right font-semibold">{{ formatMoney(r.share) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
              <p class="text-sm text-gray-600">{{ share.member_count }} members · {{ formatMoney(share.total_paid) }} of {{ formatMoney(share.pool) }} (shares are rounded down to whole shillings)</p>
              <button v-if="auth.isSuperAdmin" type="button" class="btn-primary" :disabled="sharing || !share.member_count" @click="payShare">
                {{ sharing ? 'Paying…' : `Pay ${formatMoney(share.total_paid)} to members` }}
              </button>
              <p v-else class="text-sm text-gray-500">Only the super admin pays the profit share.</p>
            </div>
          </div>
        </template>
      </section>

      <!-- Profit received -->
      <section class="card mt-6 overflow-hidden">
        <h2 class="border-b border-line px-5 py-4 font-semibold">Profit received</h2>
        <ul class="divide-y divide-line">
          <li v-for="r in book.returns" :key="r.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm" :class="{ 'text-gray-400': r.reversed_by }">
            <div>
              <p class="font-medium" :class="{ 'line-through': r.reversed_by }">{{ r.investment_name }} · {{ r.kind === 'gain' ? 'gain on sale' : 'profit' }}</p>
              <p class="text-gray-600">{{ formatDate(r.received_on) }}<template v-if="r.note"> · {{ r.note }}</template><template v-if="r.created_by_name"> · by {{ r.created_by_name }}</template></p>
            </div>
            <div class="flex items-center gap-3">
              <span class="font-semibold tabular-nums" :class="Number(r.amount) < 0 ? 'text-red-700' : 'text-emerald-700'">{{ formatMoney(r.amount, { sign: true }) }}</span>
              <button v-if="auth.isSuperAdmin && !r.reversal_of && !r.reversed_by" type="button" class="btn-ghost px-2 py-1" @click="reverseReturn(r)">Reverse</button>
            </div>
          </li>
          <li v-if="!book.returns.length" class="px-5 py-8 text-center text-sm text-gray-500">No profit recorded yet.</li>
        </ul>
      </section>
    </template>

    <!-- Add / edit -->
    <Modal v-if="book && (dialog === 'add' || dialog === 'edit')" :title="dialog === 'add' ? 'Add an investment' : `Edit ${current?.name}`" @close="dialog = null">
      <form class="space-y-4" novalidate @submit.prevent="save">
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <div class="grid gap-4 sm:grid-cols-2">
          <div><label for="iname" class="label">Name</label><input id="iname" v-model="form.name" class="input" maxlength="120" placeholder="e.g. Unit trust" /></div>
          <div>
            <label for="ikind" class="label">Type</label>
            <select id="ikind" v-model="form.kind" class="input"><option v-for="(label, k) in book.kinds" :key="k" :value="k">{{ label }}</option></select>
          </div>
          <div><label for="iinst" class="label">Held with <span class="font-normal text-gray-400">(optional)</span></label><input id="iinst" v-model="form.institution" class="input" maxlength="120" placeholder="Fund manager or bank" /></div>
          <div><label for="iamt" class="label">Amount invested</label><input id="iamt" v-model="form.amount" class="input tabular-nums" inputmode="decimal" placeholder="e.g. 50,000,000" /></div>
          <div><label for="irate" class="label">Expected return (% a year)</label><input id="irate" v-model="form.expected_return_percent" class="input tabular-nums" inputmode="decimal" placeholder="e.g. 12" /></div>
          <div><label for="istart" class="label">Invested on</label><input id="istart" v-model="form.started_on" type="date" :max="today" class="input" /></div>
        </div>
        <div><label for="inotes" class="label">Notes <span class="font-normal text-gray-400">(optional)</span></label><input id="inotes" v-model="form.notes" class="input" maxlength="255" /></div>
        <p v-if="Number(form.amount.replace(/[,\s]/g, '')) && Number(form.expected_return_percent)" class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700">
          Expected profit a year: <strong class="font-semibold">{{ formatMoney(String(Math.round(Number(form.amount.replace(/[,\s]/g, '')) * Number(form.expected_return_percent) / 100))) }}</strong>
        </p>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="dialog = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy">{{ dialog === 'add' ? 'Add investment' : 'Save changes' }}</button>
        </div>
      </form>
    </Modal>

    <Modal v-if="dialog === 'return' && current" :title="`Profit from ${current.name}`" @close="dialog = null">
      <form class="space-y-4" novalidate @submit.prevent="save">
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <p class="text-sm text-gray-600">Interest or a distribution the SACCO received from this investment.</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <div><label for="ramt" class="label">Profit received</label><input id="ramt" v-model="form.received" class="input tabular-nums" inputmode="decimal" /></div>
          <div><label for="rdate" class="label">Received on</label><input id="rdate" v-model="form.received_on" type="date" :max="today" class="input" /></div>
        </div>
        <div><label for="rnote" class="label">Note <span class="font-normal text-gray-400">(optional)</span></label><input id="rnote" v-model="form.note" class="input" maxlength="255" placeholder="e.g. Quarterly distribution" /></div>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="dialog = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !form.received">Record profit</button>
        </div>
      </form>
    </Modal>

    <Modal v-if="dialog === 'close' && current" :title="`Sell or cash in ${current.name}`" @close="dialog = null">
      <form class="space-y-4" novalidate @submit.prevent="save">
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <p class="text-sm text-gray-600">What the SACCO got for it. The difference from the {{ formatMoney(current.amount) }} invested is recorded as the gain (or loss), and shared with that year's profit.</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <div><label for="cval" class="label">Sold / cashed in for</label><input id="cval" v-model="form.closing_value" class="input tabular-nums" inputmode="decimal" /></div>
          <div><label for="cdate" class="label">On</label><input id="cdate" v-model="form.closed_on" type="date" :max="today" class="input" /></div>
        </div>
        <p v-if="Number(form.closing_value.replace(/[,\s]/g, ''))" class="rounded-lg bg-gray-50 px-3 py-2 text-sm">
          {{ Number(form.closing_value.replace(/[,\s]/g, '')) >= Number(current.amount) ? 'Gain' : 'Loss' }}:
          <strong class="font-semibold">{{ formatMoney(String(Number(form.closing_value.replace(/[,\s]/g, '')) - Number(current.amount))) }}</strong>
        </p>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="dialog = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !form.closing_value">Record sale</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
