<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowDownLeftIcon, ArrowUpRightIcon, DocumentArrowDownIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { formatDate, formatMoney } from '@/money'
import { downloadReceipt, type Receipt, type ReceiptMember } from '@/receipts'

/** Every movement on an account, each with a receipt to download: /receipts, or /members/:id/receipts */
const route = useRoute()
const auth = useAuth()
const memberId = computed(() => (route.params.id ? Number(route.params.id) : null))
const data = ref<{ receipts: Receipt[]; total: number; per_page: number; member: ReceiptMember } | null>(null)
const page = ref(1)
const show = ref<'all' | 'in' | 'out'>('all')
const error = ref('')
const making = ref('')

async function load() {
  error.value = ''
  try {
    data.value = await api.get(memberId.value ? `/members/${memberId.value}/receipts` : '/me/receipts', { page: page.value })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the receipts.'
  }
}
onMounted(load)
watch(page, load)

const rows = computed(() => (data.value?.receipts ?? []).filter((r) => show.value === 'all' || r.direction === show.value))

async function download(r: Receipt) {
  making.value = r.no
  try {
    await downloadReceipt(r, data.value!.member, { sacco: auth.sacco, user: auth.user?.full_name ?? null })
  } finally {
    making.value = ''
  }
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <p class="eyebrow">{{ memberId ? data?.member.full_name : 'My account' }}</p>
        <h1 class="mt-1 text-2xl font-bold">Receipts</h1>
        <p class="mt-1 text-gray-600">A receipt for every deposit, withdrawal, transfer, dividend, fee and loan repayment.</p>
      </div>
      <div class="flex rounded-lg bg-gray-200/60 p-0.5 text-sm font-semibold" role="radiogroup" aria-label="Show">
        <button v-for="o in ([['all', 'All'], ['in', 'Money in'], ['out', 'Money out']] as const)" :key="o[0]" type="button" role="radio" :aria-checked="show === o[0]" class="rounded-md px-3 py-1.5" :class="show === o[0] ? 'bg-surface text-ink shadow-sm' : 'text-gray-600'" @click="show = o[0]">{{ o[1] }}</button>
      </div>
    </div>
    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <ul v-if="data" class="card mt-5 divide-y divide-line overflow-hidden">
      <li v-for="r in rows" :key="r.no" class="flex items-center gap-3 p-4" :class="{ 'opacity-60': r.reversed }">
        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" :class="r.direction === 'in' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'">
          <component :is="r.direction === 'in' ? ArrowDownLeftIcon : ArrowUpRightIcon" class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0 flex-1">
          <p class="break-words font-semibold sm:truncate">{{ r.title }} <span class="font-normal text-gray-500">· {{ r.account }}</span></p>
          <p class="break-words text-sm text-gray-500 sm:truncate">{{ formatDate(r.date) }} · <span class="font-mono text-xs">{{ r.no }}</span><template v-if="r.reversed"> · reversed</template></p>
          <!-- Phones: the amount under the words, so they get the width -->
          <p class="mt-0.5 font-semibold tabular-nums sm:hidden" :class="r.direction === 'in' ? 'text-emerald-700' : 'text-ink'">{{ r.direction === 'in' ? '+' : '−' }}{{ formatMoney(r.amount) }}</p>
        </div>
        <p class="hidden whitespace-nowrap font-semibold tabular-nums sm:block" :class="r.direction === 'in' ? 'text-emerald-700' : 'text-ink'">{{ r.direction === 'in' ? '+' : '−' }}{{ formatMoney(r.amount) }}</p>
        <button type="button" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-red-700 transition hover:bg-red-50 disabled:opacity-40 sm:w-auto sm:gap-1.5 sm:px-3 sm:text-sm sm:font-semibold" :disabled="making === r.no" :aria-label="`Download receipt ${r.no}`" @click="download(r)">
          <DocumentArrowDownIcon class="h-5 w-5" :class="{ 'animate-pulse': making === r.no }" aria-hidden="true" /><span class="hidden sm:inline">PDF</span>
        </button>
      </li>
      <li v-if="!rows.length" class="p-10 text-center text-sm text-gray-500">No receipts here yet.</li>
    </ul>
    <div v-else-if="!error" class="mt-5 h-40 animate-pulse rounded-2xl bg-surface" aria-hidden="true" />

    <div v-if="data && data.total > data.per_page" class="mt-3 flex items-center justify-between text-sm">
      <span class="text-gray-500">Page {{ page }} of {{ Math.ceil(data.total / data.per_page) }}</span>
      <span class="flex gap-2">
        <button type="button" class="btn-secondary" :disabled="page === 1" @click="page--">Previous</button>
        <button type="button" class="btn-secondary" :disabled="page * data.per_page >= data.total" @click="page++">Next</button>
      </span>
    </div>
  </div>
</template>
