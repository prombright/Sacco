<script setup lang="ts">
import Crumbs from '@/components/Crumbs.vue'
import { toast } from '@/toast'
import { ask } from '@/confirm'
import SkeletonRows from '@/components/SkeletonRows.vue'
import UserAvatar from '@/components/UserAvatar.vue'
import CreditBadge from '@/components/CreditBadge.vue'
import { creditSummary } from '@/loans'
import type { CreditStanding } from '@/types'
import { downloadMemberCard, downloadMembershipForm, downloadShareCertificate } from '@/documents'
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronDownIcon, DocumentTextIcon, IdentificationIcon, ReceiptPercentIcon, PrinterIcon, CheckBadgeIcon } from '@heroicons/vue/24/outline'
import { loanStatus } from '@/loans'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import type { Account, Balances, NextOfKin, Page, Transaction, User } from '@/types'
import { formatDate, formatMoney } from '@/money'
import MemberDetails from '@/components/MemberDetails.vue'
import OldHistory from '@/components/OldHistory.vue'
import NextOfKinEditor from '@/components/NextOfKinEditor.vue'
import Modal from '@/components/Modal.vue'
import UserForm from './UserForm.vue'
import Money from '@/components/Money.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import EmptyState from '@/components/EmptyState.vue'
import AccountTabs from '@/components/AccountTabs.vue'
import TransactionList from '@/components/TransactionList.vue'
import Pager from '@/components/Pager.vue'
import RecordTransaction from './RecordTransaction.vue'
import ReverseTransaction from './ReverseTransaction.vue'
import FixTransaction from './FixTransaction.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuth()
const id = Number(route.params.id)

const member = ref<User | null>(null)
const balances = ref<Balances | null>(null)
const sharePrice = ref('0')
const credit = ref<CreditStanding | null>(null)
const pledged = ref('0')

/** Their loans and the colleagues' loans they guarantee (GET /members/{id}) */
interface MemberLoan {
  id: number
  loan_no: string
  amount: string
  months: number
  status: keyof typeof loanStatus
  disbursed_on: string | null
  applied_on: string
  closed_on: string | null
  total_left?: string
  overdue?: string
  next_due_date?: string | null
}
interface Guarantee { id: number; loan_no: string; status: string; amount: string; held: string; borrower_name: string }
/** How much they can borrow, and what they stand behind for others (GET /members/{id}) */
interface Borrowing {
  products: { product: string; multiplier: string; limit: string; limit_percent: number; blocked: string | null }[]
  savings: string
  owed: string
  without_guarantor: string
  free_to_guarantee: string
  held_for_own_loans: string
  guaranteed_total: string
  guarantee_held: string
}
const borrowing = ref<Borrowing | null>(null)
/** The main loan type leads; the others are listed under it */
const mainLoan = computed(() => borrowing.value?.products[0] ?? null)
/** Up to their free savings needs no guarantor, but never more than they can borrow at all */
const withoutGuarantor = computed(() =>
  !borrowing.value || !mainLoan.value || mainLoan.value.blocked ? 0 : Math.min(Number(borrowing.value.without_guarantor), Number(mainLoan.value.limit)),
)
const loans = ref<MemberLoan[]>([])
const loanOwed = ref('0')
const loanOverdue = ref('0')
const guarantees = ref<Guarantee[]>([])
const runningLoans = computed(() => loans.value.filter((l) => l.status === 'active').length)
const shareCount = computed(() => {
  const price = Number(sharePrice.value)
  return price > 0 && balances.value ? Math.floor(Number(balances.value.shares) / price) : null
})

/** The page's tabs, kept in the address so Back returns to the same one */
const TABS = [
  { key: 'money', label: 'Money' },
  { key: 'loans', label: 'Loans' },
  { key: 'details', label: 'Details & next of kin' },
  { key: 'old', label: 'Old system' },
] as const
type Tab = (typeof TABS)[number]['key']
const tab = computed<Tab>({
  get: () => (TABS.some((t) => t.key === route.query.tab) ? (route.query.tab as Tab) : 'money'),
  set: (v) => router.replace({ query: { ...route.query, tab: v === 'money' ? undefined : v } }),
})
const docsOpen = ref(false)

