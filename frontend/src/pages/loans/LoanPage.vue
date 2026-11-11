<script setup lang="ts">
import CreditBadge from '@/components/CreditBadge.vue'
import { downloadLoanLetter } from '@/documents'
import MoneyAccountSelect from '@/components/MoneyAccountSelect.vue'
import { ask } from '@/confirm'
import { computed, reactive, ref, watch } from 'vue'
import { CheckCircleIcon, DocumentTextIcon, ExclamationTriangleIcon, ShieldCheckIcon, XCircleIcon } from '@heroicons/vue/24/outline'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import type { Installment, Loan, LoanCharge, LoanPayment, LoanSecurity, PaymentReceipt, PickedMember, LoanGuarantor } from '@/types'
import { formatDate, formatMoney } from '@/money'
import { guarantorStatus, loanStatus, creditSummary, loanFigures } from '@/loans'
import StatusBadge from '@/components/StatusBadge.vue'
import Modal from '@/components/Modal.vue'
import MemberPicker from '@/components/MemberPicker.vue'
import SecurityFields from '@/components/SecurityFields.vue'

/** One loan. The borrower can cancel it or ask another guarantor; admins move it along. */
const route = useRoute()
const auth = useAuth()
const id = Number(route.params.id)

const loan = ref<Loan | null>(null)
const error = ref('')

const isOwner = computed(() => loan.value?.user_id === auth.user?.id)
// Admins handle other people's loans, never their own
const canAdmin = computed(() => auth.isAdmin && !isOwner.value)
const today = new Date().toLocaleDateString('en-CA')

async function load() {
  try {
    loan.value = (await api.get<{ loan: Loan }>(`/loans/${id}`)).loan
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load this loan.'
  }
}
load()

// --- Actions: each opens a small form in a dialog --------------------------------------

type Dialog = 'reject' | 'disburse' | 'payment' | 'reverse' | 'guarantor' | 'settle' | 'waive' | 'approve' | null | 'replace' | 'security'
const dialog = ref<Dialog>(null)
const form = reactive({ note: '', date: today, reference: '', amount: '', reason: '', message: '', money_account_id: '' })
const newGuarantor = ref<(PickedMember & { amount: string }) | null>(null)
const reversing = ref<LoanPayment | null>(null)
const waiving = ref<LoanCharge | null>(null)
const formError = ref('')
const replacing = ref<LoanGuarantor | null>(null)
const busy = ref(false)

function open(d: Dialog, payment?: LoanPayment) {
  Object.assign(form, { note: '', date: today, reference: '', amount: '', reason: '' })
  newGuarantor.value = null
  reversing.value = payment ?? null
  formError.value = ''
  if (d === 'payment' && loan.value?.position) form.amount = loan.value.position.next_due_amount
  if (d === 'approve' && loan.value) {
    const fee = Number(loan.value.processing_fee || 0)
    form.message = `Your loan ${loan.value.loan_no} of ${formatMoney(loan.value.amount)} has been approved. `
      + `Please pick up your cheque${fee ? ` for ${formatMoney(String(Number(loan.value.amount) - fee))} (after the ${formatMoney(loan.value.processing_fee)} processing fee)` : ''} from the SACCO office.`
  }
  dialog.value = d
}

async function act(path: string, body: Record<string, unknown> = {}, confirmText?: string) {
  if (confirmText && !await ask(confirmText)) return
  formError.value = ''
  error.value = ''
  busy.value = true
  try {
    const res = await api.post<{ loan: Loan; receipt?: PaymentReceipt } | null>(path, body)
    if (res && 'loan' in res) loan.value = res.loan
    else await load()
    receipt.value = res && 'receipt' in res ? res.receipt ?? null : null
    dialog.value = null
  } catch (e) {
    const message = e instanceof ApiError ? e.message : 'Something went wrong. Please try again.'
    if (dialog.value) formError.value = message
    else error.value = message
  } finally {
    busy.value = false
  }
}

// --- Repayments: a live preview while typing, and a receipt after ----------------------

/** Exactly what the repayment being typed would do (worked out by the server, then undone) */
const preview = ref<PaymentReceipt | null>(null)
const previewError = ref('')
/** The last repayment recorded on this page */
const receipt = ref<PaymentReceipt | null>(null)
let previewTimer: ReturnType<typeof setTimeout> | undefined
watch(() => [dialog.value, form.amount, form.date], () => {
  clearTimeout(previewTimer)
  preview.value = null
  previewError.value = ''
  if (dialog.value !== 'payment' || !loan.value || !Number(form.amount.replace(/[,\s]/g, ''))) return
  previewTimer = setTimeout(async () => {
    try {
      preview.value = (await api.get<{ receipt: PaymentReceipt | null }>(`/loans/${loan.value!.id}/payment-preview`, { amount: form.amount, paid_on: form.date })).receipt
    } catch (e) {
      previewError.value = e instanceof ApiError ? e.message : ''
    }
  }, 300)
})

// --- Schedule ------------------------------------------------------------------------

