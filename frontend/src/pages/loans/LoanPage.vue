<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import type { Installment, Loan, LoanPayment, PickedMember } from '@/types'
import { formatDate, formatMoney } from '@/money'
import { guarantorStatus, loanStatus } from '@/loans'
import StatusBadge from '@/components/StatusBadge.vue'
import Modal from '@/components/Modal.vue'
import MemberPicker from '@/components/MemberPicker.vue'

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

type Dialog = 'reject' | 'disburse' | 'payment' | 'reverse' | 'guarantor' | null
const dialog = ref<Dialog>(null)
const form = reactive({ note: '', date: today, reference: '', amount: '', reason: '' })
const newGuarantor = ref<(PickedMember & { amount: string }) | null>(null)
const reversing = ref<LoanPayment | null>(null)
const formError = ref('')
const busy = ref(false)

function open(d: Dialog, payment?: LoanPayment) {
  Object.assign(form, { note: '', date: today, reference: '', amount: '', reason: '' })
  newGuarantor.value = null
  reversing.value = payment ?? null
  formError.value = ''
  if (d === 'payment' && loan.value?.position) form.amount = loan.value.position.next_due_amount
  dialog.value = d
}

async function act(path: string, body: Record<string, unknown> = {}, confirmText?: string) {
  if (confirmText && !confirm(confirmText)) return
  formError.value = ''
  error.value = ''
  busy.value = true
  try {
    const res = await api.post<{ loan: Loan } | null>(path, body)
    if (res && 'loan' in res) loan.value = res.loan
    else await load()
    dialog.value = null
  } catch (e) {
    const message = e instanceof ApiError ? e.message : 'Something went wrong. Please try again.'
    if (dialog.value) formError.value = message
    else error.value = message
  } finally {
    busy.value = false
  }
}

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
</script>

