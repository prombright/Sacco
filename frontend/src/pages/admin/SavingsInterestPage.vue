<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { formatDate, formatMoney } from '@/money'
import { ask } from '@/confirm'
import { toast } from '@/toast'
import PageHeader from '@/components/PageHeader.vue'

interface Row { id: number; full_name: string; account_no: string | null; from_old: string; earned: string; paid: string; held: string }
interface Data {
  rate_year: number
  members: Row[]
  total_held: string
  payouts: { paid_on: string; method: 'cash' | 'savings'; amount: string; reference: string | null; full_name: string; account_no: string | null }[]
  money_accounts: { id: number; code: string; name: string }[]
}

/**
 * The savings interest held for members, and paying it out: the super admin picks members (or all)
 * and chooses cash (cleared from what is held) or into savings (added to their savings at once).
 */
const auth = useAuth()
const data = ref<Data | null>(null)
const error = ref('')
const q = ref('')
const chosen = ref<Set<number>>(new Set())
const method = ref<'savings' | 'cash'>('savings')
const paidOn = ref(new Date().toLocaleDateString('en-CA'))
const reference = ref('')
const moneyAccount = ref(0)
const busy = ref(false)

async function load() {
  try {
    data.value = await api.get<Data>('/savings-interest')
    chosen.value = new Set()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the savings interest.'
  }
}
onMounted(load)

