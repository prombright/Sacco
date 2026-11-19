<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { BellAlertIcon, ChatBubbleLeftEllipsisIcon, ExclamationTriangleIcon, PhoneIcon, UserGroupIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { formatMoney, formatDate } from '@/money'
import { ask } from '@/confirm'
import { toast } from '@/toast'
import { useAuth } from '@/stores/auth'
import PageHeader from '@/components/PageHeader.vue'
import EmptyState from '@/components/EmptyState.vue'
import SkeletonRows from '@/components/SkeletonRows.vue'
import Modal from '@/components/Modal.vue'

/**
 * Arrears follow-up: every late loan, how late and how much, who to call, its guarantors, and what
 * the office has done about it; remind the borrower, tell the guarantors, note a call or a promise.
 */
interface Late {
  id: number
  loan_no: string
  user_id: number
  borrower: string
  account_no: string | null
  phone: string | null
  product: string
  days: number
  overdue: string
  total_left: string
  guarantors: { full_name: string; phone: string | null; amount: string }[]
  history: { kind: 'note' | 'promise' | 'reminder' | 'guarantors'; note: string | null; promise_date: string | null; created_at: string; by_name: string | null }[]
  promise: string | null
}

const auth = useAuth()
const data = ref<{ loans: Late[]; overdue_total: string; last_payroll: string | null; payroll_behind: boolean; warn_after_days: number } | null>(null)
const error = ref('')
async function load() {
  try {
    data.value = await api.get('/arrears')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the late loans.'
  }
}
onMounted(load)

const today = new Date().toLocaleDateString('en-CA')
const FILTERS = [
  { key: 'all', label: 'All late' },
  { key: '1-30', label: '1 to 30 days' },
  { key: '31-90', label: '31 to 90 days' },
  { key: '90+', label: 'Over 90 days' },
  { key: 'promised', label: 'Promised to pay' },
  { key: 'untouched', label: 'Not followed up' },
] as const
const filter = ref<(typeof FILTERS)[number]['key']>('all')
const q = ref('')
/** Whether a late loan belongs under a filter */
function inFilter(l: Late, key: (typeof FILTERS)[number]['key']) {
  if (key === '1-30') return l.days <= 30
  if (key === '31-90') return l.days > 30 && l.days <= 90
  if (key === '90+') return l.days > 90
  if (key === 'promised') return !!l.promise
  if (key === 'untouched') return !l.history.length
  return true
}
const searched = computed(() => {
  const words = q.value.toLowerCase().split(/\s+/).filter(Boolean)
  return (data.value?.loans ?? []).filter((l) => words.every((w) => `${l.borrower} ${l.loan_no} ${l.account_no ?? ''}`.toLowerCase().includes(w)))
})
const shown = computed(() => searched.value.filter((l) => inFilter(l, filter.value)))
const count = (k: (typeof FILTERS)[number]['key']) => searched.value.filter((l) => inFilter(l, k)).length
const promisesDue = computed(() => (data.value?.loans ?? []).filter((l) => l.promise && l.promise <= today).length)
const lateTone = (d: number) => (d > 90 ? 'bg-red-100 text-red-800' : d > 30 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-700')
const KIND: Record<string, string> = { note: 'Note', promise: 'Promise', reminder: 'Reminder', guarantors: 'Guarantors told' }
const monthName = (m: string | null) => (m ? new Date(m + '-01T00:00:00').toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }) : '')
const when = (at: string) => new Date(at.replace(' ', 'T')).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
const canAct = computed(() => auth.can('loans'))

const busy = ref(0)
async function remind(l: Late) {
  if (!(await ask(`Send ${l.borrower} a reminder that ${formatMoney(l.overdue)} is late?`))) return
  busy.value = l.id
  try {
    await api.post(`/loans/${l.id}/remind`)
    toast(`Reminder sent to ${l.borrower}`)
    await load()
  } catch (e) {
    toast(e instanceof ApiError ? e.message : 'Couldn\'t send it.', 'error')
  } finally {
    busy.value = 0
  }
}
async function warn(l: Late) {
  if (!(await ask(`Tell ${l.guarantors.length} guarantor${l.guarantors.length === 1 ? '' : 's'} that ${l.borrower}'s loan is ${l.days} days behind?`))) return
  busy.value = l.id
  try {
    await api.post(`/loans/${l.id}/warn-guarantors`)
    toast('The guarantors have been told')
    await load()
  } catch (e) {
    toast(e instanceof ApiError ? e.message : 'Couldn\'t tell them.', 'error')
  } finally {
    busy.value = 0
  }
}

