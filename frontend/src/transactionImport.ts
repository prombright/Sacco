import * as XLSX from 'xlsx'

/**
 * Dated transactions from Excel (see TransactionImportController). One row per entry: savings in
 * (above zero) or out (below zero, a withdrawal), shares bought, and loan repayments.
 * Headings are matched loosely: case, spaces and punctuation don't matter.
 */
export const COLUMNS = {
  date: { header: 'Date', also: ['value date', 'transaction date', 'paid on', 'entry date'] },
  account_no: { header: 'Account No', also: ['account number', 'acc no', 'a/c no', 'member no', 'sacco no', 'code', 'member code'] },
  name: { header: 'Name', also: ['full name', 'member name', 'member'] },
  savings: { header: 'Savings', also: ['savings in/out', 'savings deposit', 'deposit'] },
  shares: { header: 'Shares', also: ['share capital', 'shares bought'] },
  loan: { header: 'Loan Repayment', also: ['loan', 'repayment', 'loan paid', 'loan recovery'] },
  loan_no: { header: 'Loan No', also: ['loan number', 'loan ref'] },
  reference: { header: 'Reference', also: ['ref', 'voucher', 'receipt no', 'slip no', 'vno'] },
  details: { header: 'Details', also: ['narration', 'description', 'note', 'notes', 'paydet'] },
} as const

export type Field = keyof typeof COLUMNS
export type TransactionRow = { row: number } & Record<Field, string>

const normalise = (s: unknown) => String(s ?? '').toLowerCase().replace(/[^a-z0-9]/g, '')

export async function readTransactionFile(file: File): Promise<TransactionRow[]> {
  const book = XLSX.read(await file.arrayBuffer(), { type: 'array', cellDates: true })
  const sheet = book.Sheets[book.SheetNames[0]]
  if (!sheet) throw new Error('The file has no sheets.')
  const grid = XLSX.utils.sheet_to_json<unknown[]>(sheet, { header: 1, raw: true, defval: '', blankrows: false })

  const wanted = Object.entries(COLUMNS).map(([field, c]) => ({ field: field as Field, names: [c.header, ...c.also].map(normalise) }))
  const isHeader = (r: unknown[]) => ['date', 'account_no'].every((f) => r.some((cell) => wanted.find((w) => w.field === f)!.names.includes(normalise(cell))))
  const headerIndex = grid.slice(0, 10).findIndex(isHeader)
  if (headerIndex === -1) throw new Error('Couldn\'t find the "Date" and "Account No" columns. Use the template, or check the column headings.')
  const header = grid[headerIndex].map(normalise)
  const at: Partial<Record<Field, number>> = {}
  for (const w of wanted) {
    const i = header.findIndex((h) => w.names.includes(h))
    if (i !== -1) at[w.field] = i
  }
  if (at.savings === undefined && at.shares === undefined && at.loan === undefined) {
    throw new Error('The file needs at least one of the columns "Savings", "Shares" or "Loan Repayment".')
  }

  const text = (v: unknown) => {
    if (v instanceof Date) {
      const d = new Date(v.getTime() + v.getTimezoneOffset() * -60000)
      return d.toISOString().slice(0, 10)
    }
    if (typeof v === 'number') return String(v)
    return String(v ?? '').trim()
  }
  return grid
    .slice(headerIndex + 1)
    .map((r, i) => {
      const row = { row: headerIndex + i + 2 } as TransactionRow
      for (const f of Object.keys(COLUMNS) as Field[]) row[f] = at[f] === undefined ? '' : text(r[at[f]!])
      return row
    })
    .filter((r) => r.date || r.account_no || r.savings || r.shares || r.loan)
}

/** The template, with a few example rows and every member's account number to look up */
export function downloadTransactionTemplate(members: { account_no: string | null; full_name: string }[]) {
  const headers = Object.values(COLUMNS).map((c) => c.header)
  const examples = [
    ['2026-09-30', '004', 'Mugenyi Paul', 200000, 20000, 442591, '', 'PAY-SEP', 'September payroll'],
    ['2026-09-15', '011', 'Kisekka James', 150000, '', '', '', 'BANK 77812', 'Deposit at the bank'],
    ['2026-09-20', '017', 'Nabawanuka Juliet', -300000, '', '', '', 'CHQ 004512', 'Withdrawal (minus = money out)'],
  ]
  const sheet = XLSX.utils.aoa_to_sheet([headers, ...examples])
  sheet['!cols'] = [12, 12, 26, 14, 12, 16, 10, 14, 32].map((wch) => ({ wch }))
  const notes = XLSX.utils.aoa_to_sheet([
    ['How to fill it in'],
    ['One row per entry. Replace the example rows with your own.'],
    ['Date: when it really happened, as 2026-09-30. Rows are applied in date order.'],
    ['Account No: the member\'s SACCO account number (see the Members sheet).'],
    ['Savings: above zero goes in, below zero (e.g. -300000) comes out as a withdrawal.'],
    ['Shares: shares bought. Shares can\'t come out here.'],
    ['Loan Repayment: paid off the member\'s loan. Put the Loan No only if they have more than one loan.'],
    ['New loans are not recorded here: use the Loans page.'],
    ['Nothing is saved until you check the upload and confirm it, and a whole upload can be undone.'],
  ])
  notes['!cols'] = [{ wch: 100 }]
  const list = XLSX.utils.aoa_to_sheet([['Account No', 'Name'], ...members.map((m) => [m.account_no ?? '', m.full_name])])
  list['!cols'] = [{ wch: 12 }, { wch: 32 }]
  const book = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(book, sheet, 'Transactions')
  XLSX.utils.book_append_sheet(book, notes, 'How to fill it in')
  XLSX.utils.book_append_sheet(book, list, 'Members')
  XLSX.writeFile(book, 'transactions-template.xlsx')
}
