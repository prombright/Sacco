<script setup lang="ts">
import SaccoMark from '@/components/SaccoMark.vue'
import { computed, onMounted, ref } from 'vue'
import type { Component } from 'vue'
import { BanknotesIcon, BellAlertIcon, ChartPieIcon, ClipboardDocumentCheckIcon, Cog6ToothIcon, KeyIcon, WalletIcon } from '@heroicons/vue/24/outline'
import { api, ApiError, http } from '@/api'
import { useAuth } from '@/stores/auth'
import LoanProducts from './LoanProducts.vue'

interface Setting {
  key: string
  label: string
  value: string
  /** A choice from a list instead of a number */
  options: Record<string, string> | null
}

/**
 * How each rule shows: its group, a short name, the unit beside the box, and a line of help.
 * The server's label is the full wording; it is used when a rule has no entry here.
 */
const LOOK: Record<string, { group: string; name: string; unit?: string; help?: string }> = {
  loan_min_member_months: { group: 'loans', name: 'Saving before a first loan', unit: 'months', help: 'How long someone must have saved before they can borrow.' },
  guarantor_min_member_months: { group: 'loans', name: 'Membership to guarantee', unit: 'months', help: 'How long someone must have been a member to stand as a guarantor.' },
  loan_penalty_percent: { group: 'loans', name: 'Late-payment penalty', unit: '%', help: 'Of what is late. 0 means no penalty.' },
  credit_limit_rule: { group: 'loans', name: 'Late repayments lower the limit', help: 'How a member\'s repayment record affects how much they can borrow.' },
  slow_payer_limit_percent: { group: 'loans', name: 'Lowest limit for slow payers', unit: '%', help: 'Of the usual limit. 100 means no cut.' },
  loan_reminder_days: { group: 'reminders', name: 'Remind before an instalment', unit: 'days', help: 'Members get a message this many days before it falls due.' },
  loan_reminders: { group: 'reminders', name: 'Loan reminders', help: 'Due-soon and late messages to members and the super admin. Pause them while catching up old records.' },
  withdrawal_fee_percent: { group: 'savings', name: 'Withdrawal fee', unit: '%', help: 'Of the amount withdrawn.' },
  withdrawal_fee_mode: { group: 'savings', name: 'How the fee is taken' },
  savings_min_balance: { group: 'savings', name: 'Savings that must stay', unit: 'UGX', help: 'Always left in the account; can\'t be withdrawn or moved.' },
  share_price: { group: 'shares', name: 'Price of one share', unit: 'UGX' },
  min_shares: { group: 'shares', name: 'Shares every member holds', unit: 'shares', help: 'The minimum; they can\'t transfer below it.' },
  membership_fee_once: { group: 'shares', name: 'Membership fee (once)', unit: 'UGX', help: '0 means none.' },
  subscription_fee_annual: { group: 'shares', name: 'Subscription (every year)', unit: 'UGX', help: '0 means none.' },
  dividend_rate_percent: { group: 'earnings', name: 'Dividend a year', unit: '%', help: 'Of each member\'s share capital.' },
  profit_basis: { group: 'earnings', name: 'Investment profit is shared by' },
  profit_credit_account: { group: 'earnings', name: 'Profit share is added to' },
  admins_can_members: { group: 'admins', name: 'Add and change members' },
  admins_can_money: { group: 'admins', name: 'Record and reverse money', help: 'Deposits, payroll, transfers, withdrawals, fees, income, expenses and journals.' },
  admins_can_loans: { group: 'admins', name: 'Approve, pay out and manage loans' },
  admins_can_notices: { group: 'admins', name: 'Post notices' },
}
const GROUPS: { key: string; title: string; about: string; icon: Component }[] = [
  { key: 'loans', title: 'Loans', about: 'Who can borrow, and what happens when repayments are late.', icon: ClipboardDocumentCheckIcon },
  { key: 'reminders', title: 'Reminders', about: 'Messages about instalments falling due.', icon: BellAlertIcon },
  { key: 'savings', title: 'Savings & withdrawals', about: 'What stays in an account, and the cost of taking money out.', icon: WalletIcon },
  { key: 'shares', title: 'Shares & fees', about: 'Share price, the minimum holding, and the membership fees.', icon: BanknotesIcon },
  { key: 'earnings', title: 'Earnings', about: 'Dividends and investment profit for members.', icon: ChartPieIcon },
  { key: 'admins', title: 'What admins may do', about: 'Admins see everything; these let them change things too.', icon: KeyIcon },
  { key: 'other', title: 'Other', about: '', icon: Cog6ToothIcon },
]

const auth = useAuth()
const settings = ref<Setting[]>([])
const values = ref<Record<string, string>>({})
const saved = ref<Record<string, string>>({})
const errors = ref<Record<string, string>>({})
const message = ref('')
const error = ref('')
const busy = ref(false)

function apply(list: Setting[]) {
  settings.value = list
  values.value = Object.fromEntries(list.map((s) => [s.key, s.value]))
  saved.value = { ...values.value }
}

onMounted(async () => {
  try {
    apply((await api.get<{ settings: Setting[] }>('/settings')).settings)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the settings.'
  }
})

const groups = computed(() =>
  GROUPS.map((g) => ({ ...g, rules: settings.value.filter((s) => (LOOK[s.key]?.group ?? 'other') === g.key) })).filter((g) => g.rules.length),
)
const changed = computed(() => settings.value.filter((s) => values.value[s.key] !== saved.value[s.key]).length)

