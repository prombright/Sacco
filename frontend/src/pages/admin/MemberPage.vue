<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import type { Account, Balances, NextOfKin, Page, Transaction, User } from '@/types'
import { formatMoney } from '@/money'
import MemberDetails from '@/components/MemberDetails.vue'
import NextOfKinEditor from '@/components/NextOfKinEditor.vue'
import Modal from '@/components/Modal.vue'
import UserForm from './UserForm.vue'
import BalanceTiles from '@/components/BalanceTiles.vue'
import AccountTabs from '@/components/AccountTabs.vue'
import TransactionList from '@/components/TransactionList.vue'
import Pager from '@/components/Pager.vue'
import RecordTransaction from './RecordTransaction.vue'
import ReverseTransaction from './ReverseTransaction.vue'

const route = useRoute()
const auth = useAuth()
const id = Number(route.params.id)

const member = ref<User | null>(null)
const balances = ref<Balances | null>(null)
const sharePrice = ref('0')
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

// An admin's own account is handled by another admin
const isSelf = id === auth.user?.id

async function loadMember() {
  try {
    const res = await api.get<{ user: User; balances: Balances; share_price: string }>(`/members/${id}`)
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
  balances.value = newBalances
  if (page.value === 1) loadLedger()
  else page.value = 1 // the watch reloads
}

watch(account, () => { page.value = 1; loadLedger() })
watch(page, loadLedger)
loadMember()
loadLedger()
</script>

<template>
  <div>
    <RouterLink :to="{ name: 'users' }" class="text-sm font-medium text-gray-600 hover:text-gray-900">← Members</RouterLink>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <template v-if="member">
      <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-2xl font-bold">{{ member.full_name }}</h1>
          <p class="mt-1 text-sm text-gray-600">
            Account <span class="font-mono">{{ member.account_no }}</span>
            <template v-if="member.employee_no"> · Employee {{ member.employee_no }}</template>
            <template v-if="member.status !== 'active'"> · <span class="text-amber-700">{{ member.status === 'left' ? 'Left the school' : 'Suspended' }}</span></template>
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <button v-can="'members'" type="button" class="btn-secondary" @click="editingDetails = true">Edit details</button>
          <template v-if="isMember">
            <RouterLink :to="{ name: 'member-receipts', params: { id: member.id } }" class="btn-secondary">Receipts</RouterLink>
            <RouterLink :to="{ name: 'member-statement', params: { id: member.id } }" class="btn-secondary">Print statement</RouterLink>
            <button v-can="'money'" v-if="!isSelf" type="button" class="btn-primary" @click="recording = true">Record an entry</button>
          </template>
        </div>
      </div>

      <!-- Office accounts run the SACCO and hold no money of their own -->
      <section v-if="!isMember" class="card mt-5 p-5">
        <p class="eyebrow">{{ member.role_label }} · office account</p>
        <p class="mt-2 text-sm text-gray-700">
          This account runs the SACCO: {{ member.role === 'super_admin' ? 'everything, including the rules, loan products and other admins' : 'members, loans, withdrawals, payroll and reports' }}.
          It has no savings, shares or loans, and can't apply for or guarantee a loan. If this person is also a member, they have a separate member account and sign in with that for their own money.
        </p>
      </section>

      <template v-if="isMember">
      <div class="mt-5">
        <BalanceTiles :balances="balances" :share-price="sharePrice" />
      </div>

      <div class="mt-8"><AccountTabs v-model="account" /></div>
      <div class="card mt-3 overflow-hidden">
        <TransactionList :transactions="ledger?.transactions ?? []" :can-reverse="!isSelf" @reverse="reversing = $event" />
      </div>
      <Pager v-if="ledger" :page="ledger.page" :per-page="ledger.per_page" :total="ledger.total" @go="page = $event" />

      <div class="mt-8 grid gap-6 lg:grid-cols-2">
        <MemberDetails :user="member" />
        <NextOfKinEditor :people="kin" :save-url="`/members/${member.id}/next-of-kin`" @saved="kin = $event" />
      </div>

      <section v-if="member.status !== 'left' && !isSelf" class="mt-8 rounded-2xl border border-red-200 bg-red-50/40 p-5">
        <h2 class="font-semibold text-red-800">Close membership</h2>
        <p class="mt-1 text-sm text-gray-700">When a member leaves the SACCO: pays out their savings and shares by cheque and marks them as left. They must owe nothing and guarantee nobody first.</p>
        <button type="button" class="btn-secondary mt-3" @click="openExit">Close membership…</button>
      </section>
      </template>
    </template>

    <UserForm v-if="editingDetails && member" :user="member" @close="editingDetails = false" @saved="editingDetails = false; loadMember()" />

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
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="exitQuote = null">{{ exitQuote.blockers.length ? 'Close' : 'Cancel' }}</button>
          <button v-if="!exitQuote.blockers.length" type="button" class="btn-primary !bg-red-700 hover:!bg-red-800" :disabled="exiting || !exitForm.reason.trim()" @click="closeMembership">Pay out and close membership</button>
        </div>
      </div>
    </Modal>

    <RecordTransaction v-if="recording && member && balances" :member="member" :account="account" :balances="balances" @close="recording = false" @saved="saved" />
    <ReverseTransaction v-if="reversing" :transaction="reversing" @close="reversing = null" @saved="saved" />
  </div>
</template>