/** The office marks a member as a slow payer, or lifts it (the system never does) */
const slowOpen = ref(false)
const slowNote = ref('')
const slowError = ref('')
const slowBusy = ref(false)
const slowPercent = ref(50)
function openSlow() {
  slowOpen.value = true
  slowNote.value = ''
  slowError.value = ''
  if (credit.value && !credit.value.marked) {
    api.get<{ settings: { key: string; value: string }[] }>('/settings').then((r) => {
      const v = r.settings.find((x) => x.key === 'slow_payer_limit_percent')?.value
      if (v !== undefined) slowPercent.value = Number(v)
    }).catch(() => {})
  }
}
async function saveSlow() {
  if (!credit.value) return
  slowBusy.value = true
  slowError.value = ''
  try {
    const res = await api.post<{ credit: CreditStanding }>(`/members/${id}/slow-payer`, { slow: !credit.value.marked, note: slowNote.value })
    credit.value = res.credit
    slowOpen.value = false
    toast(res.credit.marked ? 'Marked as a slow payer; they have been told' : 'Mark lifted; their full limit is back')
    loadMember()
  } catch (e) {
    slowError.value = e instanceof ApiError ? e.message : 'Couldn\'t save.'
  } finally {
    slowBusy.value = false
  }
}
const account = ref<Account>('savings')
const page = ref(1)
const ledger = ref<Page<Transaction> | null>(null)
const error = ref('')
const recording = ref(false)
// Office accounts (admin, super admin) have no money of their own: no balances, ledger or next of kin
const isMember = computed(() => member.value?.role === 'member')
const editingDetails = ref(false)
const kin = ref<NextOfKin[]>([])

// Closing the membership: what would be paid out, and what has to be cleared first
interface ExitQuote { savings: string; shares: string; loans_owed: string; blockers: string[] }
const exitQuote = ref<ExitQuote | null>(null)
const exitForm = ref({ reason: '', cheque_no: '', exit_date: new Date().toLocaleDateString('en-CA') })
const exitError = ref('')
const exiting = ref(false)

/** The super admin removes a member added by mistake (only one with no money history) */
const deleteError = ref('')
async function deleteMember() {
  if (!member.value) return
  deleteError.value = ''
  if (!await ask(`Delete ${member.value.full_name} for good? Use this only for a member added by mistake or twice. Their details, next of kin and messages go too; the audit report keeps who they were.`)) return
  try {
    await api.delete(`/users/${id}`)
    toast(`${member.value.full_name} was deleted`)
    router.replace({ name: 'users' })
  } catch (e) {
    deleteError.value = e instanceof ApiError ? e.message : 'Could not delete the member.'
  }
}
async function openExit() {
  exitError.value = ''
  exitQuote.value = await api.get<ExitQuote>(`/members/${id}/exit`)
}
async function closeMembership() {
  exitError.value = ''
  exiting.value = true
  try {
    await api.post(`/members/${id}/exit`, exitForm.value)
    exitQuote.value = null
    await loadMember()
    await loadLedger()
  } catch (e) {
    exitError.value = e instanceof ApiError ? e.message : 'Couldn\'t close the membership.'
  } finally {
    exiting.value = false
  }
}
async function loadKin() {
  kin.value = (await api.get<{ next_of_kin: NextOfKin[] }>(`/members/${id}/next-of-kin`)).next_of_kin
}
loadKin().catch(() => {})
const reversing = ref<Transaction | null>(null)
/** The super admin correcting or deleting an entry made by mistake */
const fixing = ref<{ t: Transaction; mode: 'edit' | 'delete' } | null>(null)

// An admin's own account is handled by another admin
// Your own member account, also from your office account: another admin handles its money
const isSelf = id === auth.user?.id || id === auth.user?.linked_member_id

