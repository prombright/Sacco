<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { DocumentArrowDownIcon, PrinterIcon, TableCellsIcon } from '@heroicons/vue/24/outline'
import type { Report } from '@/reports'
import { downloadWorkbook } from '@/exporters'
import { loadLogo } from '@/documents'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import { formatDate, formatMoney } from '@/money'
import SaccoMark from '@/components/SaccoMark.vue'

interface AccountStatement {
  opening: string
  money_in: string
  money_out: string
  closing: string
  rows: { date: string; type: string; details: string; amount: string; balance: string }[]
}
interface Statement {
  member: { full_name: string; account_no: string; employee_no: string | null; email: string | null }
  from: string
  to: string
  accounts: { savings: AccountStatement; shares: AccountStatement }
  loans: { loan_no: string; amount: string; months: number; disbursed_on: string; status: string; paid: string; total_left: string; next_due_date: string | null }[]
  printed_at: string
  ledger: Ledger
}
interface LedgerRow { date: string; vno: string | null; shares: number; share: string; savings: string; run_bal: string; interest: string; loan: string; loan_int: string; run_int: string; ln_int: string; details: string }
interface Ledger {
  opening: { savings: string; loan: string; loan_interest: string }
  rows: LedgerRow[]
  totals: { shares: number; share: string; savings: string; interest: string; loan_issue: string; loan_paid: string; interest_charge: string; interest_paid: string }
  closing: { savings: string; loan: string; loan_interest: string }
}

/** A member's statement for a period, laid out for printing. /statement/print or /members/:id/statement */
const route = useRoute()
const auth = useAuth()
const memberId = route.params.id ? Number(route.params.id) : null

const today = new Date()
// no start date: the server gives the whole history, from the member's first entry
const from = ref(String(route.query.from ?? ''))
const to = ref(String(route.query.to ?? today.toLocaleDateString('en-CA')))
const data = ref<Statement | null>(null)
const error = ref('')

async function load() {
  error.value = ''
  try {
    data.value = await api.get<Statement>(memberId ? `/members/${memberId}/statement` : '/me/statement', { from: from.value, to: to.value })
    from.value = data.value.from
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the statement.'
  }
}
// opened with ?print=1 (Monthly statements' print): print once the statement is on screen
onMounted(async () => {
  await load()
  if (route.query.print === '1' && data.value) {
    await nextTick()
    setTimeout(printIt, 300)
  }
})

/** Figures as the old statement printed them: whole numbers with commas, 0 for nothing */
const n = (v: string | number) => Math.round(Number(v)).toLocaleString('en-US')
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
/** 30-Sep-25 */
const shortDate = (d: string) => {
  const x = new Date(d + 'T00:00:00')
  return `${String(x.getDate()).padStart(2, '0')}-${MONTHS[x.getMonth()]}-${String(x.getFullYear()).slice(2)}`
}

function printIt() {
  window.print()
}

/** The ledger's columns, for the Excel copy */
const LEDGER_COLUMNS = [
  { key: 'date', label: 'Date', type: 'date' },
  { key: 'vno', label: 'vno', type: 'text' },
  { key: 'shares', label: 'Shareno', type: 'number' },
  { key: 'share', label: 'Share', type: 'money' },
  { key: 'savings', label: 'Savings', type: 'money' },
  { key: 'run_bal', label: 'Run Bal', type: 'money' },
  { key: 'interest', label: 'Interest', type: 'money' },
  { key: 'loan', label: 'Loan', type: 'money' },
  { key: 'loan_int', label: 'Loan Int.', type: 'money' },
  { key: 'run_int', label: 'Run Int.', type: 'money' },
  { key: 'ln_int', label: 'Ln +Int.', type: 'money' },
  { key: 'details', label: 'Payment Details', type: 'text' },
] as Report['columns']

