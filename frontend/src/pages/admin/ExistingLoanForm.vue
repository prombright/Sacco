<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/api'
import type { Loan, LoanProduct, PickedMember } from '@/types'
import Modal from '@/components/Modal.vue'
import MemberPicker from '@/components/MemberPicker.vue'

/**
 * A loan from the old records, already paid out before this system. It becomes an active loan
 * with its schedule from the real payout date, and what was already repaid is counted.
 */
const emit = defineEmits<{ close: [] }>()
const router = useRouter()

const products = ref<LoanProduct[]>([])
const member = ref<PickedMember | null>(null)
const form = reactive({ product_id: 0, amount: '', months: '12', interest_rate_monthly: '', disbursed_on: '', repaid_so_far: '', reference: '', purpose: '' })
const error = ref('')
const busy = ref(false)

onMounted(async () => {
  products.value = (await api.get<{ products: LoanProduct[] }>('/loan-products')).products
  form.product_id = products.value[0]?.id ?? 0
  form.interest_rate_monthly = products.value[0]?.interest_rate_monthly ?? ''
})

function pickProduct() {
  const p = products.value.find((x) => x.id === form.product_id)
  if (p) form.interest_rate_monthly = p.interest_rate_monthly
}

async function save() {
  if (!member.value) return
  error.value = ''
  busy.value = true
  try {
    const res = await api.post<{ loan: Loan }>('/loans/existing', { user_id: member.value.id, ...form })
    await router.push({ name: 'loan', params: { id: res.loan.id } })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t record the loan.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal title="Record a loan from the old records" @close="emit('close')">
    <form class="space-y-4" novalidate @submit.prevent="save">
      <p class="text-sm text-gray-600">For loans paid out before the SACCO used this system. The schedule runs from the real payout date, so late instalments show as late.</p>
      <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

      <div>
        <span class="label">Member</span>
        <MemberPicker v-if="!member" @pick="member = $event" />
        <div v-else class="flex items-center justify-between rounded-lg border border-line px-3 py-2">
          <span class="font-medium">{{ member.full_name }} <span class="font-mono text-xs text-gray-500">{{ member.account_no }}</span></span>
          <button type="button" class="btn-ghost px-2 py-1" @click="member = null">Change</button>
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label for="xp" class="label">Type of loan</label>
          <select id="xp" v-model.number="form.product_id" class="input" @change="pickProduct">
            <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <div><label for="xr" class="label">Interest (% a month)</label><input id="xr" v-model="form.interest_rate_monthly" class="input tabular-nums" inputmode="decimal" /></div>
        <div><label for="xa" class="label">Amount lent</label><input id="xa" v-model="form.amount" class="input tabular-nums" inputmode="numeric" /></div>
        <div><label for="xm" class="label">Over how many months</label><input id="xm" v-model="form.months" class="input tabular-nums" inputmode="numeric" /></div>
        <div><label for="xd" class="label">Paid out on</label><input id="xd" v-model="form.disbursed_on" type="date" :max="new Date().toLocaleDateString('en-CA')" class="input" /></div>
        <div><label for="xpaid" class="label">Repaid so far (total)</label><input id="xpaid" v-model="form.repaid_so_far" class="input tabular-nums" inputmode="numeric" placeholder="0" /></div>
        <div><label for="xref" class="label">Cheque or old loan no. <span class="font-normal text-gray-400">(optional)</span></label><input id="xref" v-model="form.reference" class="input" maxlength="80" /></div>
        <div><label for="xpur" class="label">Purpose <span class="font-normal text-gray-400">(optional)</span></label><input id="xpur" v-model="form.purpose" class="input" maxlength="255" /></div>
      </div>

      <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <button type="button" class="btn-secondary" @click="emit('close')">Cancel</button>
        <button type="submit" class="btn-primary" :disabled="busy || !member || !form.amount || !form.disbursed_on">Record loan</button>
      </div>
    </form>
  </Modal>
</template>
