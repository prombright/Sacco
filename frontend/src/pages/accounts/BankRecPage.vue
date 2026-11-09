<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftIcon, CheckCircleIcon, ExclamationTriangleIcon, InformationCircleIcon, PlusIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { ask } from '@/confirm'
import { confetti } from '@/motion'
import { formatDate, formatMoney } from '@/money'
import type { Report } from '@/reports'
import ReportView from '@/components/ReportView.vue'
import Modal from '@/components/Modal.vue'

interface Account { id: number; code: string; name: string; balance: string }
interface Rec {
  id: number
  account_id: number
  code: string
  account_name: string
  statement_date: string
  statement_balance: string
  status: 'open' | 'completed'
  completed_by_name: string | null
  completed_at: string | null
}
interface Item { key: string; date: string; details: string; reference: string | null; amount: string; ticked: boolean }
interface Detail {
  reconciliation: Rec & { can_tick: boolean }
  items: Item[]
  statement_balance: string
  cleared: string
  difference: string
  book_balance: string
  report: Report
}

/** Agreeing a bank account in the books with its statement, by ticking what reached the bank */
const route = useRoute()
const router = useRouter()
const auth = useAuth()
const id = computed(() => (route.params.id ? Number(route.params.id) : null))
const today = new Date().toLocaleDateString('en-CA')

const list = ref<{ accounts: Account[]; reconciliations: Rec[] } | null>(null)
const data = ref<Detail | null>(null)
const error = ref('')
const busy = ref(false)

async function load() {
  error.value = ''
  data.value = null
  try {
    if (id.value) data.value = await api.get<Detail>(`/bank-recs/${id.value}`)
    else list.value = await api.get('/bank-recs')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load this.'
  }
}
onMounted(load)
watch(id, load)

async function run(fn: () => Promise<void>) {
  busy.value = true
  error.value = ''
  try {
    await fn()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'That didn\'t work.'
  } finally {
    busy.value = false
  }
}

// --- Starting one ---
const starting = ref(false)
const form = reactive({ account_id: '', statement_date: today, statement_balance: '' })
const formError = ref('')
function start() {
  form.account_id = list.value?.accounts[0] ? String(list.value.accounts[0].id) : ''
  form.statement_balance = ''
  formError.value = ''
  starting.value = true
}
async function create() {
  formError.value = ''
  try {
    const res = await api.post<{ id: number }>('/bank-recs', form)
    starting.value = false
    router.push({ name: 'bank-rec-detail', params: { id: res.id } })
  } catch (e) {
    const err = e as ApiError
    if (err.errors?.open_id) router.push({ name: 'bank-rec-detail', params: { id: err.errors.open_id } })
    formError.value = err.message
  }
}

// --- Ticking ---
const show = ref<'all' | 'open' | 'ticked'>('all')
const q = ref('')
const items = computed(() => (data.value?.items ?? []).filter((i) => {
  if (show.value === 'open' && i.ticked) return false
  if (show.value === 'ticked' && !i.ticked) return false
  return !q.value || `${i.details} ${i.reference ?? ''} ${i.amount}`.toLowerCase().includes(q.value.toLowerCase())
}))
const editable = computed(() => !!data.value?.reconciliation.can_tick && auth.can('money'))
const agrees = computed(() => !!data.value && Math.abs(Number(data.value.difference)) < 0.005)
function tick(keys: string[], cleared: boolean) {
  return run(async () => {
    data.value = await api.post<Detail>(`/bank-recs/${id.value}/tick`, { keys, cleared })
  })
}
const allShownTicked = computed(() => items.value.length > 0 && items.value.every((i) => i.ticked))

const complete = () => run(async () => {
  data.value = await api.post<Detail>(`/bank-recs/${id.value}/complete`)
  confetti()
})
const reopen = () => run(async () => {
  if (!await ask('Reopen this reconciliation so its ticks can be changed?')) return
  data.value = await api.post<Detail>(`/bank-recs/${id.value}/reopen`)
})
const remove = () => run(async () => {
  if (!await ask('Delete this reconciliation in progress? The ticks on it are lost; the books are not affected.')) return
  await api.delete(`/bank-recs/${id.value}`)
  router.push({ name: 'bank-rec' })
})

const editing = ref(false)
const edit = reactive({ statement_date: '', statement_balance: '' })
function startEdit() {
  Object.assign(edit, { statement_date: data.value!.reconciliation.statement_date, statement_balance: data.value!.statement_balance })
  formError.value = ''
  editing.value = true
}
async function saveEdit() {
  formError.value = ''
  try {
    data.value = await api.patch<Detail>(`/bank-recs/${id.value}`, edit)
    editing.value = false
  } catch (e) {
    formError.value = e instanceof ApiError ? e.message : 'Couldn\'t save.'
  }
}
</script>

