<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { PencilSquareIcon, PlusIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import type { Report, ReportRow } from '@/reports'
import ReportView from '@/components/ReportView.vue'
import Modal from '@/components/Modal.vue'

interface Account {
  id: number
  code: string
  name: string
  type: 'asset' | 'liability' | 'equity' | 'income' | 'expense'
  system_key: string | null
  description: string | null
  status: 'active' | 'archived'
  balance: string
  used: boolean
  control: boolean
  is_money: boolean
}

/** The chart of accounts: every account in the books with today's balance. The super admin adds and edits them. */
const auth = useAuth()
const router = useRouter()
const accounts = ref<Account[]>([])
const types = ref<Record<string, string>>({})
const error = ref('')

async function load() {
  try {
    const res = await api.get<{ accounts: Account[]; types: Record<string, string> }>('/accounting/accounts')
    accounts.value = res.accounts
    types.value = res.types
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the accounts.'
  }
}
onMounted(load)

const report = computed<Report | null>(() => {
  if (!accounts.value.length) return null
  const rows: ReportRow[] = []
  for (const [type, label] of Object.entries(types.value)) {
    rows.push({ code: label, _kind: 'heading' })
    for (const a of accounts.value.filter((x) => x.type === type)) {
      rows.push({
        code: a.code,
        name: a.name + (a.status === 'archived' ? ' (archived)' : ''),
        note: a.is_money ? 'Bank or cash account' : a.control ? 'Follows members\' accounts' : a.system_key ? 'Posted by the system' : a.description ?? '',
        balance: a.balance,
        _account_id: a.id,
      })
    }
  }
  return {
    title: 'Chart of accounts',
    subtitle: `${accounts.value.length} accounts · balances as at ${new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })}`,
    columns: [
      { key: 'code', label: 'Code', type: 'text' },
      { key: 'name', label: 'Account', type: 'text' },
      { key: 'note', label: 'Notes', type: 'text' },
      { key: 'balance', label: 'Balance', type: 'money' },
    ],
    rows,
    totals: null,
  }
})

// --- Adding and editing (super admin) ---
const editing = ref<Partial<Account> | null>(null)
const formError = ref('')
function start(a?: Account) {
  formError.value = ''
  editing.value = a ? { ...a } : { code: '', name: '', type: 'expense', description: '', status: 'active', is_money: false }
}
async function save() {
  if (!editing.value) return
  formError.value = ''
  try {
    const body = { code: editing.value.code, name: editing.value.name, type: editing.value.type, is_money: editing.value.is_money, description: editing.value.description ?? '', status: editing.value.status }
    const res = editing.value.id
      ? await api.patch<{ accounts: Account[] }>(`/accounting/accounts/${editing.value.id}`, body)
      : await api.post<{ accounts: Account[] }>('/accounting/accounts', body)
    accounts.value = res.accounts
    editing.value = null
  } catch (e) {
    formError.value = e instanceof ApiError ? e.message : 'Couldn\'t save.'
  }
}
const byId = (id: unknown) => accounts.value.find((a) => a.id === id)
</script>

<template>
  <div>
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <p class="eyebrow">Financial reports</p>
        <h1 class="mt-1 text-2xl font-bold">Chart of accounts</h1>
        <p class="mt-1 max-w-3xl text-gray-600">Every account in the SACCO's books. Deposits, loans, repayments, withdrawals, dividends, income and expenses post to them by themselves. Click an account to see its ledger.</p>
      </div>
      <button v-if="auth.isSuperAdmin" type="button" class="btn-primary" @click="start()"><PlusIcon class="h-4 w-4" />Add an account</button>
    </div>
    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <ReportView class="mt-5" :report="report" :clickable="(r) => !!r._account_id" @row-click="(r) => router.push({ name: 'account-ledger', query: { account_id: r._account_id } })">
      <template v-if="auth.isSuperAdmin" #actions="{ row }">
        <button v-if="row._account_id" type="button" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 hover:text-ink" :aria-label="`Edit ${row.name}`" @click.stop="start(byId(row._account_id))">
          <PencilSquareIcon class="h-4 w-4" />
        </button>
      </template>
    </ReportView>

    <Modal v-if="editing" :title="editing.id ? 'Edit account' : 'Add an account'" @close="editing = null">
      <form class="space-y-4" @submit.prevent="save">
        <div class="grid grid-cols-3 gap-3">
          <div><label for="a-code" class="label">Code</label><input id="a-code" v-model="editing.code" class="input font-mono" placeholder="5070" /></div>
          <div class="col-span-2"><label for="a-name" class="label">Name</label><input id="a-name" v-model="editing.name" class="input" placeholder="e.g. Office rent" /></div>
        </div>
        <div>
          <label for="a-type" class="label">Kind</label>
          <select id="a-type" v-model="editing.type" class="input" :disabled="!!editing.system_key">
            <option v-for="(label, t) in types" :key="t" :value="t">{{ label }}</option>
          </select>
          <p v-if="editing.system_key" class="mt-1 text-xs text-gray-500">The system posts to this account, so its kind stays.</p>
        </div>
        <label v-if="editing.type === 'asset'" class="flex items-center gap-2 text-sm">
          <input v-model="editing.is_money" type="checkbox" class="accent-brand" :disabled="editing.system_key === 'bank' || editing.system_key === 'cash'" />
          A bank or cash account (offered when money is received or paid)
        </label>
        <div><label for="a-desc" class="label">Description <span class="font-normal text-gray-500">(optional)</span></label><input id="a-desc" v-model="editing.description" class="input" /></div>
        <label v-if="editing.id && !editing.system_key" class="flex items-center gap-2 text-sm">
          <input type="checkbox" :checked="editing.status === 'archived'" class="accent-brand" @change="editing!.status = ($event.target as HTMLInputElement).checked ? 'archived' : 'active'" />
          Archived (kept in the books, but not offered for new entries)
        </label>
        <p v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</p>
        <button type="submit" class="btn-primary w-full">Save</button>
      </form>
    </Modal>
  </div>
</template>
