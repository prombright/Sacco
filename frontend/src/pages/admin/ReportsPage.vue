<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api'
import type { Report } from '@/reports'
import ReportView from '@/components/ReportView.vue'
import Modal from '@/components/Modal.vue'
import { ArrowDownTrayIcon, DocumentTextIcon, EyeIcon } from '@heroicons/vue/24/outline'
import type { ReportRow } from '@/reports'
import { useAuth } from '@/stores/auth'
import { downloadMemberSummary, summaryFacts } from '@/memberSummary'

interface ReportInfo {
  key: string
  title: string
  description: string
  needs_dates: boolean
}

const route = useRoute()
const list = ref<ReportInfo[]>([])
const selected = ref<ReportInfo | null>(null)
const today = new Date()
const from = ref(new Date(today.getFullYear(), today.getMonth(), 1).toLocaleDateString('en-CA'))
const to = ref(today.toLocaleDateString('en-CA'))
const report = ref<Report | null>(null)
const error = ref('')
const busy = ref(false)

onMounted(async () => {
  list.value = (await api.get<{ reports: ReportInfo[] }>('/reports')).reports
  pickFromRoute()
})
// Members summary and Loan portfolio open straight on their report
function pickFromRoute() {
  const fixed = list.value.find((r) => r.key === route.meta.report)
  if (fixed) open(fixed)
  else selected.value = null
}
watch(() => route.meta.report, pickFromRoute)

async function open(r: ReportInfo) {
  selected.value = r
  await run()
}

async function run() {
  if (!selected.value) return
  error.value = ''
  busy.value = true
  report.value = null
  try {
    report.value = await api.get<Report>(`/reports/${selected.value.key}`, selected.value.needs_dates ? { from: from.value, to: to.value } : undefined)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t run the report.'
  } finally {
    busy.value = false
  }
}


// Members summary: each member's line opens on its own (View) or downloads as a PDF
const auth = useAuth()
const viewing = ref<ReportRow | null>(null)
const saving = ref<number | null>(null)
const facts = computed(() => (viewing.value ? summaryFacts(viewing.value, auth.sacco?.currency ?? 'UGX') : null))
async function downloadSummary(row: ReportRow) {
  saving.value = Number(row._user_id)
  try {
    await downloadMemberSummary(row, auth.sacco, report.value?.subtitle ?? '')
  } finally {
    saving.value = null
  }
}
</script>

<template>
  <div>
    <template v-if="route.meta.report">
      <PageHeader eyebrow="Reports">
        <template #title>{{ route.meta.title }}</template>
        {{ selected?.description }}
      </PageHeader>
    </template>
    <template v-else>
      <PageHeader eyebrow="Administration" title="Other reports">
        For the office, the committee and the auditors. Each one downloads as Excel and prints or saves as PDF.
      </PageHeader>
      <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <button
          v-for="r in list"
          :key="r.key"
          type="button"
          class="card-link p-4 text-left"
          :class="{ '!border-brand ring-2 ring-brand/20': selected?.key === r.key }"
          @click="open(r)"
        >
          <p class="font-medium">{{ r.title }}</p>
          <p class="mt-1 text-sm text-gray-600">{{ r.description }}</p>
        </button>
      </div>
    </template>

    <ReportView v-if="selected" class="mt-6" :report="report" :busy="busy" :error="error">
      <template v-if="selected.needs_dates" #controls>
        <div><label for="from" class="label">From</label><input id="from" v-model="from" type="date" class="input" @change="run" /></div>
        <div><label for="to" class="label">To</label><input id="to" v-model="to" type="date" class="input" @change="run" /></div>
      </template>
      <template v-if="selected.key === 'members'" #actions="{ row }">
        <div v-if="row._user_id" class="inline-flex justify-center gap-1.5" @click.stop>
          <button type="button" class="icon-gold inline-flex h-9 w-9 items-center justify-center rounded-xl transition hover:brightness-95" :aria-label="`View ${row.full_name}'s summary`" title="View summary" @click="viewing = row"><EyeIcon class="h-5 w-5" /></button>
          <button type="button" class="icon-gold inline-flex h-9 w-9 items-center justify-center rounded-xl transition hover:brightness-95 disabled:opacity-50" :aria-label="`Download ${row.full_name}'s summary`" title="Download summary (PDF)" :disabled="saving === Number(row._user_id)" @click="downloadSummary(row)"><ArrowDownTrayIcon class="h-5 w-5" :class="{ 'animate-pulse': saving === Number(row._user_id) }" /></button>
        </div>
      </template>
    </ReportView>

    <Modal v-if="viewing && facts" title="Member summary" @close="viewing = null">
      <div class="flex items-center gap-3">
        <span class="icon-gold inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl text-sm font-bold">{{ String(viewing.full_name ?? '').split(' ').map((w) => w[0]).slice(0, 2).join('') }}</span>
        <div class="min-w-0">
          <p class="truncate text-lg font-bold text-[#16133a] dark:text-white">{{ viewing.full_name }}</p>
          <p class="text-xs text-gray-500">{{ report?.subtitle }}</p>
        </div>
      </div>
      <dl class="mt-4 grid grid-cols-2 gap-2">
        <div v-for="f in facts.member" :key="f.label" class="rounded-xl border border-line bg-[#f6f4ff] px-3 py-2 dark:bg-white/5">
          <dt class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ f.label }}</dt>
          <dd class="mt-0.5 truncate text-sm font-semibold">{{ f.value }}</dd>
        </div>
      </dl>
      <dl class="mt-4 divide-y divide-line overflow-hidden rounded-xl border border-line">
        <div v-for="f in facts.money" :key="f.label" class="flex items-center justify-between gap-3 px-3 py-2.5" :class="f.lead ? 'bg-[#f6f4ff] font-semibold dark:bg-white/5' : ''">
          <dt class="text-sm">{{ f.label }}</dt>
          <dd class="text-sm tabular-nums">{{ f.value }}</dd>
        </div>
      </dl>
      <div class="mt-5 grid grid-cols-2 gap-2">
        <RouterLink :to="{ name: 'member-statement', params: { id: viewing._user_id } }" class="btn-secondary justify-center"><DocumentTextIcon class="h-4 w-4" />Full statement</RouterLink>
        <button type="button" class="btn-primary justify-center" :disabled="saving !== null" @click="downloadSummary(viewing)"><ArrowDownTrayIcon class="h-4 w-4" />Download PDF</button>
      </div>
    </Modal>
  </div>
</template>