<template>
  <div>
    <RouterLink :to="canAdmin ? { name: 'admin-loans' } : { name: 'loans' }" class="text-sm font-medium text-gray-600 hover:text-gray-900">
      ← {{ canAdmin ? 'Loan applications' : 'Loans' }}
    </RouterLink>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <template v-if="loan">
      <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-xl font-semibold tabular-nums">{{ formatMoney(loan.amount) }} <span class="font-normal text-gray-500">over {{ loan.months }} months</span></h1>
          <p class="mt-1 text-sm text-gray-600">
            {{ loan.loan_no }}<template v-if="!isOwner"> · {{ loan.borrower_name }} (<span class="font-mono">{{ loan.borrower_account_no }}</span>)</template>
            · {{ loan.purpose }} · {{ loan.interest_rate_monthly }}% a month, reducing balance
          </p>
        </div>
        <StatusBadge :label="loanStatus[loan.status].label" :style-class="loanStatus[loan.status].style" />
      </div>

      <!-- Where it stands, and what can be done next -->
      <div class="card mt-5 p-5">
        <p v-if="loanStatus[loan.status].explain && (isOwner || loan.status === 'closed')" class="text-sm text-gray-700">{{ loanStatus[loan.status].explain }}</p>
        <p v-if="loan.status === 'rejected'" class="text-sm text-gray-700">Rejected: {{ loan.decision_note }}</p>
        <p v-if="loan.disbursed_on" class="text-sm text-gray-700">Paid out on {{ formatDate(loan.disbursed_on) }} · {{ loan.disbursement_ref }}</p>

        <div v-if="loan.position && loan.status !== 'closed'" class="mt-3 grid gap-4 sm:grid-cols-4">
          <div><p class="text-sm text-gray-600">Still to pay</p><p class="text-lg font-semibold tabular-nums">{{ formatMoney(loan.position.total_left) }}</p></div>
          <div><p class="text-sm text-gray-600">Of which principal</p><p class="text-lg font-semibold tabular-nums">{{ formatMoney(loan.position.principal_left) }}</p></div>
          <div><p class="text-sm text-gray-600">Next payment</p><p class="text-lg font-semibold tabular-nums">{{ formatMoney(loan.position.next_due_amount) }}</p><p class="text-xs text-gray-500">{{ formatDate(loan.position.next_due_date) }}</p></div>
          <div v-if="Number(loan.position.overdue) > 0"><p class="text-sm text-red-700">Overdue</p><p class="text-lg font-semibold tabular-nums text-red-700">{{ formatMoney(loan.position.overdue) }}</p></div>
        </div>

        <div v-if="canAdmin && loan.borrower_eligibility && ['pending_guarantors', 'pending_approval', 'approved'].includes(loan.status)" class="mt-3 rounded-lg bg-gray-50 p-3 text-sm text-gray-700">
          Borrower's savings today {{ formatMoney(loan.borrower_eligibility.savings) }}<template v-if="Number(loan.borrower_eligibility.owed) > 0">, owing {{ formatMoney(loan.borrower_eligibility.owed) }} on other loans</template>
          → limit {{ formatMoney(loan.borrower_eligibility.limit) }}.
          <span v-if="Number(loan.borrower_eligibility.limit) < Number(loan.amount)" class="font-medium text-amber-800">This loan is now above their limit.</span>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
          <template v-if="canAdmin">
            <button v-if="loan.status === 'pending_approval'" type="button" class="btn-primary" :disabled="busy" @click="act(`/loans/${loan.id}/approve`, {}, `Approve ${loan.borrower_name}'s loan of ${formatMoney(loan.amount)}?`)">Approve</button>
            <button v-if="loan.status === 'approved'" type="button" class="btn-primary" @click="open('disburse')">Record the payout</button>
            <button v-if="loan.status === 'active'" type="button" class="btn-primary" @click="open('payment')">Record a repayment</button>
            <button v-if="['pending_guarantors', 'pending_approval', 'approved'].includes(loan.status)" type="button" class="btn-secondary" @click="open('reject')">Reject</button>
          </template>
          <template v-if="isOwner">
            <button v-if="loan.status === 'pending_guarantors'" type="button" class="btn-primary" @click="open('guarantor')">Ask another guarantor</button>
            <button v-if="['pending_guarantors', 'pending_approval', 'approved'].includes(loan.status)" type="button" class="btn-secondary" :disabled="busy" @click="act(`/loans/${loan.id}/cancel`, {}, 'Cancel this loan application?')">Cancel application</button>
          </template>
        </div>
      </div>

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
            <StatusBadge :label="guarantorStatus[g.status].label" :style-class="guarantorStatus[g.status].style" />
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

      <!-- Repayments -->
      <section v-if="loan.payments?.length" class="mt-8">
        <h2 class="font-semibold">Repayments</h2>
        <ul class="card mt-3 divide-y divide-gray-100">
          <li v-for="p in loan.payments" :key="p.id" class="flex flex-wrap items-center justify-between gap-2 p-4 text-sm" :class="{ 'text-gray-400': p.reversed_by }">
            <div>
              <p class="font-medium" :class="{ 'line-through': p.reversed_by }">
                {{ p.reversal_of ? 'Reversal' : 'Repayment' }} · <span class="tabular-nums">{{ formatMoney(p.amount, { sign: true }) }}</span>
              </p>
              <p class="text-gray-600">{{ formatDate(p.paid_on) }}<template v-if="p.reference"> · {{ p.reference }}</template><template v-if="p.narration"> · {{ p.narration }}</template><template v-if="p.created_by_name"> · by {{ p.created_by_name }}</template></p>
            </div>
            <button v-if="canAdmin && !p.reversal_of && !p.reversed_by" type="button" class="btn-ghost px-2 py-1" @click="open('reverse', p)">Reverse</button>
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
      <form class="space-y-4" @submit.prevent="act(`/loans/${loan.id}/disburse`, { disbursed_on: form.date, reference: form.reference })">
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <p class="text-sm text-gray-700">Record the cheque issued to {{ loan.borrower_name }} for <strong class="font-semibold">{{ formatMoney(loan.amount) }}</strong>. The repayment schedule starts from this date.</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <div><label for="ref" class="label">Cheque number</label><input id="ref" v-model="form.reference" class="input" maxlength="80" /></div>
          <div><label for="date" class="label">Date issued</label><input id="date" v-model="form.date" type="date" :max="today" class="input" /></div>
        </div>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="dialog = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !form.reference.trim()">Record payout</button>
        </div>
      </form>
    </Modal>

    <Modal v-if="dialog === 'payment' && loan" title="Record a repayment" @close="dialog = null">
      <form class="space-y-4" @submit.prevent="act(`/loans/${loan.id}/payments`, { amount: form.amount, paid_on: form.date, reference: form.reference })">
        <div v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</div>
        <p v-if="loan.position" class="text-sm text-gray-700">{{ formatMoney(loan.position.total_left) }} still owed. A repayment pays the oldest instalment first: interest, then principal.</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <div><label for="amt" class="label">Amount</label><input id="amt" v-model="form.amount" class="input tabular-nums" inputmode="decimal" /></div>
          <div><label for="pdate" class="label">Date paid</label><input id="pdate" v-model="form.date" type="date" :max="today" class="input" /></div>
        </div>
        <div><label for="pref" class="label">Reference <span class="font-normal text-gray-400">(optional)</span></label><input id="pref" v-model="form.reference" class="input" maxlength="80" placeholder="Receipt or payroll month" /></div>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="dialog = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !form.amount">Record repayment</button>
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