<template>
  <div>
    <!-- All reconciliations -->
    <template v-if="!id">
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p class="eyebrow">Cash book</p>
          <h1 class="mt-1 text-2xl font-bold">Bank reconciliation</h1>
          <p class="mt-1 max-w-3xl text-gray-600">Check the books against the bank statement. Enter the statement's closing balance, tick each entry that appears on it, and the difference must come to zero.</p>
        </div>
        <button v-can="'money'" type="button" class="btn-primary" :disabled="!list" @click="start"><PlusIcon class="h-4 w-4" />New reconciliation</button>
      </div>
      <p class="mt-4 flex items-start gap-3 rounded-2xl border border-line bg-surface p-4 text-sm text-gray-700">
        <InformationCircleIcon class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
        <span>If the bank already held money before this system, post one journal entry first: debit Bank account, credit Opening balances. Bank charges or interest on the statement that aren't in the books yet go in <RouterLink :to="{ name: 'payments' }" class="font-semibold text-brand hover:underline">Payments</RouterLink> or <RouterLink :to="{ name: 'receipts' }" class="font-semibold text-brand hover:underline">Receipts</RouterLink>.</span>
      </p>
      <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

      <div v-if="list" class="mt-5 grid gap-4 sm:grid-cols-2">
        <div v-for="a in list.accounts" :key="a.id" class="card p-5">
          <p class="eyebrow">{{ a.code }}</p>
          <p class="mt-1 font-semibold">{{ a.name }}</p>
          <p class="figure mt-2 text-2xl" :class="{ '!text-red-700': Number(a.balance) < 0 }">{{ formatMoney(a.balance) }}</p>
          <p class="text-xs text-gray-500">Balance in the books today</p>
        </div>
      </div>

      <ul v-if="list" class="card mt-5 divide-y divide-line overflow-hidden">
        <li v-for="r in list.reconciliations" :key="r.id">
          <RouterLink :to="{ name: 'bank-rec-detail', params: { id: r.id } }" class="flex flex-wrap items-center gap-3 p-4 transition hover:bg-gray-50">
            <span class="min-w-0 flex-1">
              <span class="block font-semibold">{{ r.code }} {{ r.account_name }}</span>
              <span class="block text-sm text-gray-500">Statement of {{ formatDate(r.statement_date) }}</span>
            </span>
            <span class="font-semibold tabular-nums">{{ formatMoney(r.statement_balance) }}</span>
            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="r.status === 'completed' ? 'bg-emerald-50 text-emerald-700' : 'bg-saffron-100 text-saffron-700'">{{ r.status === 'completed' ? 'Completed' : 'In progress' }}</span>
          </RouterLink>
        </li>
        <li v-if="!list.reconciliations.length" class="p-10 text-center text-sm text-gray-500">No reconciliations yet.</li>
      </ul>
    </template>

    <!-- One reconciliation -->
    <template v-else>
      <RouterLink :to="{ name: 'bank-rec' }" class="inline-flex items-center gap-1 text-sm font-semibold text-gray-600 hover:text-ink"><ArrowLeftIcon class="h-4 w-4" />All reconciliations</RouterLink>
      <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

      <template v-if="data">
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 class="text-2xl font-bold">{{ data.reconciliation.code }} {{ data.reconciliation.account_name }}</h1>
            <p class="text-gray-600">Statement of {{ formatDate(data.reconciliation.statement_date) }}<template v-if="data.reconciliation.completed_by_name"> · completed by {{ data.reconciliation.completed_by_name }}</template></p>
          </div>
          <div class="flex flex-wrap gap-2">
            <template v-if="editable">
              <button type="button" class="btn-ghost !text-red-700" @click="remove">Delete</button>
              <button type="button" class="btn-secondary" @click="startEdit">Change statement</button>
              <button type="button" class="btn-primary" :disabled="!agrees || busy" @click="complete"><CheckCircleIcon class="h-4 w-4" />Complete</button>
            </template>
            <button v-else-if="data.reconciliation.status === 'completed' && auth.isSuperAdmin" type="button" class="btn-secondary" @click="reopen">Reopen</button>
          </div>
        </div>

        <section class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <div class="card p-5"><p class="eyebrow">Statement balance</p><p class="figure mt-2 text-2xl">{{ formatMoney(data.statement_balance) }}</p></div>
          <div class="card p-5"><p class="eyebrow">Ticked so far</p><p class="figure mt-2 text-2xl">{{ formatMoney(data.cleared) }}</p></div>
          <div class="rounded-2xl border p-5" :class="agrees ? 'border-emerald-200 bg-emerald-50' : 'border-saffron-300 bg-saffron-50'">
            <p class="eyebrow flex items-center gap-1.5">
              <component :is="agrees ? CheckCircleIcon : ExclamationTriangleIcon" class="h-4 w-4" aria-hidden="true" />Difference
            </p>
            <p class="figure mt-2 text-2xl">{{ formatMoney(data.difference) }}</p>
            <p class="mt-1 text-xs text-gray-600">{{ agrees ? 'It agrees with the statement.' : 'Must be zero to complete.' }}</p>
          </div>
          <div class="card p-5"><p class="eyebrow">In the books</p><p class="figure mt-2 text-2xl">{{ formatMoney(data.book_balance) }}</p><p class="mt-1 text-xs text-gray-500">At the statement date</p></div>
        </section>

        <section class="card mt-5 overflow-hidden">
          <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line p-4">
            <h2 class="font-semibold">Entries in the books</h2>
            <div class="flex flex-wrap items-center gap-2">
              <input v-model="q" type="search" class="input !w-44" placeholder="Search" aria-label="Search entries" />
              <div class="flex rounded-lg bg-gray-100 p-0.5 text-xs font-semibold" role="radiogroup" aria-label="Show">
                <button v-for="o in ([['all', 'All'], ['open', 'Unticked'], ['ticked', 'Ticked']] as const)" :key="o[0]" type="button" role="radio" :aria-checked="show === o[0]" class="rounded-md px-2.5 py-1.5" :class="show === o[0] ? 'bg-surface text-ink shadow-sm' : 'text-gray-600'" @click="show = o[0]">{{ o[1] }}</button>
              </div>
              <button v-if="editable && items.length" type="button" class="btn-secondary" :disabled="busy" @click="tick(items.map((i) => i.key), !allShownTicked)">{{ allShownTicked ? 'Untick these' : 'Tick these' }}</button>
            </div>
          </div>
          <ul class="max-h-[32rem] divide-y divide-line overflow-y-auto">
            <li v-for="i in items" :key="i.key">
              <label class="flex items-center gap-3 p-3 sm:px-4" :class="[editable ? 'cursor-pointer hover:bg-gray-50' : '', i.ticked ? 'bg-emerald-50/40' : '']">
                <input type="checkbox" class="h-5 w-5 shrink-0 accent-brand" :checked="i.ticked" :disabled="!editable || busy" :aria-label="`Tick ${i.details}`" @change="tick([i.key], !i.ticked)" />
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-sm font-medium">{{ i.details }}</span>
                  <span class="block truncate text-xs text-gray-500">{{ formatDate(i.date) }}<template v-if="i.reference"> · {{ i.reference }}</template></span>
                </span>
                <span class="whitespace-nowrap text-sm font-semibold tabular-nums" :class="Number(i.amount) >= 0 ? 'text-emerald-700' : 'text-ink'">{{ Number(i.amount) >= 0 ? '+' : '−' }}{{ formatMoney(i.amount.replace('-', '')) }}</span>
              </label>
            </li>
            <li v-if="!items.length" class="p-10 text-center text-sm text-gray-500">Nothing here.</li>
          </ul>
        </section>

        <ReportView class="mt-5" :report="data.report" />
      </template>
      <div v-else-if="!error" class="mt-5 h-40 animate-pulse rounded-2xl bg-surface" aria-hidden="true" />
    </template>

    <Modal v-if="starting" title="New bank reconciliation" @close="starting = false">
      <form class="space-y-4" @submit.prevent="create">
        <div>
          <label for="b-acct" class="label">Account</label>
          <select id="b-acct" v-model="form.account_id" class="input"><option v-for="a in list?.accounts" :key="a.id" :value="String(a.id)">{{ a.code }} · {{ a.name }}</option></select>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div><label for="b-date" class="label">Statement date</label><input id="b-date" v-model="form.statement_date" type="date" :max="today" class="input" /></div>
          <div><label for="b-bal" class="label">Closing balance on the statement</label><input id="b-bal" v-model="form.statement_balance" inputmode="decimal" class="input tabular-nums" /></div>
        </div>
        <p v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="!form.account_id || form.statement_balance === ''">Start</button>
      </form>
    </Modal>

    <Modal v-if="editing" title="Change the statement" @close="editing = false">
      <form class="space-y-4" @submit.prevent="saveEdit">
        <div class="grid gap-3 sm:grid-cols-2">
          <div><label for="e-date" class="label">Statement date</label><input id="e-date" v-model="edit.statement_date" type="date" :max="today" class="input" /></div>
          <div><label for="e-bal" class="label">Closing balance</label><input id="e-bal" v-model="edit.statement_balance" inputmode="decimal" class="input tabular-nums" /></div>
        </div>
        <p v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</p>
        <button type="submit" class="btn-primary w-full">Save</button>
      </form>
    </Modal>
  </div>
</template>