const making = ref(false)
function asReport(): Report {
  const d = data.value!
  const l = d.ledger
  return {
    title: `Individual Member Statement: ${d.member.full_name} · ${d.member.account_no}`,
    subtitle: `${formatDate(d.from)} to ${formatDate(d.to)} · Loan issue ${n(l.totals.loan_issue)} · Loan pay/offset ${n(l.totals.loan_paid)} · Interest charge ${n(l.totals.interest_charge)} · Interest pay/offset ${n(l.totals.interest_paid)}`,
    columns: LEDGER_COLUMNS,
    rows: [
      { date: d.from, vno: '', shares: null, share: null, savings: null, run_bal: l.opening.savings, interest: null, loan: null, loan_int: null, run_int: l.opening.loan_interest, ln_int: String(Number(l.opening.loan) + Number(l.opening.loan_interest)), details: 'Brought forward', _kind: 'subtotal' },
      ...l.rows,
      { date: d.to, vno: '', shares: l.totals.shares, share: l.totals.share, savings: l.totals.savings, run_bal: l.closing.savings, interest: l.totals.interest, loan: null, loan_int: null, run_int: l.closing.loan_interest, ln_int: String(Number(l.closing.loan) + Number(l.closing.loan_interest)), details: 'Sum', _kind: 'total' },
    ],
    totals: null,
  }
}

