// Makes "7 Since the balance date.xlsx" from what extract.ps1 wrote (since.json, loans_since.json,
// clients.json): every entry the old system recorded after the cut-off, in the layout of
// Savings & shares > Import transactions. What that page can't take (new loans, savings interest
// paid, fees, shares paid back) goes on a second sheet to enter by hand.
//
//   node catch-up.mjs <data folder> <output folder>
import { createRequire } from 'node:module'
import { mkdirSync, readFileSync, existsSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const ExcelJS = createRequire(join(here, '../../frontend/package.json'))('exceljs')
const [dataDir, outDir] = process.argv.slice(2)
if (!outDir) throw new Error('Usage: node catch-up.mjs <data> <output>')
mkdirSync(outDir, { recursive: true })
const load = (n) => (existsSync(join(dataDir, `${n}.json`)) ? JSON.parse(readFileSync(join(dataDir, `${n}.json`), 'utf8')) : null)
const since = load('since')
if (!since) throw new Error('since.json is missing: run extract.ps1 again (it now also writes what happened after the cut-off).')
const clients = load('clients') ?? []
const loansSince = load('loans_since') ?? []

const num = (v) => Math.round(Number(v ?? 0))
const key = (v) => String(v ?? '').trim()
const clean = (s) => String(s ?? '').replace(/\s+/g, ' ').trim()
const titleCase = (s) => s.toLowerCase().replace(/(^|[\s'-])(\p{L})/gu, (m, a, b) => a + b.toUpperCase())
const nameOf = (c) => (c ? titleCase(clean(`${c.Surname ?? ''} ${c.OtherNames ?? ''}`).replace(/^(mr|mrs|ms|miss|dr|rev|fr|sr|hon)\.?\s+/i, '')) : '')
const clientBy = new Map(clients.map((c) => [key(c.ClientCode), c]))

const rows = []
const byHand = []
const sorted = [...since].sort((a, b) => String(a.d).localeCompare(String(b.d)) || key(a.MemberId).localeCompare(key(b.MemberId)))
for (const r of sorted) {
  const id = key(r.MemberId)
  const name = nameOf(clientBy.get(id))
  const details = clean(r.paydet)
  const ref = clean(r.vno)
  const sv = num(r.sv), sh = num(r.sh), ln = num(r.ln), li = num(r.li), si = num(r.si), ot = num(r.ot), sb = num(r.sb)
  // A repayment is principal and interest paid (credits to the loan and to interest); interest the
  // old system charged is not brought in: the new system works out its own
  const repaid = (ln < 0 ? -ln : 0) + (li < 0 ? -li : 0)
  const shares = sh > 0 ? sh : 0
  if (sv || shares || repaid) rows.push([r.d, id, name, sv || '', shares || '', repaid || '', '', ref, details])
  if (ln > 0) byHand.push([r.d, id, name, 'New loan given', ln, 'Record it on Loans > Record a loan from old records (or wait for the next old-system file).'])
  if (sh < 0) byHand.push([r.d, id, name, 'Shares paid back', -sh, 'Shares leave only by an internal transfer or when a member exits.'])
  if (si) byHand.push([r.d, id, name, si > 0 ? 'Savings interest credited' : 'Savings interest paid out', Math.abs(si), 'Savings interest payable stays as brought in until the committee decides; record any payment with a transfer or journal.'])
  if (sb) byHand.push([r.d, id, name, 'Annual subscription', Math.abs(sb), 'Record it on Members > Membership & subscription.'])
  if (ot) byHand.push([r.d, id, name, 'Loan fee, pension or penalty', Math.abs(ot), 'Check with the accountant; record a fee or charge if it still applies.'])
}
for (const l of loansSince) {
  if (!byHand.some((h) => h[1] === key(l.MEMBERID) && h[3] === 'New loan given')) {
    byHand.push([l.TDATE, key(l.MEMBERID), nameOf(clientBy.get(key(l.MEMBERID))), 'New loan given', num(l.CurrentLoan), `Old loan ${l.LOANID} at ${l.InterestRate}%. Record it on the Loans page.`])
  }
}

const book = new ExcelJS.Workbook()
const add = (name, cols, data) => {
  const s = book.addWorksheet(name, { views: [{ state: 'frozen', ySplit: 1 }] })
  s.columns = cols.map(([header, width, numFmt]) => ({ header, width, style: numFmt ? { numFmt } : {} }))
  s.getRow(1).font = { bold: true }
  data.forEach((d) => s.addRow(d))
}
const money = '#,##0;[Red]-#,##0'
add('Transactions', [['Date', 12], ['Account No', 12], ['Name', 28], ['Savings', 14, money], ['Shares', 12, money], ['Loan Repayment', 16, money], ['Loan No', 10], ['Reference', 14], ['Details', 40]], rows)
add('Do by hand', [['Date', 12], ['Account No', 12], ['Name', 28], ['What', 28], ['Amount', 14, money], ['How', 80]], byHand)
const file = '7 Since the balance date.xlsx'
await book.xlsx.writeFile(join(outDir, file))
const total = (i) => rows.reduce((s, r) => s + (Number(r[i]) || 0), 0)
console.log(`${file}: ${rows.length} rows (${rows[0]?.[0] ?? '-'} to ${rows.at(-1)?.[0] ?? '-'}); savings net ${total(3).toLocaleString()}, shares ${total(4).toLocaleString()}, loan repayments ${total(5).toLocaleString()}; ${byHand.length} to do by hand`)
