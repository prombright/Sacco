<script setup lang="ts">
import { ref, watch } from 'vue'
import { api, ApiError } from '@/api'
import type { Account, Page, Transaction } from '@/types'
import AccountTabs from '@/components/AccountTabs.vue'
import TransactionList from '@/components/TransactionList.vue'
import Pager from '@/components/Pager.vue'
import OldHistory from '@/components/OldHistory.vue'

const account = ref<Account>('savings')
const page = ref(1)
const data = ref<Page<Transaction> | null>(null)
const error = ref('')

async function load() {
  error.value = ''
  try {
    data.value = await api.get<Page<Transaction>>('/me/transactions', { account: account.value, page: page.value })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load your statement.'
  }
}
watch(account, () => { page.value = 1; load() })
watch(page, load)
load()
</script>

<template>
  <div>
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold">My statement</h1>
        <p class="mt-1 text-sm text-gray-600">Every entry on your account, newest first.</p>
      </div>
      <RouterLink :to="{ name: 'statement-print' }" class="btn-secondary">Print a statement</RouterLink>
    </div>

    <div class="mt-5"><AccountTabs v-model="account" /></div>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <div class="card mt-4 overflow-hidden">
      <TransactionList :transactions="data?.transactions ?? []" />
    </div>
    <Pager v-if="data" :page="data.page" :per-page="data.per_page" :total="data.total" @go="page = $event" />
    <OldHistory url="/me/old-history" />
  </div>
</template>