/** The PDF: the old statement's columns and summary, with a modern heading carrying the logo (A4 landscape) */
async function statementPdf() {
  const d = data.value!
  const l = d.ledger
  const [{ jsPDF }, { default: autoTable }] = await Promise.all([import('jspdf'), import('jspdf-autotable')])
  const logo = await loadLogo({ sacco: auth.sacco, user: null })
  const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' })
  const W = doc.internal.pageSize.getWidth()
  const H = doc.internal.pageSize.getHeight()
  const M = 10
  const violet: [number, number, number] = [82, 51, 230]
  const deep: [number, number, number] = [61, 31, 194]
  const ink: [number, number, number] = [22, 19, 58]
  const muted: [number, number, number] = [107, 104, 144]
  const tint: [number, number, number] = [246, 244, 255]
  const printed = new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })

  /** The violet heading band: logo, SACCO name and slogan, the title and period on the right */
  const heading = (full: boolean) => {
    const h = full ? 30 : 16
    doc.setFillColor(...deep)
    doc.roundedRect(M, 8, W - 2 * M, h, 3, 3, 'F')
    doc.setFillColor(...violet)
    doc.roundedRect(M, 8, (W - 2 * M) * 0.62, h, 3, 3, 'F')
    let x = M + 6
    if (logo) {
      const s2 = full ? 18 : 10
      doc.setFillColor(255, 255, 255)
      doc.roundedRect(x - 1.5, 8 + (h - s2) / 2 - 1.5, s2 * Math.min(logo.ratio, 1.6) + 3, s2 + 3, 2.5, 2.5, 'F')
      doc.addImage(logo.data, x, 8 + (h - s2) / 2, s2 * Math.min(logo.ratio, 1.6), s2)
      x += s2 * Math.min(logo.ratio, 1.6) + 7
    }
    doc.setTextColor(255, 255, 255)
    doc.setFont('helvetica', 'bold')
    doc.setFontSize(full ? 16 : 11.5)
    doc.text(auth.sacco?.name ?? 'SACCO', x, full ? 20 : 17.5)
    if (full && auth.sacco?.slogan) {
      doc.setFont('helvetica', 'normal')
      doc.setFontSize(9)
      doc.text(auth.sacco.slogan, x, 26)
    }
    doc.setFont('helvetica', 'bold')
    doc.setFontSize(full ? 8 : 7)
    // right-aligned by hand: jsPDF leaves the letter spacing out when it aligns
    const title = 'INDIVIDUAL MEMBER STATEMENT'
    doc.text(title, W - M - 6 - doc.getTextWidth(title) - 0.6 * (title.length - 1), full ? 17 : 14, { charSpace: 0.6 })
    doc.setFontSize(full ? 10.5 : 8.5)
    doc.text(`${formatDate(d.from)} – ${formatDate(d.to)}`, W - M - 6, full ? 24 : 19.5, { align: 'right' })
    if (full) {
      doc.setFont('helvetica', 'normal')
      doc.setFontSize(8)
      doc.text(`${d.member.full_name} · Account ${d.member.account_no}`, W - M - 6, 30, { align: 'right' })
    }
  }

  /** The member's details as tiles under the first heading */
  const facts = () => {
    const items: [string, string][] = [
      ['MEMBER', d.member.full_name],
      ['ACCOUNT NO.', String(d.member.account_no ?? '')],
      ...(d.member.employee_no ? [['EMPLOYEE NO.', d.member.employee_no] as [string, string]] : []),
      ['SAVINGS BALANCE', `UGX ${n(l.closing.savings)}`],
      ['LOAN + INTEREST OWED', `UGX ${n(Number(l.closing.loan) + Number(l.closing.loan_interest))}`],
    ]
    const gap = 3
    const w = (W - 2 * M - gap * (items.length - 1)) / items.length
    items.forEach(([label, value], i) => {
      const x = M + i * (w + gap)
      doc.setFillColor(...tint)
      doc.setDrawColor(235, 229, 255)
      doc.roundedRect(x, 42, w, 14, 2.5, 2.5, 'FD')
      doc.setFont('helvetica', 'bold')
      doc.setFontSize(6.5)
      doc.setTextColor(...muted)
      doc.text(label, x + 4, 47.5, { charSpace: 0.3 })
      doc.setFontSize(9.5)
      doc.setTextColor(...ink)
      doc.text(doc.splitTextToSize(value, w - 8)[0], x + 4, 53)
    })
  }

  const head = [['DATE', 'VNO', 'SHARENO', 'SHARE', 'SAVINGS', 'RUN BAL', 'INTEREST', 'LOAN', 'LOAN INT.', 'RUN INT.', 'LN + INT.', 'PAYMENT DETAILS']]
  const body: (string | number)[][] = [
    [shortDate(d.from), '', '', '', '', n(l.opening.savings), '', '', '', n(l.opening.loan_interest), n(Number(l.opening.loan) + Number(l.opening.loan_interest)), 'Brought forward'],
    ...l.rows.map((r) => [shortDate(r.date), r.vno ?? '', r.shares, n(r.share), n(r.savings), n(r.run_bal), n(r.interest), n(r.loan), n(r.loan_int), n(r.run_int), n(r.ln_int), r.details]),
  ]
  let first = true
  autoTable(doc, {
    head,
    body,
    startY: 62,
    margin: { left: M, right: M, top: 30, bottom: 16 },
    theme: 'plain',
    styles: { font: 'helvetica', fontSize: 7.6, cellPadding: { top: 1.7, bottom: 1.7, left: 1.4, right: 1.4 }, textColor: ink, overflow: 'linebreak' },
    headStyles: { fillColor: violet, textColor: [255, 255, 255], fontStyle: 'bold', fontSize: 7, halign: 'right' },
    alternateRowStyles: { fillColor: [251, 250, 255] },
    columnStyles: {
      0: { halign: 'left', cellWidth: 17 }, 1: { halign: 'left', cellWidth: 24 }, 2: { halign: 'right', cellWidth: 16 }, 3: { halign: 'right' }, 4: { halign: 'right' },
      5: { halign: 'right', fontStyle: 'bold' }, 6: { halign: 'right' }, 7: { halign: 'right' }, 8: { halign: 'right' }, 9: { halign: 'right' }, 10: { halign: 'right', fontStyle: 'bold' }, 11: { halign: 'left', cellWidth: 50, fontSize: 7 },
    },
    didParseCell: (c) => {
      if (c.section === 'head' && (c.column.index <= 1 || c.column.index === 11)) c.cell.styles.halign = 'left'
      if (c.section === 'body' && c.row.index === 0) { c.cell.styles.textColor = muted; c.cell.styles.fontStyle = 'italic' }
    },
    willDrawPage: () => {
      heading(first)
      if (first) facts()
    },
    didDrawPage: () => {
      first = false
    },
  })

  // the summary, in a tinted box like the page
  let y = ((doc as unknown as { lastAutoTable: { finalY: number } }).lastAutoTable.finalY) + 6
  if (y > H - 50) {
    doc.addPage()
    heading(false)
    y = 32
  }
  doc.setFillColor(...tint)
  doc.setDrawColor(235, 229, 255)
  doc.roundedRect(M, y, W - 2 * M, 30, 3, 3, 'FD')
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(7)
  doc.setTextColor(...muted)
  doc.text(`SUMMARY FOR ACCOUNT ${d.member.account_no} (${l.rows.length} DETAIL RECORDS)`, M + 5, y + 6, { charSpace: 0.3 })
  const cells: [string, string][] = [
    ['SHARES', `${l.totals.shares} · UGX ${n(l.totals.share)}`], ['SAVINGS', `UGX ${n(l.totals.savings)}`], ['INTEREST', `UGX ${n(l.totals.interest)}`], ['SAVINGS BALANCE', `UGX ${n(l.closing.savings)}`],
    ['LOAN ISSUE', `UGX ${n(l.totals.loan_issue)}`], ['LOAN PAY/OFFSET', `UGX ${n(l.totals.loan_paid)}`], ['INTEREST CHARGE', `UGX ${n(l.totals.interest_charge)}`], ['INTEREST PAY / OFFSET', `UGX ${n(l.totals.interest_paid)}`],
  ]
  const cw = (W - 2 * M - 10) / 4
  cells.forEach(([label, value], i) => {
    const x = M + 5 + (i % 4) * cw
    const yy = y + 12 + Math.floor(i / 4) * 10
    doc.setFont('helvetica', 'bold')
    doc.setFontSize(6.5)
    doc.setTextColor(...muted)
    doc.text(label, x, yy, { charSpace: 0.3 })
    doc.setFontSize(9.5)
    doc.setTextColor(...ink)
    doc.text(value, x, yy + 4.5)
  })

  // the footer on every page
  const pages = doc.getNumberOfPages()
  for (let i = 1; i <= pages; i++) {
    doc.setPage(i)
    doc.setDrawColor(...violet)
    doc.setLineWidth(0.4)
    doc.line(M, H - 10, W - M, H - 10)
    doc.setFont('helvetica', 'normal')
    doc.setFontSize(7.5)
    doc.setTextColor(...muted)
    doc.text(`${auth.sacco?.name ?? 'SACCO'} · ${d.member.full_name} · Printed ${printed}`, M, H - 5.5)
    doc.setFont('helvetica', 'bold')
    doc.setTextColor(...violet)
    doc.text(`Page ${i} of ${pages}`, W - M, H - 5.5, { align: 'right' })
  }
  doc.save(`statement-${d.member.account_no}-${d.from}-${d.to}.pdf`)
}

