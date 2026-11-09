<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { CheckCircleIcon, ExclamationTriangleIcon, LockClosedIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { ask } from '@/confirm'
import { formatMoney } from '@/money'
import Modal from '@/components/Modal.vue'

interface Year {
  year: number
  status: 'closed' | 'ready' | 'running'
  income: string
  expenses: string
  surplus: string
  distributions: string
  closed_at: string | null
  closed_by: string | null
  changed_since_closing: boolean | null
}

/** Closing a year: its result goes to Retained surplus and nothing more can be dated in it */
const auth = useAuth()
const years = ref<Year[]>([])
const lastClosed = ref<number | null>(null)
const error = ref('')
const message = ref('')
const busy = ref(false)

async function load() {
  try {
    const res = await api.get<{ years: Year[]; last_closed: number | null }>('/finance/years')
    years.value = res.years
    lastClosed.value = res.last_closed
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the years.'
  }
}
onMounted(load)

// Years close in order: the oldest one not yet closed
const nextToClose = computed(() => [...years.value].reverse().find((y) => y.status === 'ready')?.year ?? null)

async function close(y: Year) {
  if (!await ask(`Close the books for ${y.year}? Its surplus of ${formatMoney(y.surplus)} goes to Retained surplus, and nothing more can be recorded with a ${y.year} date.`)) return
  busy.value = true
  error.value = ''
  try {
    await api.post(`/finance/years/${y.year}/close`)
    message.value = `${y.year} is closed.`
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t close the year.'
  } finally {
    busy.value = false
  }
}

const reopening = ref<Year | null>(null)
const reason = ref('')
async function reopen() {
  busy.value = true
  error.value = ''
  try {
    await api.post(`/finance/years/${reopening.value!.year}/reopen`, { reason: reason.value })
    message.value = `${reopening.value!.year} is open again.`
    reopening.value = null
    reason.value = ''
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t reopen the year.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <p class="eyebrow">Reports</p>
    <h1 class="mt-1 text-2xl font-bold">Year-end closing</h1>
    <p class="mt-1 max-w-3xl text-gray-600">After the auditors have signed off a year, close it. Its income, expenses and payouts to members go to Retained surplus in one entry dated 31 December, and nothing more can be recorded with a date in that year. Corrections then go in the open year.</p>

    <p v-if="message" class="mt-4 flex items-center gap-2 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><CheckCircleIcon class="h-5 w-5" />{{ message }}</p>
    <p v-if="error" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ error }}</p>

    <div class="card mt-5 overflow-x-auto">
      <table class="w-full min-w-[40rem] text-sm">
        <thead class="border-b border-line bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
          <tr>
            <th class="px-4 py-3">Year</th>
            <th class="px-4 py-3 text-right">Income</th>
            <th class="px-4 py-3 text-right">Expenses</th>
            <th class="px-4 py-3 text-right">Surplus</th>
            <th class="px-4 py-3">Books</th>
            <th class="px-4 py-3"><span class="sr-only">Action</span></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="y in years" :key="y.year">
            <td class="px-4 py-3 font-semibold">{{ y.year }}</td>
            <td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(y.income) }}</td>
            <td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(y.expenses) }}</td>
            <td class="px-4 py-3 text-right font-semibold tabular-nums" :class="{ 'text-red-700': Number(y.surplus) < 0 }">{{ formatMoney(y.surplus) }}</td>
            <td class="px-4 py-3">
              <span v-if="y.status === 'closed'" class="inline-flex items-center gap-1 rounded-full bg-navy-50 px-2.5 py-0.5 text-xs font-semibold text-navy-800"><LockClosedIcon class="h-3.5 w-3.5" />Closed</span>
              <span v-else-if="y.status === 'running'" class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">This year</span>
              <span v-else class="rounded-full bg-saffron-100 px-2.5 py-0.5 text-xs font-semibold text-saffron-700">Can be closed</span>
              <span v-if="y.closed_by" class="mt-0.5 block text-xs text-gray-500">by {{ y.closed_by }}</span>
              <span v-if="y.changed_since_closing" class="mt-1 flex items-center gap-1 text-xs font-semibold text-amber-800"><ExclamationTriangleIcon class="h-3.5 w-3.5" />Figures changed since closing</span>
            </td>
            <td class="px-4 py-3 text-right">
              <template v-if="auth.isSuperAdmin">
                <button v-if="y.year === nextToClose" type="button" class="btn-primary px-3 py-1.5" :disabled="busy" @click="close(y)">Close {{ y.year }}</button>
                <button v-else-if="y.year === lastClosed" type="button" class="btn-ghost px-3 py-1.5" :disabled="busy" @click="reopening = y; reason = ''">Reopen</button>
              </template>
            </td>
          </tr>
          <tr v-if="!years.length"><td colspan="6" class="px-4 py-8 text-center text-gray-500">Nothing in the books yet.</td></tr>
        </tbody>
      </table>
    </div>
    <p v-if="!auth.isSuperAdmin" class="mt-3 text-xs text-gray-500">Only the super admin closes and reopens years.</p>

    <Modal v-if="reopening" :title="`Reopen ${reopening.year}`" @close="reopening = null">
      <form class="space-y-4" @submit.prevent="reopen">
        <p class="text-sm text-gray-700">The closing entry is removed and entries can be dated in {{ reopening.year }} again. Close it again when the correction is made.</p>
        <div><label for="ye-reason" class="label">Why</label><input id="ye-reason" v-model="reason" class="input" /></div>
        <button type="submit" class="btn-primary w-full" :disabled="!reason || busy">Reopen</button>
      </form>
    </Modal>
  </div>
</template>