const shown = computed(() => {
  const term = q.value.trim().toLowerCase()
  return (data.value?.members ?? []).filter((m) => !term || m.full_name.toLowerCase().includes(term) || (m.account_no ?? '').includes(term))
})
const chosenTotal = computed(() => (data.value?.members ?? []).filter((m) => chosen.value.has(m.id)).reduce((s, m) => s + Number(m.held), 0))
const allShown = computed(() => shown.value.length > 0 && shown.value.every((m) => chosen.value.has(m.id)))
function toggleAll() {
  const next = new Set(chosen.value)
  if (allShown.value) shown.value.forEach((m) => next.delete(m.id))
  else shown.value.forEach((m) => next.add(m.id))
  chosen.value = next
}
function toggle(id: number) {
  const next = new Set(chosen.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  chosen.value = next
}

async function pay() {
  const n = chosen.value.size
  if (!n) return
  const how = method.value === 'savings' ? 'into their savings' : 'in cash'
  if (!await ask(`Pay ${formatMoney(String(chosenTotal.value))} of savings interest to ${n} member${n === 1 ? '' : 's'} ${how}? Each is told.`)) return
  busy.value = true
  error.value = ''
  try {
    const res = await api.post<{ members: number; total: string }>('/savings-interest/pay', {
      method: method.value, user_ids: [...chosen.value], paid_on: paidOn.value, reference: reference.value, money_account_id: method.value === 'cash' ? moneyAccount.value || null : null,
    })
    toast(`Paid ${formatMoney(res.total)} to ${res.members} member${res.members === 1 ? '' : 's'}`)
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t pay it out.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader title="Savings interest">
      The interest members' savings have earned{{ data && data.rate_year > 0 ? ` (${data.rate_year}% a year)` : '' }}, with what the old system owed them, held until it is paid out.
      Pay it in cash (it is cleared from what is held) or into their savings (it becomes savings at once).
    </PageHeader>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <template v-if="data">
      <p class="mt-5 text-sm text-gray-600">Held for {{ data.members.length }} members: <span class="text-lg font-semibold tabular-nums text-ink">{{ formatMoney(data.total_held) }}</span></p>

      <!-- Paying out: super admin only -->
      <section v-if="auth.isSuperAdmin" class="card mt-4 p-5">
        <h2 class="font-semibold">Pay out</h2>
        <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <fieldset class="sm:col-span-2">
            <legend class="label">How</legend>
            <div class="grid gap-2 sm:grid-cols-2">
              <label v-for="o in [{ v: 'savings', t: 'Into their savings', d: 'Added to their savings at once' }, { v: 'cash', t: 'In cash', d: 'Paid to them; cleared from what is held' }]" :key="o.v" class="flex cursor-pointer gap-2 rounded-xl border p-3 text-sm" :class="method === o.v ? 'border-brand bg-navy-50/50' : 'border-line'">
                <input v-model="method" type="radio" :value="o.v" class="mt-0.5 accent-brand" />
                <span><span class="font-semibold">{{ o.t }}</span><span class="block text-xs text-gray-600">{{ o.d }}</span></span>
              </label>
            </div>
          </fieldset>
          <div><label for="si-date" class="label">Date</label><input id="si-date" v-model="paidOn" type="date" class="input" :max="new Date().toLocaleDateString('en-CA')" /></div>
          <div><label for="si-ref" class="label">Reference (optional)</label><input id="si-ref" v-model="reference" class="input" maxlength="80" placeholder="e.g. AGM 2026" /></div>
          <div v-if="method === 'cash' && data.money_accounts.length > 1" class="sm:col-span-2">
            <label for="si-acct" class="label">Paid from</label>
            <select id="si-acct" v-model.number="moneyAccount" class="input"><option :value="0">The bank account</option><option v-for="a in data.money_accounts" :key="a.id" :value="a.id">{{ a.code }} {{ a.name }}</option></select>
          </div>
        </div>
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
          <p class="text-sm text-gray-600">{{ chosen.size }} chosen · <span class="font-semibold tabular-nums text-ink">{{ formatMoney(String(chosenTotal)) }}</span></p>
          <button type="button" class="btn-primary" :disabled="busy || !chosen.size" @click="pay">{{ busy ? 'Paying…' : 'Pay the chosen members' }}</button>
        </div>
      </section>

      <div class="mt-4 max-w-sm"><input v-model="q" type="search" class="input" placeholder="Find a member" aria-label="Find a member" /></div>
      <div class="card mt-3 overflow-x-auto">
        <table class="w-full min-w-[640px] text-sm tabular-nums">
          <thead class="border-b border-line text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
              <th v-if="auth.isSuperAdmin" class="px-4 py-3"><input type="checkbox" :checked="allShown" aria-label="Choose all shown" class="accent-brand" @change="toggleAll" /></th>
              <th class="px-4 py-3">Member</th>
              <th class="px-4 py-3 text-right">From the old system</th>
              <th class="px-4 py-3 text-right">Earned since</th>
              <th class="px-4 py-3 text-right">Paid out</th>
              <th class="px-4 py-3 text-right">Held now</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="m in shown" :key="m.id" :class="chosen.has(m.id) ? 'bg-navy-50/40' : ''">
              <td v-if="auth.isSuperAdmin" class="px-4 py-2"><input type="checkbox" :checked="chosen.has(m.id)" :aria-label="`Choose ${m.full_name}`" class="accent-brand" @change="toggle(m.id)" /></td>
              <td class="px-4 py-2"><span class="font-medium">{{ m.full_name }}</span> <span class="font-mono text-xs text-gray-500">{{ m.account_no }}</span></td>
              <td class="whitespace-nowrap px-4 py-2 text-right">{{ formatMoney(m.from_old) }}</td>
              <td class="whitespace-nowrap px-4 py-2 text-right">{{ formatMoney(m.earned) }}</td>
              <td class="whitespace-nowrap px-4 py-2 text-right">{{ formatMoney(m.paid) }}</td>
              <td class="whitespace-nowrap px-4 py-2 text-right font-semibold">{{ formatMoney(m.held) }}</td>
            </tr>
            <tr v-if="!shown.length"><td colspan="6" class="px-4 py-8 text-center text-gray-500">No interest is held for anyone{{ q ? ' matching that' : '' }}.</td></tr>
          </tbody>
        </table>
      </div>

      <section v-if="data.payouts.length" class="mt-6">
        <h2 class="font-semibold">Paid out</h2>
        <ul class="card mt-3 divide-y divide-line text-sm">
          <li v-for="(p, i) in data.payouts" :key="i" class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-2">
            <span>{{ p.full_name }} <span class="font-mono text-xs text-gray-500">{{ p.account_no }}</span> · {{ formatDate(p.paid_on) }} · {{ p.method === 'cash' ? 'cash' : 'into savings' }}<template v-if="p.reference"> · {{ p.reference }}</template></span>
            <span class="font-semibold tabular-nums">{{ formatMoney(p.amount) }}</span>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>