const schedule = computed<Installment[]>(() => loan.value?.installments?.length ? loan.value.installments : (loan.value?.projected ?? []))
function installmentState(i: Installment) {
  if (!i.principal_paid) return null
  const due = Number(i.principal) + Number(i.interest)
  const paid = Number(i.principal_paid) + Number(i.interest_paid)
  if (paid >= due) return { label: 'Paid', style: 'text-green-700' }
  if (i.due_date < today) return { label: paid > 0 ? 'Part paid · overdue' : 'Overdue', style: 'text-red-700' }
  return paid > 0 ? { label: 'Part paid', style: 'text-gray-700' } : { label: '', style: '' }
}

// --- Loan security (collateral) ---------------------------------------------------------

const SECURITY_KINDS = ['Land title', 'Car logbook', 'Household items', 'Business equipment', 'Fixed deposit', 'Other']
const blankSecurity = () => ({ kind: '', description: '', value: '', reference: '', kept_at: '' })
const security = reactive(blankSecurity())
/** In the payout dialog: whether a security is being recorded with it */
const withSecurity = ref(false)
const figures = computed(() => (loan.value ? loanFigures(loan.value) : null))
const securityTotal = computed(() => (loan.value?.securities ?? []).filter((x) => !x.returned_on).reduce((sum, x) => sum + Number(x.value ?? 0), 0))

function openSecurity() {
  Object.assign(security, blankSecurity())
  formError.value = ''
  dialog.value = 'security'
}

/** Saves the security being typed; true when it worked (or nothing was typed) */
async function saveSecurity(): Promise<boolean> {
  if (!loan.value) return false
  try {
    const res = await api.post<{ securities: LoanSecurity[] }>(`/loans/${loan.value.id}/securities`, { ...security })
    loan.value.securities = res.securities
    Object.assign(security, blankSecurity())
    return true
  } catch (e) {
    formError.value = e instanceof ApiError ? e.message : 'Couldn\'t save the security.'
    return false
  }
}

async function addSecurity() {
  busy.value = true
  if (await saveSecurity()) dialog.value = null
  busy.value = false
}

/** Payout, with the security first when one was filled in */
async function disburse() {
  if (!loan.value) return
  formError.value = ''
  if (withSecurity.value) {
    busy.value = true
    const ok = await saveSecurity()
    busy.value = false
    if (!ok) return
    withSecurity.value = false
  }
  await act(`/loans/${loan.value.id}/disburse`, { disbursed_on: form.date, reference: form.reference, money_account_id: form.money_account_id })
}

async function securityAction(x: LoanSecurity, action: 'return' | 'remove') {
  if (!loan.value) return
  const text = action === 'return'
    ? `Mark "${x.kind}: ${x.description}" as handed back to ${loan.value.borrower_name}?`
    : `Remove "${x.kind}: ${x.description}"? Only do this if it was entered by mistake.`
  if (!await ask(text)) return
  error.value = ''
  try {
    const res = action === 'return'
      ? await api.post<{ securities: LoanSecurity[] }>(`/loans/${loan.value.id}/securities/${x.id}/return`, {})
      : await api.delete<{ securities: LoanSecurity[] }>(`/loans/${loan.value.id}/securities/${x.id}`)
    loan.value.securities = res.securities
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Something went wrong. Please try again.'
  }
}

const letter = () => downloadLoanLetter(loan.value!, { sacco: auth.sacco, user: auth.user?.full_name ?? null })
</script>

