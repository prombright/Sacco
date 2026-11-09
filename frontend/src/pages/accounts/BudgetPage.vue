<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { CheckCircleIcon, PencilSquareIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { formatMoney } from '@/money'
import type { Report } from '@/reports'
import ReportView from '@/components/ReportView.vue'

interface Line { id: number; code: string; name: string; type: 'income' | 'expense'; budget: string; actual: string; last_year: string }
interface Budget { year: number; elapsed_percent: number; accounts: Line[]; report: Report }

/** What the SACCO plans to earn and spend, against what it has */
const auth = useAuth()
const thisYear = new Date().getFullYear()
const year = ref(thisYear)
const data = ref<Budget | null>(null)
const error = ref('')
const message = ref('')
const editing = ref(false)
const values = ref<Record<number, string>>({})
const busy = ref(false)

async function load() {
  error.value = ''
  try {
    data.value = await api.get<Budget>('/finance/budget', { year: year.value })
    values.value = Object.fromEntries(data.value.accounts.map((a) => [a.id, Number(a.budget) ? String(Number(a.budget)) : '']))
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the budget.'
  }
}
onMounted(load)
watch(year, () => {
  editing.value = false
  load()
})

const groups = computed(() => (['income', 'expense'] as const).map((t) => ({ type: t, label: t === 'income' ? 'Income' : 'Expenses', lines: data.value?.accounts.filter((a) => a.type === t) ?? [] })))
const total = (t: 'income' | 'expense') => (data.value?.accounts ?? []).filter((a) => a.type === t).reduce((s, a) => s + (Number(values.value[a.id]) || 0), 0)

function copyLastYear() {
  for (const a of data.value?.accounts ?? []) values.value[a.id] = Number(a.last_year) ? String(Math.round(Number(a.last_year))) : ''
}
async function save() {
  busy.value = true
  error.value = ''
  try {
    data.value = await api.patch<Budget>('/finance/budget', { year: year.value, lines: Object.entries(values.value).map(([account_id, amount]) => ({ account_id: Number(account_id), amount })) })
    editing.value = false
    message.value = `The ${year.value} budget is saved.`
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t save.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <p class="eyebrow">Reports</p>
        <h1 class="mt-1 text-2xl font-bold">Budget and actual</h1>
        <p class="mt-1 max-w-3xl text-gray-600">Set what the SACCO expects to earn and spend in a year, account by account, and see how the year is going against it.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <div class="flex rounded-lg bg-gray-200/60 p-0.5 text-sm font-semibold" role="radiogroup" aria-label="Year">
          <button v-for="y in [thisYear - 1, thisYear, thisYear + 1]" :key="y" type="button" role="radio" :aria-checked="year === y" class="rounded-md px-3 py-1.5" :class="year === y ? 'bg-surface text-ink shadow-sm' : 'text-gray-600'" @click="year = y">{{ y }}</button>
        </div>
        <button v-if="auth.isSuperAdmin && !editing" type="button" class="btn-primary" :disabled="!data" @click="editing = true; message = ''"><PencilSquareIcon class="h-4 w-4" />Set the budget</button>
      </div>
    </div>
    <p v-if="message" class="mt-4 flex items-center gap-2 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><CheckCircleIcon class="h-5 w-5" />{{ message }}</p>
    <p v-if="error" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ error }}</p>

    <form v-if="editing && data" class="card mt-5 p-5" @submit.prevent="save">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="font-semibold">Budget for {{ year }}</h2>
        <button type="button" class="btn-secondary" @click="copyLastYear">Copy {{ year - 1 }}'s actual figures</button>
      </div>
      <div class="mt-4 grid gap-6 lg:grid-cols-2">
        <section v-for="g in groups" :key="g.type">
          <h3 class="text-xs font-bold uppercase tracking-wider text-gray-500">{{ g.label }}</h3>
          <div v-for="a in g.lines" :key="a.id" class="mt-2 grid grid-cols-[1fr_9rem] items-center gap-3">
            <label :for="`b-${a.id}`" class="text-sm">
              <span class="font-mono text-xs text-gray-500">{{ a.code }}</span> {{ a.name }}
              <span class="block text-xs text-gray-500">{{ year - 1 }}: {{ formatMoney(a.last_year) }}</span>
            </label>
            <input :id="`b-${a.id}`" v-model="values[a.id]" inputmode="decimal" class="input text-right tabular-nums" placeholder="0" />
          </div>
          <p class="mt-3 flex justify-between border-t border-line pt-2 text-sm font-semibold"><span>Total {{ g.label.toLowerCase() }}</span><span class="tabular-nums">{{ formatMoney(String(total(g.type))) }}</span></p>
        </section>
      </div>
      <p class="mt-4 text-sm">Planned surplus: <strong class="tabular-nums">{{ formatMoney(String(total('income') - total('expense'))) }}</strong></p>
      <div class="mt-4 flex flex-wrap gap-2">
        <button type="submit" class="btn-primary" :disabled="busy">Save the budget</button>
        <button type="button" class="btn-secondary" @click="editing = false; load()">Cancel</button>
      </div>
    </form>

    <ReportView class="mt-5" :report="data?.report ?? null">
      <template #empty>No budget set for {{ year }} yet.</template>
    </ReportView>
  </div>
</template>
