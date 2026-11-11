// Makes the import files from what extract.ps1 wrote. The headings match the screens exactly
// (frontend/src/memberImport.ts and oldSystemImport.ts).
//
//   node build.mjs <data folder> <output folder> <cut-off, e.g. 2026-08-31>
import { createRequire } from 'node:module'
import { mkdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const ExcelJS = createRequire(join(here, '../../frontend/package.json'))('exceljs')
const [dataDir, outDir, cutOff] = process.argv.slice(2)
if (!cutOff) throw new Error('Usage: node build.mjs <data> <output> <cut-off>')
mkdirSync(outDir, { recursive: true })
const load = (n) => JSON.parse(readFileSync(join(dataDir, `${n}.json`), 'utf8'))
const clients = load('clients'), ledger = load('ledger'), plan = load('plan'), oldLoans = load('loans'), gl = load('gl')

const num = (v) => Math.round(Number(v ?? 0))
const money = '#,##0;[Red]-#,##0'
const key = (v) => String(v ?? '').trim()
const clean = (s) => String(s ?? '').replace(/\s+/g, ' ').trim()
const titleCase = (s) => s.toLowerCase().replace(/(^|[\s'-])(\p{L})/gu, (m, a, b) => a + b.toUpperCase())
const nameOf = (c) => titleCase(clean(`${c.Surname ?? ''} ${c.OtherNames ?? ''}`).replace(/^(mr|mrs|ms|miss|dr|rev|fr|sr|hon)\.?\s+/i, ''))
const phoneOf = (s) => { const d = String(s ?? '').replace(/\D/g, ''); return d.length === 9 ? `0${d}` : d }

// ---- each member's position at the cut-off
const positions = new Map()
for (const r of ledger) {
  const id = key(r.MemberId)
  const p = positions.get(id) ?? { id, sv: 0, sh: 0, ln: 0, li: 0, si: 0, pn: 0, first: r.d, subs: 0 }
  p.sv += num(r.sv); p.sh += num(r.sh); p.ln += num(r.ln); p.li += num(r.li); p.si += num(r.si); p.pn += num(r.pn)
  if (r.d < p.first) p.first = r.d
  if (String(r.d).slice(0, 4) === cutOff.slice(0, 4)) p.subs += num(r.sb)
  positions.set(id, p)
}
const hasMoney = (p) => Math.abs(p.sv) + Math.abs(p.sh) + Math.abs(p.ln) + Math.abs(p.li) + Math.abs(p.si) >= 1
const clientBy = new Map(clients.map((c) => [key(c.ClientCode), c]))
const dedBy = new Map(plan.map((m) => [key(m.MemberId), num(m.MLoan)]))
const latestLoan = new Map()
for (const l of [...oldLoans].sort((a, b) => String(a.TDATE).localeCompare(String(b.TDATE)))) latestLoan.set(key(l.MEMBERID), l)

const members = [], loans = [], payable = [], review = []
const flag = (issue, code, name, amount, what) => review.push([issue, code, name, amount, what])
const sum = (a, f) => a.reduce((n, x) => n + f(x), 0)

for (const p of [...positions.values()].sort((a, b) => a.id.localeCompare(b.id))) {
  if (!hasMoney(p)) continue
  const c = clientBy.get(p.id)
  if (!c) {
    flag('Money under a code with no member record', p.id, '', p.sv + p.sh, `Savings ${p.sv.toLocaleString()}, shares ${p.sh.toLocaleString()}. Not given an account; the amount is in Suspense until someone says whose it is.`)
    continue
  }
  const name = nameOf(c)
  members.push({ name, employee: `SM${p.id}`, account: p.id, phone: phoneOf(c.Telephone), gender: clean(c.Gender), since: p.first, savings: Math.max(p.sv, 0), shares: Math.max(p.sh, 0), subscribed: p.subs > 0 ? Number(cutOff.slice(0, 4)) : '' })
  if (p.ln > 0) {
    const ded = dedBy.get(p.id) ?? 0
    const months = ded > 0 ? Math.min(120, Math.max(1, Math.ceil(p.ln / ded))) : 12
    const yearly = Number(latestLoan.get(p.id)?.InterestRate ?? 12)
    loans.push({ account: p.id, name, amount: p.ln, months, rate: Math.round((yearly / 12) * 100) / 100, purpose: 'Combined balance from the old system' })
    if (ded <= 0) flag('Loan with no monthly deduction on file', p.id, name, p.ln, 'Set to 12 months. Change "Months to repay" in file 2 if a different plan was agreed.')
    else if (months > 24) flag('Loan needs over 24 months at the current deduction', p.id, name, p.ln, `Set to ${months} months.`)
  } else if (p.ln < 0) {
    flag('Loan overpaid: the SACCO owes the member', p.id, name, p.ln, 'Not credited to the member; the amount is in Suspense.')
  }
  if (p.li > 0) {
    loans.push({ account: p.id, name, amount: p.li, months: 12, rate: 0, purpose: 'Interest owed from the old system' })
    if (p.ln <= 0) flag('Interest owed with no loan left', p.id, name, p.li, 'Brought in as an interest-free 12-month balance. Please confirm the member really owes this.')
  } else if (p.li < 0) {
    flag('Loan interest overpaid', p.id, name, p.li, 'Not credited to the member; the amount is in Suspense.')
  }
  if (p.si !== 0) {
    payable.push({ account: p.id, name, amount: p.si })
    if (p.si < 0) flag('Savings interest overpaid', p.id, name, p.si, 'Kept as a negative payable.')
  }
  if (p.pn) flag('Pension in the old member ledger', p.id, name, p.pn, 'The old books have no pension account, so it is not brought in as a balance. It stays in the history.')
}
const payableTotal = sum(payable, (x) => x.amount)
const savingsTotal = sum(members, (x) => x.savings), sharesTotal = sum(members, (x) => x.shares), loansTotal = sum(loans, (x) => x.amount)

// ---- the opening journal
const COVERED = { 3100: 'shares', 3900: 'shares', 3200: 'savings', 3500: 'loans', 3700: 'interest', 4000: 'payable' }
const MAP = { 2100: '1010', 2200: '1010', 2300: '1020', 2400: '1000', 2444: '1000', 3770: '1130', 4500: '1300', 2000: '2900', 3345: '2900', 3750: '2900', 4020: '2900' }
const NEW_NAME = { 1000: 'Bank account', 1010: 'Cash on hand', 1020: 'Funds in transit', 1130: 'Loan fees receivable', 1300: 'Projects', 2010: 'Savings interest payable', 2900: 'Suspense', 3100: 'Retained surplus', 3900: 'Opening balances' }
const lines = [], chart = []
const glBy = Object.fromEntries(gl.map((g) => [key(g.ACODE), num(g.net)]))
let retained = 0, oldTotal = 0
for (const g of [...gl].sort((a, b) => key(a.ACODE).localeCompare(key(b.ACODE)))) {
  const code = key(g.ACODE), net = num(g.net), name = clean(g.name)
  oldTotal += net
  if (!net) continue
  if (COVERED[code]) { chart.push([code, name, net, 'Member files', `Comes from each member's ${COVERED[code]}`]); continue }
  if (g.type === 'NML' || code === '8820') { retained += net; chart.push([code, name, net, '3100', 'Retained surplus']); continue }
  if (!MAP[code]) throw new Error(`No home for old account ${code} ${name}`)
  lines.push({ code: MAP[code], net, memo: `Old account ${code} ${name}` })
  chart.push([code, name, net, MAP[code], NEW_NAME[MAP[code]]])
}
lines.push({ code: '3100', net: retained, memo: 'Income, expenses and retained earnings to the balance date' })
lines.push({ code: '2010', net: -payableTotal, memo: "Members' savings interest payable, see file 3" })
const openingNet = savingsTotal + sharesTotal - loansTotal
lines.push({ code: '3900', net: -openingNet, memo: 'Balances against the opening entries from files 1 and 2' })
const outOfBalance = -sum(lines, (l) => l.net)
lines.push({ code: '2900', net: outOfBalance, memo: 'Out of balance from the old system, to be sorted' })

// ---- writing
async function write(file, sheetName, columns, rows, extra) {
  const book = new ExcelJS.Workbook()
  const sheet = book.addWorksheet(sheetName, { views: [{ state: 'frozen', ySplit: 1 }] })
  sheet.columns = columns.map(([header, width, fmt]) => ({ header, width, style: fmt ? { numFmt: fmt } : {} }))
  for (const r of rows) sheet.addRow(r)
  const head = sheet.getRow(1); head.font = { bold: true, color: { argb: 'FFFFFFFF' } }
  head.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF2B35AF' } }
  if (extra) extra(book)
  await book.xlsx.writeFile(join(outDir, file))
  console.log(file, rows.length)
}

await write('1 Members.xlsx', 'Members', [['Full name', 32], ['Email', 22], ['Employee no', 13], ['Account no', 12], ['Phone', 14], ['Gender', 8], ['Member since', 13], ['Balances as at', 14], ['Opening savings', 16, money], ['Opening shares', 15, money], ['Membership fee paid', 19], ['Subscription paid for', 20]],
  members.map((m) => [m.name, '', m.employee, m.account, m.phone, m.gender, m.since, cutOff, m.savings, m.shares, 'Yes', m.subscribed]))
await write('2 Old loans.xlsx', 'Loans', [['Account no', 12], ['Full name', 32], ['Loan type', 14], ['Amount owed', 15, money], ['Months to repay', 16], ['Interest % a month', 18], ['Balance as at', 13], ['Purpose', 38]],
  loans.map((l) => [l.account, l.name, 'Normal loan', l.amount, l.months, l.rate, cutOff, l.purpose]))
await write('3 Savings interest payable.xlsx', 'Payable', [['Account no', 12], ['Full name', 32], ['Savings interest payable', 24, money], ['Balance as at', 13]],
  payable.map((p) => [p.account, p.name, p.amount, cutOff]))
await write('4 Opening journal.xlsx', 'Journal', [['Account code', 13], ['Account name', 28], ['Debit', 16, money], ['Credit', 16, money], ['Memo', 58], ['Date', 12]],
  lines.filter((l) => l.net).map((l) => [l.code, NEW_NAME[l.code], l.net > 0 ? l.net : null, l.net < 0 ? -l.net : null, l.memo, cutOff]))
const history = ledger.filter((r) => [r.sv, r.sh, r.ln, r.li, r.si, r.ot].some((v) => num(v) !== 0)).sort((a, b) => key(a.MemberId).localeCompare(key(b.MemberId)) || String(a.d).localeCompare(String(b.d)))
await write('5 Old history.xlsx', 'Old history', [['Account no', 11], ['Date', 12], ['Details', 34], ['Voucher', 12], ['Savings', 14, money], ['Shares', 13, money], ['Loan', 14, money], ['Loan interest', 13, money], ['Savings interest', 15, money], ['Other', 12, money]],
  history.map((r) => [key(r.MemberId), r.d, clean(r.paydet), clean(r.vno), num(r.sv), num(r.sh), num(r.ln), num(r.li), num(r.si), num(r.ot)]))

const mine = (f) => sum([...positions.values()], f)
const check = [
  ['Balances as at', cutOff, '', ''],
  ['', '', '', ''],
  ['What', 'In the files', 'Old general ledger', 'Difference'],
  ['Savings', savingsTotal, -(glBy[3200] ?? 0), savingsTotal + (glBy[3200] ?? 0)],
  ['Shares (with share premium)', sharesTotal, -((glBy[3100] ?? 0) + (glBy[3900] ?? 0)), sharesTotal + (glBy[3100] ?? 0) + (glBy[3900] ?? 0)],
  ['Loans owed, with interest (file 2)', loansTotal, (glBy[3500] ?? 0) + (glBy[3700] ?? 0), loansTotal - (glBy[3500] ?? 0) - (glBy[3700] ?? 0)],
  ['Savings interest payable', payableTotal, -(glBy[4000] ?? 0), payableTotal + (glBy[4000] ?? 0)],
  ['', '', '', ''],
  ['Old general ledger out of balance on its own', oldTotal, '', ''],
  ['Goes to Suspense in file 4', outOfBalance, '', ''],
  ['', '', '', ''],
  ['Members with a balance', members.length, '', ''],
  ['Loans in file 2', loans.length, '', ''],
  ['Marked as having paid the membership fee', members.length, '', ''],
  [`Marked as having paid the ${cutOff.slice(0, 4)} subscription`, members.filter((m) => m.subscribed).length, '', ''],
  ['Rows of old history', history.length, '', ''],
  ['Everyone in the old ledger: savings / shares', mine((p) => p.sv), mine((p) => p.sh), ''],
]
const steps = [
  ['Import in this order, signed in as the super admin'],
  [''],
  ['1. Members > Add members from Excel: upload "1 Members.xlsx". Download the sign-in slips it gives you: they are shown once.'],
  ['2. From that page, open "After the members" and upload file 2 (loans).'],
  ['3. Upload file 3 (savings interest payable).'],
  ['4. Upload file 4 (opening journal).'],
  ['5. Upload file 5 (old history).'],
  [''],
  ['Members sign in with their Employee no, for example SM123 (SM and their old SACCO code). Emails can be added later on each member.'],
  ['Everything is dated at the balance date. Anything after it in the old system has to be entered in the new one, or the files made again from a newer copy.'],
]
await write('0 READ ME - order, checks, items to review.xlsx', 'Order', [['Steps', 120]], steps, (book) => {
  const add = (name, cols, rows) => {
    const s = book.addWorksheet(name, { views: [{ state: 'frozen', ySplit: 1 }] })
    s.columns = cols.map(([header, width, fmt]) => ({ header, width, style: fmt ? { numFmt: fmt } : {} }))
    for (const r of rows) s.addRow(r)
    s.getRow(1).font = { bold: true }
  }
  add('Totals check', [['Check', 46], ['Value', 18, money], ['Compare', 18, money], ['Difference', 16, money]], check)
  add('Accounts mapping', [['Old code', 9], ['Old account', 32], ['Old balance (Dr +)', 18, money], ['New code', 13], ['Where it goes', 34]], chart)
  add('To review', [['Issue', 44], ['Code', 8], ['Member', 32], ['Amount', 15, money], ['What was done', 80]], review.sort((a, b) => a[0].localeCompare(b[0])))
})

console.log(JSON.stringify({ members: members.length, savingsTotal, sharesTotal, loans: loans.length, loansTotal, payableTotal, payableRows: payable.length, oldTotal, outOfBalance, retained, history: history.length, review: review.length }))
