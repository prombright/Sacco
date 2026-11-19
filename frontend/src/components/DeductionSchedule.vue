<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api'
import ReportView from '@/components/ReportView.vue'
import type { Report } from '@/reports'

/**
 * The deduction schedule for the bursar: what each member's pay should give the SACCO this month -
 * savings and shares as in the last payroll, every loan instalment due across all their loans, and
 * what was already late - with totals and lines to sign. Excel for HR, PDF to sign and hand over.
 */
interface Row { full_name: string; account_no: string | null; employee_no: string | null; savings: string; shares: string; loans: string; loan_due: string; arrears: string; total: string }

const period = ref(new Date().toLocaleDateString('en-CA').slice(0, 7))
const data = ref<{ period: string; based_on: string | null; rows: Row[]; totals: Record<string, string> } | null>(null)
const busy = ref(false)
const error = ref('')
async function load() {
  busy.value = true
  error.value = ''
  try {
    data.value = await api.get('/payroll/schedule', { period: period.value })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t make the schedule.'
  } finally {
    busy.value = false
  }
}
watch(period, (v) => v && load())
onMounted(load)

const month = (m: string | null) => (m ? new Date(m + '-01T00:00:00').toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }) : '')
const report = computed<Report | null>(() =>
  data.value
    ? {
        title: `Deduction schedule, ${month(data.value.period)}`,
        subtitle: `${data.value.rows.length} members · savings and shares as in the ${data.value.based_on ? month(data.value.based_on) : 'last'} payroll · loans: every instalment due this month, and what is late from before`,
        columns: [
          { key: 'full_name', label: 'Member', type: 'text' },
          { key: 'employee_no', label: 'Employee no.', type: 'text' },
          { key: 'account_no', label: 'Account no.', type: 'text' },
          { key: 'savings', label: 'Savings', type: 'money' },
          { key: 'shares', label: 'Shares', type: 'money' },
          { key: 'loans', label: 'Loans', type: 'text' },
          { key: 'loan_due', label: 'Loan due this month', type: 'money' },
          { key: 'arrears', label: 'Late from before', type: 'money' },
          { key: 'total', label: 'Total to deduct', type: 'money' },
        ],
        rows: data.value.rows,
        totals: data.value.totals,
        signoff: ['Prepared by (SACCO office)', 'Approved by', 'Received by (bursar)'],
      }
    : null,
)
</script>

<template>
  <section class="mt-5">
    <h2 class="font-semibold">Deduction schedule for the bursar</h2>
    <p class="mt-1 max-w-3xl text-sm text-gray-600 phone-hide">
      What each member's pay should give the SACCO this month: savings and shares as last month, every loan instalment due across all their loans, and what is late from before.
      Download it for HR, or print it to sign and hand to the bursar before payday.
    </p>
    <ReportView class="mt-3" :report="report" :busy="busy" :error="error">
      <template #controls>
        <div><label for="sched-month" class="label">Month</label><input id="sched-month" v-model="period" type="month" class="input" /></div>
      </template>
      <template #empty>No member has anything to deduct this month.</template>
    </ReportView>
  </section>
</template>