<template>
  <div>
    <RouterLink :to="canAdmin ? { name: 'admin-loans' } : { name: 'loans' }" class="text-sm font-medium text-gray-600 hover:text-gray-900">
      ← {{ canAdmin ? 'Loans' : 'My loans' }}
    </RouterLink>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <template v-if="loan">
      <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-2xl font-bold tabular-nums">{{ formatMoney(loan.amount) }} <span class="font-normal text-gray-500">over {{ loan.months }} months</span></h1>
          <p class="mt-1 text-sm text-gray-600">
            {{ loan.loan_no }}<template v-if="loan.product_name"> · {{ loan.product_name }}</template><template v-if="!isOwner"> · {{ loan.borrower_name }} (<span class="font-mono">{{ loan.borrower_account_no }}</span>)</template>
            · {{ loan.purpose }} · {{ loan.interest_rate_monthly }}% a month, reducing balance
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <button v-if="['approved', 'active', 'closed'].includes(loan.status) && (isOwner || auth.isAdmin)" type="button" class="btn-secondary" @click="letter">
            <DocumentTextIcon class="h-4 w-4" aria-hidden="true" />Approval letter
          </button>
          <StatusBadge :label="loanStatus[loan.status].label" :style-class="loanStatus[loan.status].style" />
        </div>
      </div>

      <!-- The repayment just recorded -->
      <div v-if="receipt" class="mt-5 flex gap-4 rounded-2xl border border-emerald-100 bg-emerald-50 p-4" role="status">
        <CheckCircleIcon class="h-6 w-6 shrink-0 text-emerald-700" aria-hidden="true" />
        <div class="min-w-0 flex-1 text-sm">
          <p class="font-semibold text-emerald-800">{{ formatMoney(receipt.amount) }} recorded{{ receipt.closed ? ' · the loan is fully repaid' : '' }}</p>
          <p class="mt-1 text-gray-700">
            Went to
            <template v-if="Number(receipt.penalties)">penalties {{ formatMoney(receipt.penalties) }}, </template>
            interest {{ formatMoney(receipt.interest) }} and principal {{ formatMoney(receipt.principal) }}.
            Owed went from {{ formatMoney(receipt.owed_before) }} to <strong class="font-semibold">{{ formatMoney(receipt.owed_after) }}</strong>.
            <template v-if="!receipt.closed && receipt.next_due_date"> Next due: {{ formatMoney(receipt.next_due_amount) }} on {{ formatDate(receipt.next_due_date) }}.</template>
          </p>
        </div>
        <button type="button" class="self-start text-xs font-semibold text-gray-500 hover:text-gray-800" @click="receipt = null">Dismiss</button>
      </div>

      <!-- Does the borrower qualify? (admins, before payout) -->
      <section v-if="canAdmin && loan.assessment" class="card mt-5 overflow-hidden">
        <div
          class="flex items-center gap-3 px-5 py-4"
          :class="{
            'bg-emerald-50': loan.assessment.verdict === 'qualifies',
            'bg-amber-50': loan.assessment.verdict === 'qualifies_with_concerns',
            'bg-red-50': loan.assessment.verdict === 'does_not_qualify',
          }"
        >
          <ShieldCheckIcon v-if="loan.assessment.verdict === 'qualifies'" class="h-6 w-6 text-emerald-700" aria-hidden="true" />
          <ExclamationTriangleIcon v-else-if="loan.assessment.verdict === 'qualifies_with_concerns'" class="h-6 w-6 text-amber-700" aria-hidden="true" />
          <XCircleIcon v-else class="h-6 w-6 text-red-700" aria-hidden="true" />
          <div>
            <p class="font-semibold" :class="{ 'text-emerald-800': loan.assessment.verdict === 'qualifies', 'text-amber-800': loan.assessment.verdict === 'qualifies_with_concerns', 'text-red-800': loan.assessment.verdict === 'does_not_qualify' }">
              {{ loan.assessment.verdict === 'qualifies' ? 'Qualifies' : loan.assessment.verdict === 'qualifies_with_concerns' ? 'Qualifies, but look at the warning' : 'Does not qualify yet' }}
            </p>
            <p class="text-xs text-gray-600">Assessment against the SACCO's rules, with today's figures</p>
          </div>
        </div>
        <div v-if="loan.borrower_credit" class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3 text-sm">
          <span class="font-medium">Repayment record</span>
          <CreditBadge :credit="loan.borrower_credit" />
          <span class="text-gray-600">{{ creditSummary(loan.borrower_credit) }}<template v-if="loan.borrower_credit.grade === 'poor'"> As a slow payer they can borrow {{ loan.borrower_credit.limit_percent }}% of the usual limit.</template></span>
        </div>
        <ul class="divide-y divide-line">
          <li v-for="c in loan.assessment.checks" :key="c.label" class="flex gap-3 px-5 py-3 text-sm">
            <span
              class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold"
              :class="{ 'bg-emerald-50 text-emerald-700': c.level === 'pass', 'bg-amber-50 text-amber-700': c.level === 'warn', 'bg-red-50 text-red-700': c.level === 'fail' }"
              :aria-label="c.level"
            >{{ c.level === 'pass' ? '✓' : c.level === 'warn' ? '!' : '✕' }}</span>
            <div>
              <p class="font-medium">{{ c.label }}</p>
              <p class="text-gray-600">{{ c.detail }}</p>
            </div>
          </li>
        </ul>
      </section>

      <!-- Where it stands, and what can be done next -->
      <div class="card mt-5 p-5">
        <p v-if="loanStatus[loan.status].explain && (isOwner || loan.status === 'closed')" class="text-sm text-gray-700">{{ loanStatus[loan.status].explain }}</p>
        <p v-if="loan.status === 'rejected'" class="text-sm text-gray-700">Rejected: {{ loan.decision_note }}</p>
        <p v-if="loan.disbursed_on" class="text-sm text-gray-700">Paid out on {{ formatDate(loan.disbursed_on) }} · {{ loan.disbursement_ref }}</p>

        <!-- The money: borrowed, interest, total, paid, left -->
        <template v-if="figures?.known && !['rejected', 'cancelled'].includes(loan.status)">
          <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3" :class="loan.position ? 'lg:grid-cols-5' : ''">
            <div><dt class="text-sm text-gray-600">Amount borrowed</dt><dd class="text-lg font-semibold tabular-nums">{{ formatMoney(String(figures.borrowed)) }}</dd></div>
            <div><dt class="text-sm text-gray-600">Interest{{ loan.position ? '' : ' (expected)' }}</dt><dd class="text-lg font-semibold tabular-nums">{{ formatMoney(String(figures.interest)) }}</dd></div>
            <div><dt class="text-sm text-gray-600">Total to repay</dt><dd class="text-lg font-semibold tabular-nums">{{ formatMoney(String(figures.total)) }}</dd></div>
            <template v-if="loan.position">
              <div><dt class="text-sm text-gray-600">Amount paid</dt><dd class="text-lg font-semibold tabular-nums">{{ formatMoney(String(figures.paid)) }}</dd></div>
              <div><dt class="text-sm text-gray-600">Still to pay</dt><dd class="text-lg font-semibold tabular-nums">{{ formatMoney(String(figures.left)) }}</dd>
                <p v-if="figures.penalties > 0" class="text-xs text-amber-800">includes {{ formatMoney(String(figures.penalties)) }} penalties</p></div>
            </template>
          </dl>
          <div v-if="loan.position" class="mt-3">
            <div class="h-2 overflow-hidden rounded-full bg-gray-100" role="progressbar" :aria-valuenow="figures.percentPaid" aria-valuemin="0" aria-valuemax="100" aria-label="Share of the loan repaid">
              <div class="h-full rounded-full bg-brand transition-all" :style="{ width: figures.percentPaid + '%' }" />
            </div>
            <p class="mt-1 text-xs text-gray-600">{{ figures.percentPaid }}% repaid</p>
          </div>
          <div v-if="loan.position && loan.status === 'active'" class="mt-3 flex flex-wrap gap-x-8 gap-y-2 border-t border-line pt-3 text-sm">
            <p><span class="text-gray-600">Next payment</span> <span class="font-semibold tabular-nums">{{ formatMoney(loan.position.next_due_amount) }}</span> <span class="text-gray-600">on {{ formatDate(loan.position.next_due_date) }}</span></p>
            <p><span class="text-gray-600">Principal left</span> <span class="font-semibold tabular-nums">{{ formatMoney(loan.position.principal_left) }}</span> · <span class="text-gray-600">interest left</span> <span class="font-semibold tabular-nums">{{ formatMoney(loan.position.interest_left) }}</span></p>
            <p v-if="Number(loan.position.overdue) > 0" class="font-semibold text-red-700">Overdue <span class="tabular-nums">{{ formatMoney(loan.position.overdue) }}</span></p>
          </div>
        </template>

        <div v-if="canAdmin && loan.borrower_eligibility && ['pending_guarantors', 'pending_approval', 'approved'].includes(loan.status)" class="mt-3 rounded-lg bg-gray-50 p-3 text-sm text-gray-700">
          Borrower's savings today {{ formatMoney(loan.borrower_eligibility.savings) }}<template v-if="Number(loan.borrower_eligibility.owed) > 0">, owing {{ formatMoney(loan.borrower_eligibility.owed) }} on other loans</template>
          → limit {{ formatMoney(loan.borrower_eligibility.limit) }}.
          <span v-if="Number(loan.borrower_eligibility.limit) < Number(loan.amount)" class="font-medium text-amber-800">This loan is now above their limit.</span>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
          <template v-if="canAdmin">
            <button v-can="'loans'" v-if="loan.status === 'pending_approval'" type="button" class="btn-primary" :disabled="busy" @click="open('approve')">Approve…</button>
            <button v-can="'loans'" v-if="loan.status === 'approved'" type="button" class="btn-primary" @click="open('disburse')">Record the payout</button>
            <button v-can="'loans'" v-if="loan.status === 'active'" type="button" class="btn-primary" @click="open('payment')">Record a repayment</button>
            <button v-can="'loans'" v-if="loan.status === 'active' && loan.settlement" type="button" class="btn-secondary" @click="open('settle')">Pay off early</button>
            <button v-can="'loans'" v-if="['pending_guarantors', 'pending_approval', 'approved'].includes(loan.status)" type="button" class="btn-secondary" @click="open('reject')">Reject</button>
          </template>
          <template v-if="isOwner">
            <button v-if="loan.status === 'pending_guarantors'" type="button" class="btn-primary" @click="open('guarantor')">Ask another guarantor</button>
            <button v-if="['pending_guarantors', 'pending_approval', 'approved'].includes(loan.status)" type="button" class="btn-secondary" :disabled="busy" @click="act(`/loans/${loan.id}/cancel`, {}, 'Cancel this loan application?')">Cancel application</button>
          </template>
        </div>
      </div>

      <!-- Loan security (collateral) -->
      <section v-if="loan.securities && (loan.securities.length || (canAdmin && !['closed', 'rejected', 'cancelled'].includes(loan.status)))" class="mt-8">
        <div class="flex flex-wrap items-end justify-between gap-2">
          <div>
            <h2 class="font-semibold">Loan security</h2>
            <p class="text-sm text-gray-600">
              <template v-if="loan.securities.length">What {{ isOwner ? 'you' : loan.borrower_name }} left with the SACCO<template v-if="securityTotal"> · worth about {{ formatMoney(String(securityTotal)) }}</template>. Handed back once the loan is fully repaid.</template>
              <template v-else>None recorded. If the borrower leaves something as security (a land title, a logbook…), record it here.</template>
            </p>
          </div>
          <button v-can="'loans'" v-if="canAdmin && !['closed', 'rejected', 'cancelled'].includes(loan.status)" type="button" class="btn-secondary" @click="openSecurity">Add security</button>
        </div>
        <ul v-if="loan.securities.length" class="card mt-3 divide-y divide-line">
          <li v-for="x in loan.securities" :key="x.id" class="flex flex-wrap items-center justify-between gap-2 p-4 text-sm">
            <div class="min-w-0">
              <p class="font-medium">{{ x.kind }} · {{ x.description }}</p>
              <p class="text-gray-600">
                <template v-if="x.value">Worth about <span class="tabular-nums">{{ formatMoney(x.value) }}</span></template>
                <template v-if="x.reference"><template v-if="x.value"> · </template>No. {{ x.reference }}</template>
                <template v-if="x.kept_at"> · kept: {{ x.kept_at }}</template>
              </p>
              <p class="text-xs text-gray-500">Recorded {{ formatDate(x.created_at) }}<template v-if="x.created_by_name"> by {{ x.created_by_name }}</template></p>
            </div>
            <span class="flex shrink-0 items-center gap-2">
              <span v-if="x.returned_on" class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-800">Returned {{ formatDate(x.returned_on) }}</span>
              <span v-else class="rounded-full bg-navy-50 px-2 py-0.5 text-xs font-semibold text-navy-800">Held by the SACCO</span>
              <template v-if="canAdmin && !x.returned_on">
                <button v-can="'loans'" v-if="loan.status !== 'active'" type="button" class="btn-ghost px-2 py-1 text-xs" @click="securityAction(x, 'return')">Mark returned</button>
                <button v-can="'loans'" type="button" class="btn-ghost px-2 py-1 text-xs" @click="securityAction(x, 'remove')">Remove</button>
              </template>
            </span>
          </li>
        </ul>
      </section>

      <!-- Guarantors -->
      <section v-if="loan.guarantors?.length" class="mt-8">
        <h2 class="font-semibold">Guarantors</h2>
        <p v-if="loan.guarantee_shortfall" class="mt-1 text-sm text-amber-800">
          Still needs
          <template v-if="loan.guarantee_shortfall.guarantors">{{ loan.guarantee_shortfall.guarantors }} more guarantor{{ loan.guarantee_shortfall.guarantors === 1 ? '' : 's' }}</template>
          <template v-if="loan.guarantee_shortfall.guarantors && Number(loan.guarantee_shortfall.amount) > 0"> and </template>
          <template v-if="Number(loan.guarantee_shortfall.amount) > 0">{{ formatMoney(loan.guarantee_shortfall.amount) }} more in guarantees</template>.
        </p>
        <ul class="card mt-3 divide-y divide-gray-100">
          <li v-for="g in loan.guarantors" :key="g.id" class="flex items-center justify-between gap-2 p-4 text-sm">
            <div>
              <p class="font-medium">{{ g.full_name }}</p>
              <p v-if="g.status === 'accepted' && g.requested_amount && Number(g.amount) !== Number(g.requested_amount)" class="text-gray-600 tabular-nums">
                Agreed to {{ formatMoney(g.amount) }} <span class="text-amber-800">of the {{ formatMoney(g.requested_amount) }} asked</span>
              </p>
              <p v-else class="text-gray-600 tabular-nums">{{ g.status === 'accepted' ? 'Guarantees' : 'Asked for' }} {{ formatMoney(g.amount) }}</p>
            </div>
            <span class="flex shrink-0 items-center gap-2">
              <button
                v-if="g.status === 'accepted' && (isOwner || auth.can('loans')) && ['pending_approval', 'approved', 'active'].includes(loan.status) && !(loan.guarantors ?? []).some((x) => x.replaces_id === g.id && x.status === 'pending')"
                type="button"
                class="btn-ghost px-2 py-1 text-xs"
                @click="replacing = g; newGuarantor = null; open('replace')"
              >Replace</button>
              <StatusBadge :label="guarantorStatus[g.status].label" :style-class="guarantorStatus[g.status].style" />
            </span>
          </li>
        </ul>
      </section>

      <!-- Schedule -->
      <section v-if="schedule.length" class="mt-8">
        <h2 class="font-semibold">{{ loan.installments?.length ? 'Repayment schedule' : 'Expected schedule' }}</h2>
        <p v-if="!loan.installments?.length" class="mt-1 text-sm text-gray-600">If paid out today. The dates are fixed when the loan is paid out.</p>
        <div class="card mt-3 overflow-x-auto">
          <table class="w-full text-sm tabular-nums">
            <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
              <tr>
                <th class="px-4 py-3">#</th>
                <th class="px-4 py-3">Due</th>
                <th class="px-4 py-3 text-right">Principal</th>
                <th class="px-4 py-3 text-right">Interest</th>
                <th class="px-4 py-3 text-right">Payment</th>
                <th v-if="loan.installments?.length" class="px-4 py-3">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="i in schedule" :key="i.number">
                <td class="px-4 py-2">{{ i.number }}</td>
                <td class="whitespace-nowrap px-4 py-2">{{ formatDate(i.due_date) }}</td>
                <td class="px-4 py-2 text-right">{{ formatMoney(i.principal) }}</td>
                <td class="px-4 py-2 text-right">{{ formatMoney(i.interest) }}</td>
                <td class="px-4 py-2 text-right font-medium">{{ formatMoney(String(Number(i.principal) + Number(i.interest))) }}</td>
                <td v-if="loan.installments?.length" class="whitespace-nowrap px-4 py-2 text-sm" :class="installmentState(i)?.style">{{ installmentState(i)?.label }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Penalties and other charges -->
      <section v-if="loan.charges?.length" class="mt-8">
        <h2 class="font-semibold">Penalties</h2>
        <ul class="card mt-3 divide-y divide-line">
          <li v-for="c in loan.charges" :key="c.id" class="flex flex-wrap items-center justify-between gap-2 p-4 text-sm" :class="{ 'text-gray-400': c.waived_at }">
            <div>
              <p class="font-medium" :class="{ 'line-through': c.waived_at }">Late-payment penalty · <span class="tabular-nums">{{ formatMoney(c.amount) }}</span></p>
              <p class="text-gray-600">{{ formatDate(c.charged_on) }}<template v-if="c.note"> · {{ c.note }}</template></p>
            </div>
            <div class="flex items-center gap-2">
              <span v-if="!c.waived_at" class="text-xs font-semibold" :class="Number(c.paid) >= Number(c.amount) ? 'text-emerald-700' : 'text-amber-700'">
                {{ Number(c.paid) >= Number(c.amount) ? 'Paid' : `${formatMoney(String(Number(c.amount) - Number(c.paid)))} to pay` }}
              </span>
              <button v-can="'loans'" v-if="canAdmin && !c.waived_at && Number(c.paid) < Number(c.amount)" type="button" class="btn-ghost px-2 py-1" @click="waiving = c; open('waive')">Waive</button>
            </div>
          </li>
        </ul>
      </section>

      <!-- Repayments -->
      <section v-if="loan.payments?.length" class="mt-8">
        <h2 class="font-semibold">Repayments</h2>
        <ul class="card mt-3 divide-y divide-gray-100">
          <li v-for="p in loan.payments" :key="p.id" class="flex flex-wrap items-center justify-between gap-2 p-4 text-sm" :class="{ 'text-gray-400': p.reversed_by }">
            <div>
              <p class="font-medium" :class="{ 'line-through': p.reversed_by }">
                {{ p.reversal_of ? 'Reversal' : 'Repayment' }} · <span class="tabular-nums">{{ formatMoney(p.amount, { sign: true }) }}</span>
              </p>
              <p v-if="p.split && !p.reversed_by" class="mt-1 flex flex-wrap gap-1.5 text-xs">
                <span v-if="p.split.penalty" class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-800">Penalty {{ formatMoney(p.split.penalty) }}</span>
                <span v-if="p.split.interest" class="rounded-full bg-navy-50 px-2 py-0.5 text-navy-800">Interest {{ formatMoney(p.split.interest) }}</span>
                <span v-if="p.split.principal" class="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-800">Principal {{ formatMoney(p.split.principal) }}</span>
              </p>
              <p class="text-gray-600">{{ formatDate(p.paid_on) }}<template v-if="p.reference"> · {{ p.reference }}</template><template v-if="p.narration"> · {{ p.narration }}</template><template v-if="p.created_by_name"> · by {{ p.created_by_name }}</template></p>
            </div>
            <button v-can="'loans'" v-if="canAdmin && !p.reversal_of && !p.reversed_by" type="button" class="btn-ghost px-2 py-1" @click="open('reverse', p)">Reverse</button>
          </li>
        </ul>
      </section>
    </template>

    <!-- Dialogs -->
    <Modal v-if="dialog === 'reject' && loan" title="Reject this loan" @close="dialog = null">
      <form class="space-y-4" @submit.prevent="act(`/loans/${loan.id}/reject`, { note: form.note })">
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <div>
          <label for="note" class="label">Reason (the member will see this)</label>
          <input id="note" v-model="form.note" class="input" maxlength="255" />
        </div>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="dialog = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !form.note.trim()">Reject loan</button>
        </div>
      </form>
    </Modal>

    <Modal v-if="dialog === 'disburse' && loan" title="Record the payout" @close="dialog = null">
      <form class="space-y-4" @submit.prevent="disburse">
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <p class="text-sm text-gray-700">Record the cheque issued to {{ loan.borrower_name }}. The repayment schedule starts from this date.</p>
        <dl class="grid grid-cols-2 gap-y-1 rounded-xl bg-gray-50 p-3 text-sm">
          <dt class="text-gray-600">Loan</dt><dd class="text-right tabular-nums">{{ formatMoney(loan.amount) }}</dd>
          <template v-if="Number(loan.processing_fee) > 0">
            <dt class="text-gray-600">Processing fee</dt><dd class="text-right tabular-nums">−{{ formatMoney(loan.processing_fee) }}</dd>
          </template>
          <dt class="font-semibold">Cheque amount</dt><dd class="text-right font-semibold tabular-nums">{{ formatMoney(String(Number(loan.amount) - Number(loan.processing_fee || 0))) }}</dd>
        </dl>
        <div class="grid gap-4 sm:grid-cols-2">
          <div><label for="ref" class="label">Cheque number</label><input id="ref" v-model="form.reference" class="input" maxlength="80" /></div>
          <MoneyAccountSelect id="d-money" v-model="form.money_account_id" label="Paid from" class="sm:col-span-2" />
          <div><label for="date" class="label">Date issued</label><input id="date" v-model="form.date" type="date" :max="today" class="input" /></div>
        </div>
        <div class="rounded-xl border border-line p-3">
          <p v-if="loan.securities?.length" class="mb-2 text-sm text-gray-700">Security already recorded: {{ loan.securities.map((x) => x.kind).join(', ') }}.</p>
          <label class="flex items-center gap-2 text-sm font-medium">
            <input v-model="withSecurity" type="checkbox" class="h-4 w-4 accent-brand" />
            The borrower is leaving {{ loan.securities?.length ? 'another' : 'a' }} security (land title, logbook…)
          </label>
          <div v-if="withSecurity" class="mt-3">
            <SecurityFields :security="security" :kinds="SECURITY_KINDS" />
          </div>
        </div>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="dialog = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !form.reference.trim() || (withSecurity && (!security.kind || !security.description.trim()))">Record payout</button>
        </div>
      </form>
    </Modal>

    <Modal v-if="dialog === 'security' && loan" title="Add loan security" @close="dialog = null">
      <form class="space-y-4" @submit.prevent="addSecurity">
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <p class="text-sm text-gray-700">Something {{ loan.borrower_name }} leaves with the SACCO for loan {{ loan.loan_no }}. They see it on their loan page, and the office is reminded to hand it back once the loan is repaid.</p>
        <SecurityFields :security="security" :kinds="SECURITY_KINDS" />
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="dialog = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !security.kind || !security.description.trim()">Save security</button>
        </div>
      </form>
    </Modal>

    <Modal v-if="dialog === 'payment' && loan" title="Record a repayment" @close="dialog = null">
      <form class="space-y-4" @submit.prevent="act(`/loans/${loan.id}/payments`, { money_account_id: form.money_account_id, amount: form.amount, paid_on: form.date, reference: form.reference })">
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <p v-if="loan.position" class="text-sm text-gray-700">
          {{ formatMoney(loan.position.total_left) }} still owed. A repayment clears any penalty first, then the oldest instalment: its interest, then principal.
        </p>
        <div class="grid gap-4 sm:grid-cols-2">
          <div><label for="amt" class="label">Amount</label><input id="amt" v-model="form.amount" class="input tabular-nums" inputmode="decimal" /></div>
          <div><label for="pdate" class="label">Date paid</label><input id="pdate" v-model="form.date" type="date" :max="today" class="input" /></div>
        </div>
        <MoneyAccountSelect id="p-money" v-model="form.money_account_id" label="Paid into" />
        <div><label for="pref" class="label">Reference <span class="font-normal text-gray-400">(optional)</span></label><input id="pref" v-model="form.reference" class="input" maxlength="80" placeholder="Receipt or payroll month" /></div>

        <!-- What this repayment will do -->
        <div v-if="preview" class="rounded-xl border border-line bg-gray-50 p-4 text-sm" aria-live="polite">
          <p class="eyebrow">This repayment</p>
          <dl class="mt-2 space-y-1">
            <div v-if="Number(preview.penalties)" class="flex justify-between"><dt class="text-gray-600">Clears penalties</dt><dd class="tabular-nums">{{ formatMoney(preview.penalties) }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-600">Pays interest</dt><dd class="tabular-nums">{{ formatMoney(preview.interest) }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-600">Reduces the principal by</dt><dd class="tabular-nums">{{ formatMoney(preview.principal) }}</dd></div>
          </dl>
          <div class="mt-3 grid grid-cols-2 gap-3 border-t border-line pt-3">
            <div>
              <p class="text-xs text-gray-500">Still owed after</p>
              <p class="text-lg font-bold tabular-nums">{{ preview.closed ? 'Nothing: repaid' : formatMoney(preview.owed_after) }}</p>
            </div>
            <div v-if="!preview.closed && preview.next_due_date">
              <p class="text-xs text-gray-500">Next amount due</p>
              <p class="text-lg font-bold tabular-nums">{{ formatMoney(preview.next_due_amount) }}</p>
              <p class="text-xs text-gray-500">{{ formatDate(preview.next_due_date) }}<template v-if="Number(preview.overdue_after)"> · {{ formatMoney(preview.overdue_after) }} still late</template></p>
            </div>
          </div>
        </div>
        <p v-else-if="previewError" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ previewError }}</p>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="dialog = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !form.amount || !!previewError">Record repayment</button>
        </div>
      </form>
    </Modal>

    <Modal v-if="dialog === 'reverse' && reversing" title="Reverse a repayment" @close="dialog = null">
      <form class="space-y-4" @submit.prevent="act(`/loan-payments/${reversing.id}/reverse`, { reason: form.reason })">
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <p class="text-sm text-gray-700">The {{ formatMoney(reversing.amount) }} repayment of {{ formatDate(reversing.paid_on) }} will no longer count. Both entries stay in the history.</p>
        <div><label for="reason" class="label">Why?</label><input id="reason" v-model="form.reason" class="input" maxlength="255" placeholder="e.g. Cheque bounced" /></div>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="dialog = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !form.reason.trim()">Reverse repayment</button>
        </div>
      </form>
    </Modal>

    <Modal v-if="dialog === 'approve' && loan" title="Approve this loan" @close="dialog = null">
      <form class="space-y-4" @submit.prevent="act(`/loans/${loan.id}/approve`, { message: form.message })">
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <p v-if="loan.assessment && loan.assessment.verdict !== 'qualifies'" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
          The assessment flagged something. Check it before approving.
        </p>
        <dl class="grid grid-cols-2 gap-y-1 rounded-xl bg-gray-50 p-3 text-sm">
          <dt class="text-gray-600">Borrower</dt><dd class="text-right font-medium">{{ loan.borrower_name }}</dd>
          <dt class="text-gray-600">Loan</dt><dd class="text-right tabular-nums">{{ formatMoney(loan.amount) }} · {{ loan.months }} months</dd>
        </dl>
        <div>
          <label for="amsg" class="label">Message to {{ loan.borrower_name.split(' ')[0] }}</label>
          <textarea id="amsg" v-model="form.message" class="input min-h-[6rem]" maxlength="2000" />
          <p class="mt-1 text-xs text-gray-500">They see it under the bell when they sign in. Add when and where to pick up the cheque.</p>
        </div>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="dialog = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !form.message.trim()">Approve and send message</button>
        </div>
      </form>
    </Modal>

    <Modal v-if="dialog === 'settle' && loan?.settlement" title="Pay off early" @close="dialog = null">
      <form class="space-y-4" @submit.prevent="act(`/loans/${loan.id}/settle`, { reference: form.reference, money_account_id: form.money_account_id })">
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <p class="text-sm text-gray-700">
          {{ loan.borrower_name }} pays everything off today: the principal still owed, any penalties, and interest only up to this month.
        </p>
        <dl class="grid grid-cols-2 gap-y-1 rounded-xl bg-gray-50 p-3 text-sm">
          <dt class="font-semibold">To pay today</dt><dd class="text-right font-semibold tabular-nums">{{ formatMoney(loan.settlement.amount) }}</dd>
          <dt class="text-gray-600">Interest they save</dt><dd class="text-right tabular-nums text-emerald-700">{{ formatMoney(loan.settlement.interest_saved) }}</dd>
        </dl>
        <MoneyAccountSelect id="s-money" v-model="form.money_account_id" label="Paid into" />
        <div><label for="sref" class="label">Reference <span class="font-normal text-gray-400">(optional)</span></label><input id="sref" v-model="form.reference" class="input" maxlength="80" placeholder="Receipt or bank slip" /></div>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="dialog = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy">Record payment and close loan</button>
        </div>
      </form>
    </Modal>

    <Modal v-if="dialog === 'waive' && waiving" title="Waive a penalty" @close="dialog = null">
      <form class="space-y-4" @submit.prevent="act(`/loan-charges/${waiving.id}/waive`, { reason: form.reason })">
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <p class="text-sm text-gray-700">The {{ formatMoney(waiving.amount) }} penalty of {{ formatDate(waiving.charged_on) }} will no longer be owed.</p>
        <div><label for="wreason" class="label">Why?</label><input id="wreason" v-model="form.reason" class="input" maxlength="200" placeholder="e.g. Payroll was late, not the member" /></div>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="dialog = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !form.reason.trim()">Waive penalty</button>
        </div>
      </form>
    </Modal>

    <Modal v-if="dialog === 'replace' && loan && replacing" :title="`Replace ${replacing.full_name}`" @close="dialog = null">
      <form class="space-y-4" @submit.prevent="newGuarantor && act(`/loans/${loan.id}/guarantors/${replacing.id}/replace`, { user_id: newGuarantor.id, amount: newGuarantor.amount })">
        <p class="text-sm text-gray-700">Choose a member to take over {{ replacing.full_name }}'s guarantee of {{ formatMoney(replacing.amount) }}. They are asked first; {{ replacing.full_name }} is released once they agree.</p>
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <MemberPicker
          v-if="!newGuarantor"
          :exclude="[loan.user_id, ...(loan.guarantors ?? []).map((g) => g.guarantor_id)]"
          @pick="newGuarantor = { ...$event, amount: replacing!.amount }"
        />
        <div v-else class="flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 p-3">
          <p class="flex-1 font-medium">{{ newGuarantor.full_name }}</p>
          <input v-model="newGuarantor.amount" class="input w-40 tabular-nums" inputmode="numeric" aria-label="Amount to guarantee" />
          <button type="button" class="btn-ghost px-2 py-1" @click="newGuarantor = null">Change</button>
        </div>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="dialog = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !newGuarantor">Ask them</button>
        </div>
      </form>
    </Modal>

    <Modal v-if="dialog === 'guarantor' && loan" title="Ask another guarantor" @close="dialog = null">
      <form class="space-y-4" @submit.prevent="newGuarantor && act(`/loans/${loan.id}/guarantors`, { user_id: newGuarantor.id, amount: newGuarantor.amount })">
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <MemberPicker
          v-if="!newGuarantor"
          :exclude="(loan.guarantors ?? []).filter((g) => g.status !== 'declined').map((g) => g.guarantor_id)"
          @pick="newGuarantor = { ...$event, amount: loan.guarantee_shortfall?.amount ?? '' }"
        />
        <div v-else class="flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 p-3">
          <p class="flex-1 font-medium">{{ newGuarantor.full_name }}</p>
          <input v-model="newGuarantor.amount" class="input w-40 tabular-nums" inputmode="numeric" aria-label="Amount to guarantee" />
          <button type="button" class="btn-ghost px-2 py-1" @click="newGuarantor = null">Change</button>
        </div>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="dialog = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !newGuarantor || !newGuarantor.amount">Send request</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