async function save() {
  errors.value = {}
  error.value = ''
  message.value = ''
  busy.value = true
  try {
    apply((await api.patch<{ settings: Setting[] }>('/settings', values.value)).settings)
    message.value = 'Saved. New loan applications use these rules; existing loans keep theirs.'
  } catch (e) {
    if (e instanceof ApiError) {
      errors.value = e.errors
      error.value = e.message
    }
  } finally {
    busy.value = false
  }
}

// The SACCO's logo
const logoError = ref('')
async function uploadLogo(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  logoError.value = ''
  const body = new FormData()
  body.append('logo', file)
  try {
    const res = await http.post('/settings/logo', body, { headers: { 'Content-Type': 'multipart/form-data' } })
    auth.sacco && (auth.sacco.logo_url = res.data.data.logo_url)
  } catch (err) {
    logoError.value = (err as { response?: { data?: { message?: string } } }).response?.data?.message ?? 'The logo couldn\'t be uploaded.'
  }
}
async function removeLogo() {
  await api.delete('/settings/logo')
  auth.sacco && (auth.sacco.logo_url = null)
}
</script>

<template>
  <div class="max-w-5xl">
    <p class="eyebrow">Settings</p>
    <h1 class="mt-1 text-2xl font-bold">Setup & rules</h1>
    <p class="mt-1 text-gray-600">
      The SACCO's rules, as the committee set them.
      <template v-if="!auth.isSuperAdmin">Only a super admin can change them.</template>
    </p>

    <section v-if="auth.isSuperAdmin" class="card mt-6 flex flex-wrap items-center gap-4 p-5">
      <SaccoMark :sacco="auth.sacco" size="lg" />
      <div class="min-w-[14rem] flex-1">
        <h2 class="font-semibold">The SACCO's logo</h2>
        <p class="text-sm text-gray-600">Shown in the app and on every letterhead, receipt, card and certificate. A PNG or JPG under 1 MB, ideally square.</p>
        <p v-if="logoError" class="mt-1 text-sm text-red-700" role="alert">{{ logoError }}</p>
      </div>
      <label class="btn-primary cursor-pointer">
        {{ auth.sacco?.logo_url ? 'Change the logo' : 'Upload the logo' }}
        <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="uploadLogo" />
      </label>
      <button v-if="auth.sacco?.logo_url" type="button" class="btn-ghost" @click="removeLogo">Remove</button>
    </section>

    <LoanProducts class="mt-6" />

    <form class="mt-6 space-y-6" novalidate @submit.prevent="save">
      <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
      <div v-if="message && !changed" class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800" role="status">{{ message }}</div>

      <section v-for="g in groups" :key="g.key" class="card overflow-hidden" :aria-labelledby="`rules-${g.key}`">
        <div class="flex items-start gap-3 border-b border-line px-5 py-4">
          <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-navy-50 text-navy-800"><component :is="g.icon" class="h-5 w-5" aria-hidden="true" /></span>
          <div>
            <h2 :id="`rules-${g.key}`" class="font-semibold">{{ g.title }}</h2>
            <p v-if="g.about" class="text-sm text-gray-600">{{ g.about }}</p>
          </div>
        </div>
        <div class="grid gap-x-8 gap-y-5 p-5 md:grid-cols-2">
          <div v-for="s in g.rules" :key="s.key">
            <label :for="s.key" class="label">{{ LOOK[s.key]?.name ?? s.label }}</label>
            <select v-if="s.options" :id="s.key" v-model="values[s.key]" class="input" :disabled="!auth.isSuperAdmin">
              <option v-for="(label, value) in s.options" :key="value" :value="value">{{ label }}</option>
            </select>
            <div v-else class="relative">
              <span v-if="LOOK[s.key]?.unit === 'UGX'" class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-gray-500">UGX</span>
              <input
                :id="s.key"
                v-model="values[s.key]"
                class="input tabular-nums"
                :class="{ 'pl-12': LOOK[s.key]?.unit === 'UGX', 'pr-16': LOOK[s.key]?.unit && LOOK[s.key]?.unit !== 'UGX' }"
                inputmode="decimal"
                :disabled="!auth.isSuperAdmin"
              />
              <span v-if="LOOK[s.key]?.unit && LOOK[s.key]?.unit !== 'UGX'" class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-gray-500">{{ LOOK[s.key]?.unit }}</span>
            </div>
            <p v-if="errors[s.key]" class="field-error">{{ errors[s.key] }}</p>
            <p v-else-if="LOOK[s.key]?.help" class="mt-1 text-xs text-gray-500">{{ LOOK[s.key]?.help }}</p>
          </div>
        </div>
      </section>

      <!-- Appears once something has changed, and stays in view while scrolling -->
      <div v-if="auth.isSuperAdmin && changed" class="sticky bottom-20 z-10 lg:bottom-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-line bg-surface px-5 py-3 shadow-lift">
        <p class="text-sm"><span class="font-semibold">{{ changed }} rule{{ changed === 1 ? '' : 's' }} changed.</span> <span class="text-gray-600">New loan applications use the new rules; existing loans keep theirs.</span></p>
        <div class="flex gap-2">
          <button type="button" class="btn-secondary" :disabled="busy" @click="values = { ...saved }">Undo changes</button>
          <button type="submit" class="btn-primary" :disabled="busy">{{ busy ? 'Saving…' : 'Save rules' }}</button>
        </div>
      </div>
    </form>
  </div>
</template>