const noting = ref<Late | null>(null)
const form = reactive({ kind: 'note' as 'note' | 'promise', note: '', promise_date: '' })
const noteError = ref('')
function openNote(l: Late) {
  noting.value = l
  Object.assign(form, { kind: 'note', note: '', promise_date: '' })
  noteError.value = ''
}
async function saveNote() {
  if (!noting.value) return
  noteError.value = ''
  try {
    await api.post(`/loans/${noting.value.id}/followups`, form)
    toast('Saved')
    noting.value = null
    await load()
  } catch (e) {
    noteError.value = e instanceof ApiError ? e.message : 'Couldn\'t save.'
  }
}
</script>

<template>
  <div>
    <PageHeader title="Arrears follow-up">
      Every late loan: how late, how much, who to call, and what the office has done about it. Members are reminded by the system the day an instalment becomes late; this page is for the office's own follow-up.
    </PageHeader>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
    <div v-if="!data && !error" class="card mt-5"><SkeletonRows :rows="5" /></div>

    <template v-if="data">
      <p v-if="data.payroll_behind" class="mt-4 flex gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <ExclamationTriangleIcon class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
        <span>
          <strong>Post the payroll first:</strong> {{ data.last_payroll ? `the last one posted was for ${monthName(data.last_payroll)}` : 'no payroll has been posted yet' }}.
          <span class="phone-hide">Repayments come off salaries, so many of these loans may not really be late.</span> Guarantors are never told automatically while the payroll is behind.
        </span>
      </p>

      <section class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Summary">
        <div class="card p-4"><p class="eyebrow">Late loans</p><p class="mt-1.5 text-2xl font-bold tabular-nums">{{ data.loans.length }}</p></div>
        <div class="card p-4"><p class="eyebrow">Amount late</p><p class="mt-1.5 text-2xl font-bold tabular-nums">{{ formatMoney(data.overdue_total) }}</p></div>
        <div class="card p-4"><p class="eyebrow">Promises due</p><p class="mt-1.5 text-2xl font-bold tabular-nums" :class="promisesDue ? 'text-amber-700' : ''">{{ promisesDue }}</p><p class="text-xs text-gray-500">today or past</p></div>
        <div class="card p-4"><p class="eyebrow">Guarantors told automatically</p><p class="mt-1.5 text-sm font-semibold">{{ data.warn_after_days ? `After ${data.warn_after_days} days late` : 'Off' }}</p><p class="text-xs text-gray-500">Setup › Reminders</p></div>
      </section>

      <div class="mt-4 flex flex-wrap items-center gap-2">
        <button
          v-for="f in FILTERS"
          :key="f.key"
          type="button"
          class="tap rounded-full border px-3.5 py-1.5 text-sm font-semibold"
          :class="filter === f.key ? 'border-brand bg-brand text-white' : 'border-line bg-surface text-gray-600 hover:bg-gray-50'"
          :aria-pressed="filter === f.key"
          @click="filter = f.key"
        >{{ f.label }} <span class="ml-0.5 text-xs opacity-75">{{ count(f.key) }}</span></button>
        <input v-model="q" type="search" class="input ml-auto w-full sm:w-64" placeholder="Search a name or loan" aria-label="Search the late loans" />
      </div>

      <div v-if="!shown.length" class="card mt-4"><EmptyState :title="data.loans.length ? 'None here' : 'No late loans'" :text="data.loans.length ? 'No late loan matches what is chosen.' : 'Every running loan is up to date.'" compact /></div>
      <ul v-else class="mt-4 space-y-3">
        <li v-for="l in shown" :key="l.id" class="card p-4 sm:p-5">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <RouterLink :to="{ name: 'loan', params: { id: l.id } }" class="font-semibold hover:text-brand">{{ l.borrower }}</RouterLink>
              <p class="text-xs text-gray-500">{{ l.loan_no }} · {{ l.product }}<template v-if="l.account_no"> · {{ l.account_no }}</template></p>
              <p class="mt-1.5 flex flex-wrap items-center gap-2 text-sm">
                <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="lateTone(l.days)">{{ l.days }} days late</span>
                <span><strong class="tabular-nums">{{ formatMoney(l.overdue) }}</strong> late · {{ formatMoney(l.total_left) }} left to pay</span>
              </p>
            </div>
            <a v-if="l.phone" :href="`tel:${l.phone}`" class="btn-secondary shrink-0"><PhoneIcon class="h-4 w-4" />{{ l.phone }}</a>
            <span v-else class="text-xs text-gray-400">No phone recorded</span>
          </div>

          <p v-if="l.promise" class="mt-2 text-sm" :class="l.promise <= today ? 'font-semibold text-amber-800' : 'text-gray-700'">
            Promised to pay by {{ formatDate(l.promise) }}<template v-if="l.promise < today"> · past</template><template v-else-if="l.promise === today"> · today</template>
          </p>
          <p v-if="l.guarantors.length" class="mt-2 text-sm text-gray-600">
            <UserGroupIcon class="mr-1 inline h-4 w-4 align-text-bottom text-gray-400" aria-hidden="true" />Guarantors:
            <template v-for="(g, i) in l.guarantors" :key="i">{{ i ? ', ' : '' }}{{ g.full_name }} ({{ formatMoney(g.amount) }})</template>
          </p>
          <p v-else class="mt-2 text-sm text-gray-400">No guarantors</p>

          <ul v-if="l.history.length" class="mt-3 space-y-1 border-l-2 border-line pl-3 text-xs text-gray-600">
            <li v-for="(h, i) in l.history" :key="i">
              <span class="font-semibold text-gray-700">{{ KIND[h.kind] }}</span> · {{ when(h.created_at) }}<template v-if="h.by_name"> · {{ h.by_name }}</template>
              <template v-if="h.promise_date"> · by {{ formatDate(h.promise_date) }}</template><template v-if="h.note">: {{ h.note }}</template>
            </li>
          </ul>

          <div v-if="canAct" class="mt-3 flex flex-wrap gap-2 border-t border-line pt-3">
            <button type="button" class="btn-secondary" :disabled="busy === l.id" @click="remind(l)"><BellAlertIcon class="h-4 w-4" />Remind</button>
            <button type="button" class="btn-secondary" :disabled="busy === l.id || !l.guarantors.length" @click="warn(l)"><UserGroupIcon class="h-4 w-4" />Tell guarantors</button>
            <button type="button" class="btn-secondary" @click="openNote(l)"><ChatBubbleLeftEllipsisIcon class="h-4 w-4" />Note or promise</button>
          </div>
        </li>
      </ul>
    </template>

    <Modal v-if="noting" :title="`Follow-up: ${noting.borrower}`" @close="noting = null">
      <form class="space-y-4" @submit.prevent="saveNote">
        <div class="flex rounded-lg bg-gray-100 p-0.5 text-sm font-semibold" role="radiogroup">
          <button v-for="k in (['note', 'promise'] as const)" :key="k" type="button" role="radio" :aria-checked="form.kind === k" class="flex-1 rounded-md px-3 py-1.5" :class="form.kind === k ? 'bg-surface text-ink shadow-sm' : 'text-gray-600'" @click="form.kind = k">
            {{ k === 'note' ? 'A note' : 'A promise to pay' }}
          </button>
        </div>
        <div v-if="noteError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ noteError }}</div>
        <div v-if="form.kind === 'promise'">
          <label for="fu-date" class="label">They will pay by</label>
          <input id="fu-date" v-model="form.promise_date" type="date" class="input" :min="today" />
        </div>
        <div>
          <label for="fu-note" class="label">{{ form.kind === 'promise' ? 'Note (optional)' : 'What happened' }}</label>
          <textarea id="fu-note" v-model="form.note" class="input min-h-[5rem]" maxlength="500" placeholder="e.g. Called; salary was delayed, will pay at month end" />
        </div>
        <div class="sheet-actions flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="noting = null">Cancel</button>
          <button type="submit" class="btn-primary">Save</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