async function loadMember() {
  try {
    const res = await api.get<{
      user: User; balances: Balances; share_price: string; credit: CreditStanding; pledged: string
      loans: MemberLoan[]; loan_owed: string; loan_overdue: string; guarantees: Guarantee[]; borrowing: Borrowing
    }>(`/members/${id}`)
    borrowing.value = res.borrowing ?? null
    credit.value = res.credit
    loans.value = res.loans ?? []
    loanOwed.value = res.loan_owed ?? '0'
    loanOverdue.value = res.loan_overdue ?? '0'
    guarantees.value = res.guarantees ?? []
    pledged.value = res.pledged
    member.value = res.user
    balances.value = res.balances
    sharePrice.value = res.share_price
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load this member.'
  }
}

async function loadLedger() {
  try {
    ledger.value = await api.get<Page<Transaction>>(`/members/${id}/transactions`, { account: account.value, page: page.value })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the entries.'
  }
}

function saved(newBalances: Balances) {
  recording.value = false
  reversing.value = null
  fixing.value = null
  balances.value = newBalances
  if (page.value === 1) loadLedger()
  else page.value = 1 // the watch reloads
}

watch(account, () => { page.value = 1; loadLedger() })
watch(page, loadLedger)
loadMember()
loadLedger()

const who = () => ({ sacco: auth.sacco, user: auth.user?.full_name ?? null })
function certificate() {
  const capital = Number(balances.value?.shares ?? 0)
  const price = Number(sharePrice.value)
  return downloadShareCertificate(member.value!, price > 0 ? Math.floor(capital / price) : 0, price, capital, who())
}
</script>

