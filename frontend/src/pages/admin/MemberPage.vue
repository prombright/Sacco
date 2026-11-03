<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import type { Account, Balances, Page, Transaction, User } from '@/types'
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
    <RouterLink :to="{ name: 'users' }" class="text-sm font-medium text-gray-600 hover:text-gray-900">← Members &amp; users</RouterLink>

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
          <RouterLink :to="{ name: 'member-statement', params: { id: member.id } }" class="btn-secondary">Print statement</RouterLink>
          <button v-if="!isSelf" type="button" class="btn-primary" @click="recording = true">Record an entry</button>
        </div>
      </div>
      <p v-if="isSelf" class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-600">
        This is your own account. Another admin records and reverses entries on it.
      </p>

      <div class="mt-5">
        <BalanceTiles :balances="balances" :share-price="sharePrice" />
      </div>

      <div class="mt-8"><AccountTabs v-model="account" /></div>
      <div class="card mt-3 overflow-hidden">
        <TransactionList :transactions="ledger?.transactions ?? []" :can-reverse="!isSelf" @reverse="reversing = $event" />
      </div>
      <Pager v-if="ledger" :page="ledger.page" :per-page="ledger.per_page" :total="ledger.total" @go="page = $event" />
    </template>

    <RecordTransaction v-if="recording && member && balances" :member="member" :account="account" :balances="balances" @close="recording = false" @saved="saved" />
    <ReverseTransaction v-if="reversing" :transaction="reversing" @close="reversing = null" @saved="saved" />
  </div>
</template>
