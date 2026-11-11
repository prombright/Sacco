import * as XLSX from 'xlsx'

/** The files made from the old accounting system, and the headings each one has */
export const OLD_FILES = {
  loans: {
    account_no: 'Account no', full_name: 'Full name', product: 'Loan type', principal: 'Amount owed',
    months: 'Months to repay', rate: 'Interest % a month', start_date: 'Balance as at', purpose: 'Purpose',
  },
  payable: { account_no: 'Account no', full_name: 'Full name', amount: 'Savings interest payable', as_at: 'Balance as at' },
  journal: { code: 'Account code', account: 'Account name', debit: 'Debit', credit: 'Credit', memo: 'Memo', date: 'Date' },
  history: {
    account_no: 'Account no', date: 'Date', details: 'Details', voucher: 'Voucher', savings: 'Savings', shares: 'Shares',
    loan: 'Loan', loan_interest: 'Loan interest', savings_interest: 'Savings interest', other: 'Other',
  },
} as const

export type OldFile = keyof typeof OLD_FILES
export type OldRow = { row: number } & Record<string, string>

const normalise = (s: unknown) => String(s ?? '').toLowerCase().replace(/[^a-z0-9]/g, '')

export async function readOldFile(kind: OldFile, file: File): Promise<OldRow[]> {
  const book = XLSX.read(await file.arrayBuffer(), { type: 'array' })
  const sheet = book.Sheets[book.SheetNames[0]]
  if (!sheet) throw new Error('The file has no sheets.')
  const grid = XLSX.utils.sheet_to_json<unknown[]>(sheet, { header: 1, raw: true, defval: '', blankrows: false })
  const header = (grid[0] ?? []).map(normalise)
  const at = Object.entries(OLD_FILES[kind]).map(([field, title]) => [field, header.indexOf(normalise(title)), title] as const)
  const missing = at.filter(([, i]) => i === -1).map(([, , title]) => title)
  if (missing.length) throw new Error(`This isn't the right file: it has no ${missing.join(', ')} column.`)
  return grid.slice(1).map((r, i) => {
    const row = { row: i + 2 } as OldRow
    for (const [field, index] of at) row[field] = String(r[index] ?? '').trim()
    return row
  })
}
