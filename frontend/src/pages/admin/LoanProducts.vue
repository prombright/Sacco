<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import type { LoanProduct } from '@/types'
import Modal from '@/components/Modal.vue'
import StatusBadge from '@/components/StatusBadge.vue'

/** The kinds of loan the SACCO offers. A super admin adds and changes them. */
const auth = useAuth()
const products = ref<LoanProduct[]>([])
const editing = ref<LoanProduct | null | undefined>(undefined) // undefined closed, null new
const form = reactive({
  name: '',
  description: '',
  interest_rate_monthly: '1',
  max_months: '12',
  savings_multiplier: '2',
  processing_fee_percent: '0',
  min_guarantors: '2',
  guarantee_cover_percent: '100',
  status: 'active',
})
const errors = ref<Record<string, string>>({})
const error = ref('')
const busy = ref(false)

async function load() {
  products.value = (await api.get<{ products: LoanProduct[] }>('/loan-products', { all: 1 })).products
}
onMounted(load)

function open(p: LoanProduct | null) {
  errors.value = {}
  error.value = ''
  Object.assign(form, p
    ? { ...p, description: p.description ?? '', max_months: String(p.max_months), min_guarantors: String(p.min_guarantors), guarantee_cover_percent: String(p.guarantee_cover_percent) }
    : { name: '', description: '', interest_rate_monthly: '1', max_months: '12', savings_multiplier: '2', processing_fee_percent: '0', min_guarantors: '2', guarantee_cover_percent: '100', status: 'active' })
  editing.value = p
}

async function save() {
  errors.value = {}
  error.value = ''
  busy.value = true
  try {
    if (editing.value) await api.patch(`/loan-products/${editing.value.id}`, form)
    else await api.post('/loan-products', form)
    editing.value = undefined
    await load()
  } catch (e) {
    if (e instanceof ApiError) {
      errors.value = e.errors
      error.value = e.message
    }
  } finally {
    busy.value = false
  }
}

const fields: { key: keyof typeof form; label: string; suffix: string }[] = [
  { key: 'interest_rate_monthly', label: 'Interest', suffix: '% a month' },
  { key: 'max_months', label: 'Longest term', suffix: 'months' },
  { key: 'savings_multiplier', label: 'Loan limit', suffix: '× savings' },
  { key: 'processing_fee_percent', label: 'Processing fee', suffix: '% of the loan' },
  { key: 'min_guarantors', label: 'Guarantors needed', suffix: 'people' },
  { key: 'guarantee_cover_percent', label: 'Guarantees must cover', suffix: '% of the loan' },
]
</script>

<template>
  <section class="card p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="font-semibold">Loan products</h2>
        <p class="mt-1 text-sm text-gray-600">The kinds of loan members can apply for. A loan keeps the terms it was given, so changes affect new applications only.</p>
      </div>
      <button v-if="auth.isSuperAdmin" type="button" class="btn-primary" @click="open(null)">Add a product</button>
    </div>

    <div class="mt-4 overflow-x-auto rounded-xl border border-line">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
          <tr>
            <th class="px-3 py-2">Product</th>
            <th class="px-3 py-2 text-right">Interest</th>
            <th class="px-3 py-2 text-right">Term</th>
            <th class="px-3 py-2 text-right">Limit</th>
            <th class="px-3 py-2 text-right">Fee</th>
            <th class="px-3 py-2 text-right">Guarantors</th>
            <th class="px-3 py-2"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="p in products" :key="p.id" :class="{ 'text-gray-400': p.status === 'archived' }">
            <td class="px-3 py-2.5">
              <span class="font-semibold">{{ p.name }}</span>
              <StatusBadge v-if="p.status === 'archived'" class="ml-2" label="Not offered" style-class="bg-gray-100 text-gray-600" />
              <span v-if="p.description" class="block text-xs text-gray-500">{{ p.description }}</span>
            </td>
            <td class="px-3 py-2.5 text-right tabular-nums">{{ p.interest_rate_monthly }}%/mo</td>
            <td class="px-3 py-2.5 text-right tabular-nums">{{ p.max_months }} mo</td>
            <td class="px-3 py-2.5 text-right tabular-nums">{{ p.savings_multiplier }}×</td>
            <td class="px-3 py-2.5 text-right tabular-nums">{{ p.processing_fee_percent }}%</td>
            <td class="px-3 py-2.5 text-right tabular-nums">{{ p.min_guarantors }} · {{ p.guarantee_cover_percent }}%</td>
            <td class="px-3 py-2.5 text-right"><button v-if="auth.isSuperAdmin" type="button" class="btn-ghost px-2 py-1" @click="open(p)">Edit</button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <Modal v-if="editing !== undefined" :title="editing ? `Edit ${editing.name}` : 'Add a loan product'" @close="editing = undefined">
      <form class="space-y-4" novalidate @submit.prevent="save">
        <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
        <div class="grid gap-4 sm:grid-cols-2">
          <div><label for="pname" class="label">Name</label><input id="pname" v-model="form.name" class="input" maxlength="80" placeholder="e.g. School fees loan" /></div>
          <div><label for="pdesc" class="label">Short description</label><input id="pdesc" v-model="form.description" class="input" maxlength="255" /></div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <div v-for="f in fields" :key="f.key">
            <label :for="f.key" class="label">{{ f.label }}</label>
            <div class="flex items-center gap-2">
              <input :id="f.key" v-model="form[f.key]" class="input tabular-nums" inputmode="decimal" />
              <span class="whitespace-nowrap text-sm text-gray-500">{{ f.suffix }}</span>
            </div>
            <p v-if="errors[f.key]" class="field-error">{{ errors[f.key] }}</p>
          </div>
        </div>
        <label v-if="editing" class="flex items-center gap-2 text-sm">
          <input v-model="form.status" type="checkbox" true-value="archived" false-value="active" class="rounded border-line text-brand focus:ring-brand" />
          Stop offering this product (existing loans aren't affected)
        </label>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-secondary" @click="editing = undefined">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy">{{ editing ? 'Save changes' : 'Add product' }}</button>
        </div>
      </form>
    </Modal>
  </section>
</template>
