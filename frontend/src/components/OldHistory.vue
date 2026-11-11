<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { api, ApiError } from '@/api'
import { formatDate, formatMoney } from '@/money'
import Pager from '@/components/Pager.vue'

/** What a member did on the old system: for reference only, it never changes a balance */
interface OldEntry {
  entry_date: string
  details: string | null
  voucher: string | null
  savings: string
  shares: string
  loan: string
  loan_interest: string
  savings_interest: string
  other: string
}
interface Result {
  transactions: OldEntry[]
  total: number
  page: number
  per_page: number
  interest_payable: { amount: string; as_at: string } | null
}

const props = defineProps<{ url: string }>()
const page = ref(1)
const data = ref<Result | null>(null)
const open = ref(false)
const error = ref('')

async function load() {
  error.value = ''
  try {
    data.value = await api.get<Result>(props.url, { page: page.value })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the old records.'
  }
}
watch(() => props.url, () => { page.value = 1; load() }, { immediate: true })
watch(page, load)

const COLUMNS = [['savings', 'Savings'], ['shares', 'Shares'], ['loan', 'Loan'], ['loan_interest', 'Loan interest'], ['savings_interest', 'Savings interest'], ['other', 'Other']] as const
const show = computed(() => !!data.value && (data.value.total > 0 || !!data.value.interest_payable))
const cell = (v: string) => (Number(v) ? formatMoney(v) : '')
</script>

<template>
  <section v-if="show && data" class="card mt-5 p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0 flex-1">
        <h2 class="font-semibold">From the old system</h2>
        <p class="mt-0.5 text-sm text-gray-600">For reference only. Balances on this account already include all of this.</p>
      </div>
      <button v-if="data.total" type="button" class="btn-secondary" :aria-expanded="open" @click="open = !open">{{ open ? 'Hide' : `Show ${data.total.toLocaleString()} old entries` }}</button>
    </div>
    <p v-if="data.interest_payable" class="mt-3 text-sm">
      Savings interest payable: <strong class="tabular-nums">{{ formatMoney(data.interest_payable.amount) }}</strong>
      <span class="text-gray-600"> as at {{ formatDate(data.interest_payable.as_at) }}, until the committee decides how it is settled.</span>
    </p>
    <div v-if="error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
    <template v-if="open && data.total">
      <div class="mt-4 overflow-x-auto rounded-xl border border-line">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
            <tr>
              <th class="px-3 py-2">Date</th>
              <th class="px-3 py-2">Details</th>
              <th v-for="[k, label] in COLUMNS" :key="k" class="whitespace-nowrap px-3 py-2 text-right">{{ label }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="(e, i) in data.transactions" :key="i">
              <td class="whitespace-nowrap px-3 py-2 text-gray-600">{{ formatDate(e.entry_date) }}</td>
              <td class="min-w-[12rem] px-3 py-2">{{ e.details }}<span v-if="e.voucher" class="text-gray-500"> · {{ e.voucher }}</span></td>
              <td v-for="[k] in COLUMNS" :key="k" class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ cell(e[k]) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pager :page="data.page" :per-page="data.per_page" :total="data.total" @go="page = $event" />
    </template>
  </section>
</template>
