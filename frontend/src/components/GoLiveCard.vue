<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { CheckIcon, ChevronDownIcon, RocketLaunchIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'

/** The super admin's switch-over checklist (GoLiveController): what is done, and what to do next */

interface Step {
  key: string
  n: number
  title: string
  done: boolean
  detail: string
  manual: boolean
  ticked_on: string | null
  link?: { label: string; to: string }
  months?: { month: string; label: string; entries: number; payroll: boolean }[]
}
interface Checklist { steps: Step[]; done: number; total: number; balance_date: string | null }

const list = ref<Checklist | null>(null)
/** Folded by default (just the progress bar); opens with a tap */
const open = ref(false)
const error = ref('')
const busy = ref('')

async function load() {
  try {
    list.value = await api.get<Checklist>('/go-live')
  } catch {
    list.value = null
  }
}
onMounted(load)

async function tick(s: Step) {
  busy.value = s.key
  error.value = ''
  try {
    list.value = await api.post<Checklist>(`/go-live/${s.key}`, { done: !s.done })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t save that.'
  } finally {
    busy.value = ''
  }
}

const percent = computed(() => (list.value ? Math.round((list.value.done / list.value.total) * 100) : 0))
const next = computed(() => list.value?.steps.find((s) => !s.done) ?? null)
</script>

<template>
  <section v-if="list && list.done < list.total" class="card overflow-hidden" aria-labelledby="go-live">
    <button type="button" class="flex w-full items-center gap-4 px-5 py-4 text-left" :aria-expanded="open" @click="open = !open">
      <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-saffron-100 text-saffron-700"><RocketLaunchIcon class="h-5 w-5" aria-hidden="true" /></span>
      <span class="min-w-0 flex-1">
        <span id="go-live" class="block font-semibold">Switching over from the old system</span>
        <span class="block text-sm text-gray-600">{{ list.done }} of {{ list.total }} steps done<template v-if="next"> · next: {{ next.title }}</template></span>
        <span class="mt-2 block h-1.5 overflow-hidden rounded-full bg-gray-100"><span class="block h-full rounded-full bg-brand transition-all" :style="{ width: percent + '%' }" /></span>
      </span>
      <ChevronDownIcon class="h-5 w-5 shrink-0 text-gray-500 transition" :class="open ? 'rotate-180' : ''" aria-hidden="true" />
    </button>
    <p v-if="error" class="mx-5 mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
    <ol v-if="open" class="divide-y divide-line border-t border-line">
      <li v-for="s in list.steps" :key="s.key" class="flex gap-3 px-5 py-4">
        <span
          class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
          :class="s.done ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-700'"
          :aria-label="s.done ? 'Done' : 'Not done'"
        ><CheckIcon v-if="s.done" class="h-4 w-4" /><template v-else>{{ s.n }}</template></span>
        <div class="min-w-0 flex-1">
          <p class="font-medium" :class="s.done ? 'text-gray-500' : ''">{{ s.title }}</p>
          <p class="mt-0.5 text-sm text-gray-600">{{ s.detail }}</p>
          <div v-if="s.months?.length" class="mt-2 flex flex-wrap gap-1.5">
            <span
              v-for="m in s.months"
              :key="m.month"
              class="rounded-full px-2 py-0.5 text-xs font-medium"
              :class="m.payroll ? 'bg-emerald-50 text-emerald-800' : 'bg-gray-100 text-gray-700'"
            >{{ m.label }} · {{ m.payroll ? 'payroll in' : 'no payroll' }}<template v-if="m.entries"> · {{ m.entries }} entries</template></span>
          </div>
          <div class="mt-2 flex flex-wrap items-center gap-2">
            <RouterLink v-if="s.link && !s.done" :to="s.link.to" class="btn-secondary px-3 py-1.5 text-xs">{{ s.link.label }}</RouterLink>
            <label v-if="s.manual" class="tap inline-flex cursor-pointer items-center gap-2 text-sm" :class="{ 'opacity-50': busy === s.key }">
              <input type="checkbox" class="h-4 w-4 accent-brand" :checked="s.done" :disabled="busy === s.key" @change="tick(s)" />
              {{ s.done ? `Done${s.ticked_on ? ' (' + new Date(s.ticked_on).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) + ')' : ''}` : 'Mark as done' }}
            </label>
          </div>
        </div>
      </li>
    </ol>
  </section>
</template>