<template>
  <div>
    <Crumbs :trail="member ? [{ label: member.full_name }] : []" />

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <div v-if="!member && !error" class="mt-4 space-y-4" aria-busy="true">
      <div class="card h-36 animate-pulse" />
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-5"><div v-for="n in 5" :key="n" class="card h-24 animate-pulse" /></div>
    </div>

    <template v-if="member">
      <!-- Who they are, and what can be done for them -->
      <section class="card mt-3 p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="flex min-w-0 items-center gap-4">
            <UserAvatar :name="member.full_name" :photo-url="member.photo_url" size="h-16 w-16 text-xl" />
            <div class="min-w-0">
              <h1 class="text-2xl font-bold tracking-tight sm:text-[1.7rem]">{{ member.full_name }}</h1>
              <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-600">
                <span>Account <span class="font-mono font-medium text-ink">{{ member.account_no }}</span></span>
                <span v-if="member.employee_no">· Employee {{ member.employee_no }}</span>
                <span v-if="!isMember" class="rounded-full bg-navy-50 px-2 py-0.5 text-xs font-semibold text-navy-800">{{ member.role_label }}</span>
                <span v-if="member.office_role" class="rounded-full bg-saffron-100 px-2 py-0.5 text-xs font-semibold text-saffron-800" title="They switch to the office with the round button">Office: {{ member.office_role === 'super_admin' ? 'Super admin' : 'Admin' }}</span>
                <span v-if="member.status !== 'active'" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800">{{ member.status === 'left' ? 'Left the SACCO' : 'Suspended' }}</span>
              </p>
            </div>
          </div>
          <div class="flex flex-wrap gap-2 max-sm:w-full">
            <button v-can="'money'" v-if="isMember && !isSelf" type="button" class="btn-primary max-sm:flex-1" @click="recording = true">Record an entry</button>
            <button v-can="'members'" type="button" class="btn-secondary max-sm:flex-1" @click="editingDetails = true">Edit details</button>
            <!-- The papers for this member, in one menu -->
            <div v-if="isMember" class="relative max-sm:flex-1">
              <button type="button" class="btn-secondary w-full" :aria-expanded="docsOpen" aria-haspopup="menu" @click="docsOpen = !docsOpen">
                <DocumentTextIcon class="h-4 w-4" aria-hidden="true" />Documents<ChevronDownIcon class="h-4 w-4" aria-hidden="true" />
              </button>
              <template v-if="docsOpen">
                <div class="fixed inset-0 z-30" aria-hidden="true" @click="docsOpen = false" />
                <div class="card absolute right-0 z-40 mt-2 w-60 p-1.5 shadow-lift" role="menu" @click="docsOpen = false">
                  <RouterLink :to="{ name: 'member-full-statement', params: { id: member.id } }" class="menu-item font-semibold" role="menuitem"><DocumentTextIcon class="h-4 w-4" />Full statement</RouterLink>
                  <RouterLink :to="{ name: 'member-statement', params: { id: member.id } }" class="menu-item" role="menuitem"><PrinterIcon class="h-4 w-4" />Print statement</RouterLink>
                  <RouterLink :to="{ name: 'member-receipts', params: { id: member.id } }" class="menu-item" role="menuitem"><ReceiptPercentIcon class="h-4 w-4" />Receipts</RouterLink>
                  <button type="button" class="menu-item" role="menuitem" @click="downloadMembershipForm(member, kin, balances, who())"><DocumentTextIcon class="h-4 w-4" />Membership form</button>
                  <button type="button" class="menu-item" role="menuitem" @click="downloadMemberCard(member, who())"><IdentificationIcon class="h-4 w-4" />Member card</button>
                  <button type="button" class="menu-item" role="menuitem" @click="certificate"><CheckBadgeIcon class="h-4 w-4" />Share certificate</button>
                </div>
              </template>
            </div>
          </div>
        </div>
      </section>

      <!-- Office accounts run the SACCO and hold no money of their own -->
      <section v-if="!isMember" class="card mt-4 p-5">
        <p class="eyebrow">{{ member.role_label }} · office account</p>
        <p class="mt-2 text-sm text-gray-700">
          This account {{ member.role === 'super_admin' ? 'runs the SACCO: everything, including the rules, loan products and other admins' : member.role === 'staff' ? 'sees the SACCO\'s records and reports; it approves and changes nothing' : 'runs the SACCO day to day: it approves loans, and does what Setup allows admins to do' }}.
          It has no savings, shares or loans, and can't apply for or guarantee a loan. If this person is also a member, they have a separate member account and log in with that for their own money.
        </p>
      </section>

      <template v-if="isMember">
        <!-- Where they stand, at a glance -->
        <section class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-5" aria-label="Summary">
          <div class="col-span-2 rounded-2xl bg-navy-900 p-4 text-white shadow-lift sm:col-span-1">
            <p class="text-xs font-semibold uppercase tracking-wider text-navy-200">Savings</p>
            <p class="mt-1.5 text-xl font-bold tabular-nums sm:text-2xl"><Money v-if="balances" :value="balances.savings" /></p>
          </div>
          <div class="card p-4">
            <p class="eyebrow">Share capital</p>
            <p class="mt-1.5 text-xl font-bold tabular-nums"><Money v-if="balances" :value="balances.shares" /></p>
            <p v-if="shareCount !== null" class="mt-0.5 text-xs text-gray-500">{{ shareCount.toLocaleString() }} share{{ shareCount === 1 ? '' : 's' }}</p>
          </div>
          <button type="button" class="card p-4 text-left transition hover:border-navy-100" @click="tab = 'loans'">
            <p class="eyebrow">Loans owed</p>
            <p class="mt-1.5 text-xl font-bold tabular-nums"><Money :value="loanOwed" /></p>
            <p class="mt-0.5 text-xs" :class="Number(loanOverdue) > 0 ? 'font-semibold text-red-700' : 'text-gray-500'">
              {{ Number(loanOverdue) > 0 ? `${formatMoney(loanOverdue)} overdue` : runningLoans ? `${runningLoans} running loan${runningLoans === 1 ? '' : 's'}, on time` : 'No running loan' }}
            </p>
          </button>
          <div class="card p-4">
            <p class="eyebrow">Held</p>
            <p class="mt-1.5 text-xl font-bold tabular-nums"><Money :value="pledged" /></p>
            <p class="mt-0.5 text-xs text-gray-500">{{ guarantees.length ? `Guaranteeing ${guarantees.length} loan${guarantees.length === 1 ? '' : 's'}` : 'For their own loans' }}</p>
          </div>
          <div class="card p-4" :class="{ '!border-red-200 !bg-red-50/50': credit?.marked }" :title="credit ? creditSummary(credit) : undefined">
            <p class="eyebrow">Credit standing</p>
            <p v-if="credit" class="mt-2"><CreditBadge :credit="credit" /></p>
            <p v-if="credit?.marked" class="mt-1 text-xs text-red-800">Marked {{ formatDate(credit.marked.since) }}: can borrow {{ credit.limit_percent }}% of the limit</p>
            <p v-else-if="credit" class="mt-1 text-xs text-gray-500">{{ credit.due ? `${credit.on_time} of ${credit.due} paid on time` : 'No repayments yet' }}</p>
            <!-- The office decides, not the system: repayments come off salaries -->
            <button v-can="'loans'" type="button" class="mt-2 text-xs font-semibold text-brand hover:underline" @click="openSlow">
              {{ credit?.marked ? 'Lift the slow-payer mark' : 'Mark as slow payer' }}
            </button>
          </div>
        </section>

        <!-- Borrowing power: what they can borrow now, and what they stand behind for others -->
        <section v-if="borrowing && mainLoan" class="card mt-4 overflow-hidden" aria-label="Borrowing power">
          <div class="grid lg:grid-cols-[1.3fr_1fr_1fr]">
            <div class="border-line p-5 max-lg:border-b lg:border-r">
              <p class="eyebrow">Can borrow now · {{ mainLoan.product }}</p>
              <p class="mt-1.5 text-3xl font-bold tracking-tight tabular-nums" :class="mainLoan.blocked || !Number(mainLoan.limit) ? 'text-gray-400' : 'text-ink'">
                <Money :value="mainLoan.blocked ? '0' : mainLoan.limit" />
              </p>
              <p class="mt-1 text-sm text-gray-600">
                <template v-if="Number(borrowing.owed) > 0">{{ mainLoan.multiplier }}× (savings of {{ formatMoney(borrowing.savings) }} less {{ formatMoney(borrowing.owed) }} still owed)</template><template v-else>{{ mainLoan.multiplier }}× savings of {{ formatMoney(borrowing.savings) }}</template><template v-if="mainLoan.limit_percent < 100">, cut to {{ mainLoan.limit_percent }}% (marked as a slow payer by the office)</template>.
                <span v-if="Number(borrowing.owed) >= Number(borrowing.savings) && Number(borrowing.owed) > 0" class="block text-amber-800">They owe as much as they have saved, so they can borrow more only after repaying.</span>
              </p>
              <p v-if="mainLoan.blocked" class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ mainLoan.blocked.replace(/^You /, 'They ').replace(/ you /g, ' they ').replace(/your /g, 'their ') }}</p>
              <ul v-if="borrowing.products.length > 1" class="mt-3 space-y-1 border-t border-line pt-3 text-sm">
                <li v-for="p in borrowing.products.slice(1)" :key="p.product" class="flex items-baseline justify-between gap-3">
                  <span class="text-gray-600">{{ p.product }} <span class="text-xs text-gray-400">({{ p.multiplier }}× savings)</span></span>
                  <span v-if="p.blocked" class="text-right text-xs text-amber-800">Not now</span>
                  <span v-else class="font-semibold tabular-nums">{{ formatMoney(p.limit) }}</span>
                </li>
              </ul>
            </div>
            <div class="border-line p-5 max-lg:border-b lg:border-r">
              <p class="eyebrow">Without a guarantor</p>
              <p class="mt-1.5 text-2xl font-bold tabular-nums"><Money :value="String(withoutGuarantor)" /></p>
              <p class="mt-1 text-sm text-gray-600">A loan up to their free savings needs no guarantor; above it, guarantors cover the rest.</p>
              <p v-if="Number(borrowing.held_for_own_loans) > 0" class="mt-2 text-xs text-gray-500">{{ formatMoney(borrowing.held_for_own_loans) }} of their savings already stands behind their own loans.</p>
            </div>
            <div class="p-5">
              <p class="eyebrow">Guaranteeing others</p>
              <p class="mt-1.5 text-2xl font-bold tabular-nums"><Money :value="borrowing.guaranteed_total" /></p>
              <p class="mt-1 text-sm text-gray-600">
                <template v-if="guarantees.length">
                  For {{ guarantees.length }} loan{{ guarantees.length === 1 ? '' : 's' }}; {{ formatMoney(borrowing.guarantee_held) }} of it is held now.
                  <button type="button" class="font-semibold text-brand hover:underline" @click="tab = 'loans'">See them</button>
                </template>
                <template v-else>They don't guarantee anyone now.</template>
              </p>
              <p class="mt-2 text-xs text-gray-500">Free to guarantee: <span class="font-semibold text-ink tabular-nums">{{ formatMoney(borrowing.free_to_guarantee) }}</span></p>
            </div>
          </div>
        </section>

        <!-- Tabs -->
        <div class="mt-6 flex gap-1 overflow-x-auto border-b border-line" role="tablist" aria-label="Member">
          <button
            v-for="t in TABS"
            :key="t.key"
            type="button"
            role="tab"
            :aria-selected="tab === t.key"
            class="-mb-px shrink-0 border-b-2 px-3 py-2.5 text-sm font-semibold transition"
            :class="tab === t.key ? 'border-brand text-brand' : 'border-transparent text-gray-500 hover:text-ink'"
            @click="tab = t.key"
          >
            {{ t.label }}<span v-if="t.key === 'loans' && loans.length" class="ml-1.5 rounded-full bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-600">{{ loans.length }}</span>
          </button>
        </div>

        <!-- Money: every entry, account by account -->
        <div v-show="tab === 'money'" class="mt-5" role="tabpanel">
          <AccountTabs v-model="account" />
          <div class="card mt-3 overflow-hidden">
            <SkeletonRows v-if="!ledger" :rows="5" /><TransactionList v-else by-month :transactions="ledger.transactions" :can-reverse="!isSelf" :can-fix="auth.isSuperAdmin && !isSelf" @reverse="reversing = $event" @fix="(t, mode) => (fixing = { t, mode })" />
          </div>
          <Pager v-if="ledger" :page="ledger.page" :per-page="ledger.per_page" :total="ledger.total" @go="page = $event" />
        </div>

        <!-- Loans: theirs, and the ones they guarantee -->
        <div v-if="tab === 'loans'" class="mt-5 space-y-6" role="tabpanel">
          <div>
            <h2 class="font-semibold">Their loans</h2>
            <EmptyState v-if="!loans.length" class="mt-3" title="No loans" text="Loans they apply for, or that were brought in from the old system, show here." compact />
            <ul v-else class="mt-3 grid gap-3 md:grid-cols-2">
              <li v-for="l in loans" :key="l.id">
                <RouterLink :to="{ name: 'loan', params: { id: l.id } }" class="card card-link flex h-full flex-col p-4">
                  <div class="flex items-start justify-between gap-3">
                    <div>
                      <p class="font-semibold tabular-nums"><Money :value="l.amount" /> <span class="font-normal text-gray-500">· {{ l.months }} months</span></p>
                      <p class="mt-0.5 text-xs text-gray-500">{{ l.loan_no }} · {{ l.disbursed_on ? `paid out ${formatDate(l.disbursed_on)}` : `applied ${formatDate(l.applied_on)}` }}</p>
                    </div>
                    <StatusBadge :label="loanStatus[l.status]?.label ?? l.status" :style-class="loanStatus[l.status]?.style ?? ''" />
                  </div>
                  <p v-if="l.total_left" class="mt-3 flex flex-wrap items-baseline justify-between gap-2 border-t border-line pt-3 text-sm">
                    <span class="text-gray-600">Left to pay <span class="font-semibold tabular-nums text-ink">{{ formatMoney(l.total_left) }}</span></span>
                    <span v-if="Number(l.overdue) > 0" class="text-xs font-semibold text-red-700">{{ formatMoney(l.overdue!) }} overdue</span>
                    <span v-else-if="l.next_due_date" class="text-xs text-gray-500">next due {{ formatDate(l.next_due_date) }}</span>
                  </p>
                </RouterLink>
              </li>
            </ul>
          </div>
          <div v-if="guarantees.length">
            <h2 class="font-semibold">Guaranteeing</h2>
            <ul class="card mt-3 divide-y divide-line">
              <li v-for="g in guarantees" :key="g.id">
                <RouterLink :to="{ name: 'loan', params: { id: g.id } }" class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-gray-50">
                  <span class="min-w-0"><span class="block truncate font-medium">{{ g.borrower_name }}</span><span class="text-xs text-gray-500">{{ g.loan_no }}</span></span>
                  <span class="shrink-0 text-right text-sm tabular-nums"><span class="block font-semibold">{{ formatMoney(g.amount) }}</span><span class="text-xs text-gray-500">{{ formatMoney(g.held) }} held now</span></span>
                </RouterLink>
              </li>
            </ul>
          </div>
        </div>

        <!-- Details, next of kin, and closing the membership -->
        <div v-if="tab === 'details'" class="mt-5" role="tabpanel">
          <div class="grid gap-6 lg:grid-cols-2">
            <MemberDetails :user="member" />
            <NextOfKinEditor :people="kin" :save-url="`/members/${member.id}/next-of-kin`" :editable="auth.can('members')" @saved="kin = $event" />
          </div>
          <section v-if="member.status !== 'left' && !isSelf" class="mt-8 rounded-2xl border border-red-200 bg-red-50/40 p-5">
            <h2 class="font-semibold text-red-800">Close membership</h2>
            <p class="mt-1 text-sm text-gray-700">When a member leaves the SACCO: pays out their savings and shares by cheque and marks them as left. They must owe nothing and guarantee nobody first.</p>
            <button type="button" class="btn-secondary mt-3" @click="openExit">Close membership…</button>
          </section>
          <section v-if="auth.isSuperAdmin && !isSelf" class="mt-4 rounded-2xl border border-red-200 p-5">
            <h2 class="font-semibold text-red-800">Delete this member</h2>
            <p class="mt-1 text-sm text-gray-700">For a member added by mistake or twice. Only someone with no money history here can be deleted; anyone who has saved, borrowed, guaranteed or paid a fee keeps their records, so close their membership instead.</p>
            <p v-if="deleteError" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ deleteError }}</p>
            <button type="button" class="btn mt-3 bg-red-600 text-white hover:bg-red-700" @click="deleteMember">Delete member…</button>
          </section>
        </div>

        <!-- What the old system held for them -->
        <div v-if="tab === 'old'" class="mt-5" role="tabpanel">
          <OldHistory :url="`/members/${member.id}/old-history`" show-empty />
        </div>
      </template>
    </template>

    <UserForm v-if="editingDetails && member" :user="member" @close="editingDetails = false" @saved="editingDetails = false; loadMember()" @deleted="toast('The member was deleted'); router.replace({ name: 'users' })" />

    <Modal v-if="slowOpen && member && credit" :title="credit.marked ? `Lift ${member.full_name.split(' ')[0]}'s slow-payer mark` : `Mark ${member.full_name.split(' ')[0]} as a slow payer`" @close="slowOpen = false">
      <form class="space-y-4" @submit.prevent="saveSlow">
        <div class="rounded-xl bg-gray-50 p-3 text-sm">
          <p class="font-medium">Their repayment record</p>
          <p class="mt-0.5 text-gray-600">{{ creditSummary(credit) }}</p>
          <p class="mt-1 text-xs text-gray-500">Repayments come off salaries, so a late one isn't always the member's doing. The system never marks anyone; you decide.</p>
        </div>
        <p v-if="credit.marked" class="text-sm text-gray-700">
          Marked on {{ formatDate(credit.marked.since) }}<template v-if="credit.marked.by"> by {{ credit.marked.by }}</template><template v-if="credit.marked.note">: “{{ credit.marked.note }}”</template>.
          Lifting it gives them their full borrowing limit back.
        </p>
        <p v-else class="text-sm text-gray-700">
          Until the mark is lifted they can borrow up to {{ slowPercent }}% of their usual limit (Setup › Loans). They are told, with your reason.
        </p>
        <div v-if="slowError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ slowError }}</div>
        <div>
          <label for="slow-note" class="label">{{ credit.marked ? 'Note (optional)' : 'Why' }}</label>
          <input id="slow-note" v-model="slowNote" class="input" maxlength="255" :placeholder="credit.marked ? 'e.g. Caught up on all instalments' : 'e.g. Three months unpaid, not through payroll'" />
        </div>
        <div class="sheet-actions flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="slowOpen = false">Cancel</button>
          <button type="submit" class="btn-primary" :class="{ '!bg-red-700 hover:!bg-red-800': !credit.marked }" :disabled="slowBusy || (!credit.marked && !slowNote.trim())">
            {{ credit.marked ? 'Lift the mark' : 'Mark as slow payer' }}
          </button>
        </div>
      </form>
    </Modal>

    <Modal v-if="exitQuote && member" :title="`Close ${member.full_name}'s membership`" @close="exitQuote = null">
      <div class="space-y-4">
        <div v-if="exitError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ exitError }}</div>
        <div v-if="exitQuote.blockers.length" class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900">
          <p class="font-semibold">First clear:</p>
          <ul class="mt-2 list-disc space-y-1 pl-5"><li v-for="b in exitQuote.blockers" :key="b">{{ b }}</li></ul>
        </div>
        <template v-else>
          <dl class="grid grid-cols-2 gap-y-1 rounded-xl bg-gray-50 p-3 text-sm">
            <dt class="text-gray-600">Savings</dt><dd class="text-right tabular-nums">{{ formatMoney(exitQuote.savings) }}</dd>
            <dt class="text-gray-600">Share capital</dt><dd class="text-right tabular-nums">{{ formatMoney(exitQuote.shares) }}</dd>
            <dt class="font-semibold">Cheque amount</dt><dd class="text-right font-semibold tabular-nums">{{ formatMoney(String(Number(exitQuote.savings) + Number(exitQuote.shares))) }}</dd>
          </dl>
          <div><label for="xreason" class="label">Why are they leaving?</label><input id="xreason" v-model="exitForm.reason" class="input" maxlength="255" placeholder="e.g. Left the school, retired" /></div>
          <div class="grid gap-4 sm:grid-cols-2">
            <div><label for="xcheque" class="label">Cheque number</label><input id="xcheque" v-model="exitForm.cheque_no" class="input" maxlength="80" /></div>
            <div><label for="xdate" class="label">Date</label><input id="xdate" v-model="exitForm.exit_date" type="date" class="input" /></div>
          </div>
        </template>
        <div class="sheet-actions flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="exitQuote = null">{{ exitQuote.blockers.length ? 'Close' : 'Cancel' }}</button>
          <button v-if="!exitQuote.blockers.length" type="button" class="btn-primary !bg-red-700 hover:!bg-red-800" :disabled="exiting || !exitForm.reason.trim()" @click="closeMembership">Pay out and close membership</button>
        </div>
      </div>
    </Modal>

    <RecordTransaction v-if="recording && member && balances" :member="member" :account="account" :balances="balances" @close="recording = false" @saved="saved" />
    <ReverseTransaction v-if="reversing" :transaction="reversing" @close="reversing = null" @saved="saved" />
    <FixTransaction v-if="fixing" :transaction="fixing.t" :mode="fixing.mode" @close="fixing = null" @saved="saved" />
  </div>
</template>