async function download(kind: 'pdf' | 'excel') {
  if (!data.value) return
  making.value = true
  const who = { sacco: auth.sacco, user: auth.user?.full_name ?? null }
  try {
    if (kind === 'pdf') await statementPdf()
    else await downloadWorkbook([asReport()], who, `statement ${data.value.member.account_no} ${data.value.from} ${data.value.to}`)
  } finally {
    making.value = false
  }
}
</script>

<template>
  <div class="force-light text-ink print:bg-white print:p-0">
    <div class="mb-4 flex flex-wrap items-end gap-3 print:hidden">
      <button type="button" class="btn-secondary" @click="$router.back()">← Back</button>
      <div><label for="from" class="label">From</label><input id="from" v-model="from" type="date" class="input" /></div>
      <div><label for="to" class="label">To</label><input id="to" v-model="to" type="date" class="input" /></div>
      <button type="button" class="btn-secondary" @click="load">Show</button>
      <div class="ml-auto flex flex-wrap gap-2">
        <button type="button" class="btn-primary" :disabled="!data || making" @click="download('pdf')"><DocumentArrowDownIcon class="h-4 w-4" />Download PDF</button>
        <button type="button" class="btn-secondary" :disabled="!data || making" @click="download('excel')"><TableCellsIcon class="h-4 w-4" />Excel</button>
        <button type="button" class="btn-secondary" :disabled="!data" @click="printIt"><PrinterIcon class="h-4 w-4" />Print</button>
      </div>
    </div>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</div>

    <article v-if="data" class="overflow-hidden rounded-2xl bg-surface p-4 shadow-card sm:p-6 print:max-w-none print:overflow-visible print:rounded-none print:p-0 print:shadow-none">
      <!-- The heading: the SACCO's logo and name on a violet band, the statement's title and period -->
      <header class="stmt-hero">
        <div class="flex min-w-0 items-center gap-4">
          <span class="stmt-logo"><SaccoMark :sacco="auth.sacco" size="lg" /></span>
          <div class="min-w-0">
            <p class="truncate text-xl font-extrabold tracking-[-0.01em] text-white sm:text-2xl">{{ auth.sacco?.name }}</p>
            <p v-if="auth.sacco?.slogan" class="truncate text-sm text-white/75">{{ auth.sacco.slogan }}</p>
          </div>
        </div>
        <div class="sm:text-right">
          <p class="stmt-badge">Individual member statement</p>
          <p class="mt-2 font-bold text-white">{{ formatDate(data.from) }} – {{ formatDate(data.to) }}</p>
          <p class="text-xs text-white/70">Printed {{ formatDate(data.printed_at) }}</p>
        </div>
      </header>

      <!-- Who it is for, and where they stand -->
      <dl class="stmt-facts">
        <div><dt>Member</dt><dd>{{ data.member.full_name }}</dd></div>
        <div><dt>Account no.</dt><dd class="font-mono">{{ data.member.account_no }}</dd></div>
        <div v-if="data.member.employee_no"><dt>Employee no.</dt><dd>{{ data.member.employee_no }}</dd></div>
        <div><dt>Savings balance</dt><dd>{{ formatMoney(data.ledger.closing.savings) }}</dd></div>
        <div><dt>Loan + interest owed</dt><dd>{{ formatMoney(String(Number(data.ledger.closing.loan) + Number(data.ledger.closing.loan_interest))) }}</dd></div>
      </dl>

      <!-- The ledger: savings, shares and loans in one, as the old system printed it -->
      <section class="mt-6">
        <div class="overflow-x-auto print:overflow-visible">
          <table class="stmt-table">
            <thead>
              <tr>
                <th class="text-left">Date</th><th class="text-left">vno</th><th>Shareno</th><th>Share</th><th>Savings</th><th>Run Bal</th><th>Interest</th><th>Loan</th><th>Loan Int.</th><th>Run Int.</th><th>Ln +Int.</th><th class="text-left">Payment Details</th>
              </tr>
            </thead>
            <tbody>
              <tr class="text-gray-500">
                <td>{{ shortDate(data.from) }}</td><td colspan="4" class="text-left">Brought forward</td>
                <td>{{ n(data.ledger.opening.savings) }}</td><td colspan="3"></td>
                <td>{{ n(data.ledger.opening.loan_interest) }}</td>
                <td>{{ n(Number(data.ledger.opening.loan) + Number(data.ledger.opening.loan_interest)) }}</td><td></td>
              </tr>
              <tr v-for="(r, i) in data.ledger.rows" :key="i">
                <td class="whitespace-nowrap">{{ shortDate(r.date) }}</td>
                <td class="stmt-vno">{{ r.vno ?? '' }}</td>
                <td>{{ r.shares }}</td>
                <td>{{ n(r.share) }}</td>
                <td>{{ n(r.savings) }}</td>
                <td>{{ n(r.run_bal) }}</td>
                <td>{{ n(r.interest) }}</td>
                <td>{{ n(r.loan) }}</td>
                <td>{{ n(r.loan_int) }}</td>
                <td>{{ n(r.run_int) }}</td>
                <td>{{ n(r.ln_int) }}</td>
                <td class="stmt-details">{{ r.details }}</td>
              </tr>
              <tr v-if="!data.ledger.rows.length"><td colspan="12" class="!text-center text-gray-500">Nothing on this account in the period.</td></tr>
            </tbody>
          </table>
        </div>

        <!-- The summary, as at the end of the old statement -->
        <div class="stmt-summary">
          <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">Summary for account {{ data.member.account_no }} ({{ data.ledger.rows.length }} detail record{{ data.ledger.rows.length === 1 ? '' : 's' }})</p>
          <dl class="mt-1 grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
            <div><dt>Shares</dt><dd>{{ data.ledger.totals.shares }} · {{ n(data.ledger.totals.share) }}</dd></div>
            <div><dt>Savings</dt><dd>{{ n(data.ledger.totals.savings) }}</dd></div>
            <div><dt>Interest</dt><dd>{{ n(data.ledger.totals.interest) }}</dd></div>
            <div><dt>Savings balance</dt><dd class="font-bold">{{ n(data.ledger.closing.savings) }}</dd></div>
            <div><dt>Loan Issue</dt><dd>{{ n(data.ledger.totals.loan_issue) }}</dd></div>
            <div><dt>Loan Pay/Offset</dt><dd>{{ n(data.ledger.totals.loan_paid) }}</dd></div>
            <div><dt>Interest Charge</dt><dd>{{ n(data.ledger.totals.interest_charge) }}</dd></div>
            <div><dt>Interest Pay / Offset</dt><dd>{{ n(data.ledger.totals.interest_paid) }}</dd></div>
          </dl>
        </div>
      </section>

      <section v-if="data.loans.length" class="mt-7 break-inside-avoid-page">
        <h2 class="text-lg font-bold">Loans</h2>
        <div class="overflow-x-auto print:overflow-visible"><table class="mt-2 w-full min-w-[32rem] text-sm print:min-w-0">
          <thead class="border-y border-gray-300 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
            <tr><th class="py-2 pr-3">Loan</th><th class="py-2 pr-3">Paid out</th><th class="py-2 pr-3 text-right">Amount</th><th class="py-2 pr-3 text-right">Repaid</th><th class="py-2 pr-3 text-right">Still owed</th><th class="py-2 text-right">Next due</th></tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="l in data.loans" :key="l.loan_no">
              <td class="py-1.5 pr-3 font-mono">{{ l.loan_no }}</td>
              <td class="py-1.5 pr-3">{{ formatDate(l.disbursed_on) }} · {{ l.months }} months</td>
              <td class="py-1.5 pr-3 text-right tabular-nums">{{ formatMoney(l.amount) }}</td>
              <td class="py-1.5 pr-3 text-right tabular-nums">{{ formatMoney(l.paid) }}</td>
              <td class="py-1.5 pr-3 text-right font-semibold tabular-nums">{{ l.status === 'closed' ? 'Repaid' : formatMoney(l.total_left) }}</td>
              <td class="py-1.5 text-right">{{ l.next_due_date ? formatDate(l.next_due_date) : '' }}</td>
            </tr>
          </tbody>
        </table></div>
      </section>

      <footer class="mt-10 border-t border-gray-200 pt-3 text-xs text-gray-500">
        This statement is produced by the {{ auth.sacco?.name }} system. Please report any entry you don't recognise to the SACCO office.
      </footer>
    </article>
  </div>
