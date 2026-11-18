<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api'
import ReportView from '@/components/ReportView.vue'
import type { Report } from '@/reports'

/** After a month's payroll: who had less taken for their loans than fell due (or wasn't on it at all) */
const props = defineProps<{ batchId: number }>()
interface Row { full_name: string; account_no: string | null; employee_no: string | null; loans: string; due: string; deducted: string; short: string; missing: boolean }
const data = ref<{ period: string; rows: Row[]; short_total: string } | null>(null)
const error = ref('')
onMounted(async () => {
  try {
    data.value = await api.get(`/payroll/batches/${props.batchId}/shortfalls`)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t compare with what was due.'
  }
})
const month = (m: string) => new Date(m + '-01T00:00:00').toLocaleDateString('en-GB', { month: 'long', year: 'numeric' })
const report = computed<Report | null>(() =>
  data.value
    ? {
        title: `Loan deductions short, ${month(data.value.period)}`,
        subtitle: `${data.value.rows.length} member${data.value.rows.length === 1 ? '' : 's'} had less taken for their loans than fell due this month`,
        columns: [
          { key: 'full_name', label: 'Member', type: 'text' },
          { key: 'employee_no', label: 'Employee no.', type: 'text' },
          { key: 'loans', label: 'Loans', type: 'text' },
          { key: 'due', label: 'Due this month', type: 'money' },
          { key: 'deducted', label: 'Deducted', type: 'money' },
          { key: 'short', label: 'Short by', type: 'money' },
          { key: 'note', label: '', type: 'text' },
        ],
        rows: data.value.rows.map(({ missing, ...r }) => ({ ...r, note: missing ? 'Not on the payroll' : '' })),
        totals: { short: data.value.short_total },
      }
    : null,
)
</script>

<template>
  <section class="mt-8">
    <h2 class="font-semibold">Deducted against what was due</h2>
    <p class="mt-1 max-w-3xl text-sm text-gray-600">Members whose loan deduction this month came in under the instalments that fell due, or who weren't on the payroll at all. Follow them up with the bursar, or under Arrears follow-up.</p>
    <ReportView class="mt-3" :report="report" :busy="!data && !error" :error="error">
      <template #empty>Every loan instalment due this month was deducted in full.</template>
    </ReportView>
  </section>
</template>