</template>

<style scoped>
.stmt-hero {
  display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem;
  margin: -1rem -1rem 0; padding: 1.4rem 1.5rem; border-radius: 1rem 1rem 0 0;
  background: radial-gradient(circle at 100% 0%, rgba(255, 255, 255, 0.14), transparent 45%), linear-gradient(135deg, #5233e6 0%, #3d1fc2 100%);
  -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
@media (min-width: 640px) { .stmt-hero { margin: -1.5rem -1.5rem 0; padding: 1.6rem 2rem; } }
@media print { .stmt-hero { margin: 0; border-radius: 0.75rem; } }
.stmt-logo { display: inline-flex; flex-shrink: 0; padding: 6px; border-radius: 1rem; background: #fff; box-shadow: 0 6px 16px -8px rgba(0, 0, 0, 0.5); }
.stmt-badge { display: inline-block; padding: 0.3rem 0.8rem; border-radius: 9999px; background: rgba(255, 255, 255, 0.16); color: #fff; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.25); }
.stmt-facts { margin-top: 1.25rem; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem; }
@media (min-width: 900px) { .stmt-facts { grid-template-columns: repeat(5, minmax(0, 1fr)); } }
.stmt-facts > div { min-width: 0; padding: 0.7rem 0.9rem; border-radius: 0.85rem; background: #f6f4ff; border: 1px solid #ebe5ff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.stmt-facts dt { font-size: 0.68rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #6b6890; }
.stmt-facts dd { margin-top: 0.15rem; font-weight: 800; color: #16133a; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
.dark .stmt-facts > div { background: rgb(124 92 245 / 0.1); border-color: rgb(124 92 245 / 0.2); }
.dark .stmt-facts dd { color: #fff; }
.stmt-table { width: 100%; min-width: 64rem; font-size: 0.8rem; font-variant-numeric: tabular-nums; border-collapse: separate; border-spacing: 0; }
.stmt-table thead th { position: sticky; top: 0; background: #f1edff; color: #4b3f8a; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; text-align: right; padding: 0.65rem 0.55rem; white-space: nowrap; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.stmt-table thead th:first-child { border-radius: 0.6rem 0 0 0.6rem; }
.stmt-table thead th:last-child { border-radius: 0 0.6rem 0.6rem 0; }
.stmt-table thead th.text-left { text-align: left; }
.stmt-table tbody td { text-align: right; padding: 0.45rem 0.55rem; border-bottom: 1px solid #efedf6; white-space: nowrap; }
.stmt-table tbody tr:nth-child(even) td { background: #fbfaff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.stmt-table tbody td:first-child, .stmt-table tbody td.stmt-vno, .stmt-table tbody td.stmt-details, .stmt-table tbody td.text-left { text-align: left; }
.stmt-table tbody td.stmt-details { white-space: normal; font-size: 0.75rem; min-width: 11rem; }
.stmt-table tbody td.stmt-vno { max-width: 7rem; overflow: hidden; text-overflow: ellipsis; }
.dark .stmt-table thead th { background: rgb(124 92 245 / 0.18); color: #d5ccff; }
.dark .stmt-table tbody td { border-color: rgb(var(--line)); }
.dark .stmt-table tbody tr:nth-child(even) td { background: rgb(255 255 255 / 0.02); }
.stmt-summary { margin-top: 1rem; padding: 1rem 1.1rem; border-radius: 0.9rem; background: #f6f4ff; border: 1px solid #ebe5ff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.stmt-summary dt { font-size: 0.68rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #6b6890; }
.stmt-summary dd { margin-top: 0.1rem; font-weight: 800; font-variant-numeric: tabular-nums; color: #16133a; }
.dark .stmt-summary { background: rgb(124 92 245 / 0.1); border-color: rgb(124 92 245 / 0.2); }
.dark .stmt-summary dd { color: #fff; }
</style>
