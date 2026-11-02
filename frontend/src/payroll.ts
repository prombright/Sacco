import * as XLSX from 'xlsx'

/**
 * The payroll sheet's layout. This is OUR template for now; when the SACCO brings its real payroll
 * sheet, change COLUMNS (the header names to look for) and nothing else needs to move.
 *
 * Headers are matched loosely: case, spaces and punctuation are ignored, and any of the listed
 * names counts, so "A/C No." and "Account Number" both find the account column.
 */
export const COLUMNS = {
  account_no: { header: 'Account No', also: ['account number', 'acc no', 'a/c no', 'member no', 'sacco no'] },
  name: { header: 'Name', also: ['full name', 'member name', 'staff name', 'employee name'] },
  savings: { header: 'Savings', also: ['savings deduction', 'monthly savings', 'deposit'] },
  shares: { header: 'Shares', also: ['share capital', 'shares deduction'] },
  loan: { header: 'Loan Repayment', also: ['loan', 'loan deduction', 'loan recovery', 'repayment'] },
  loan_no: { header: 'Loan No', also: ['loan number', 'loan ref'] },
} as const

export type Field = keyof typeof COLUMNS

export interface PayrollRow {
  /** The row number in the spreadsheet, so problems can be found in the file */
  row: number
  account_no: string
  name: string
  savings: string
  shares: string
  loan: string
  loan_no: string
}

const normalise = (s: unknown) => String(s ?? '').toLowerCase().replace(/[^a-z0-9]/g, '')

/** Reads the first sheet of an .xlsx/.xls/.csv file into rows. Throws a message for the user. */
export async function readPayrollFile(file: File): Promise<PayrollRow[]> {
  const workbook = XLSX.read(await file.arrayBuffer(), { type: 'array' })
  const sheet = workbook.Sheets[workbook.SheetNames[0]]
  if (!sheet) throw new Error('The file has no sheets.')
  const grid = XLSX.utils.sheet_to_json<unknown[]>(sheet, { header: 1, raw: false, defval: '', blankrows: false })

  // The header row is the first one (of the top 10) that names an account column
  const wanted = Object.entries(COLUMNS).map(([field, c]) => ({ field: field as Field, names: [c.header, ...c.also].map(normalise) }))
  const headerIndex = grid.slice(0, 10).findIndex((r) => r.some((cell) => wanted[0].names.includes(normalise(cell))))
  if (headerIndex === -1) {
    throw new Error(`Couldn't find the "${COLUMNS.account_no.header}" column. Use the template, or check the column headings.`)
  }
  const header = grid[headerIndex].map(normalise)
  const at: Partial<Record<Field, number>> = {}
  for (const w of wanted) {
    const i = header.findIndex((h) => w.names.includes(h))
    if (i !== -1) at[w.field] = i
  }
  if (at.savings === undefined && at.shares === undefined && at.loan === undefined) {
    throw new Error('Couldn\'t find a Savings, Shares or Loan Repayment column.')
  }

  const cell = (r: unknown[], f: Field) => (at[f] === undefined ? '' : String(r[at[f]!] ?? '').trim())
  return grid
    .slice(headerIndex + 1)
    .map((r, i) => ({
      row: headerIndex + i + 2,
      account_no: cell(r, 'account_no'),
      name: cell(r, 'name'),
      savings: cell(r, 'savings'),
      shares: cell(r, 'shares'),
      loan: cell(r, 'loan'),
      loan_no: cell(r, 'loan_no'),
    }))
    // Skip blank lines and a totals line at the bottom
    .filter((r) => r.account_no !== '' || r.savings !== '' || r.shares !== '' || r.loan !== '')
    .filter((r) => !/^total/i.test(r.account_no) && !/^total/i.test(r.name))
}

export interface TemplateMember {
  account_no: string
  full_name: string
  employee_no: string | null
  last_savings: string | null
  last_shares: string | null
  loan_no: string | null
  loan_due: string | null
}

/** Downloads the template: every active member, pre-filled with last month's amounts and loan instalments */
export function downloadTemplate(members: TemplateMember[], period: string) {
  const header = [COLUMNS.account_no.header, COLUMNS.name.header, COLUMNS.savings.header, COLUMNS.shares.header, COLUMNS.loan.header, COLUMNS.loan_no.header]
  const num = (v: string | null) => (v && Number(v) > 0 ? Number(v) : '')
  const rows = members.map((m) => [m.account_no, m.full_name, num(m.last_savings), num(m.last_shares), num(m.loan_due), m.loan_no ?? ''])
  const sheet = XLSX.utils.aoa_to_sheet([header, ...rows])
  sheet['!cols'] = [{ wch: 12 }, { wch: 28 }, { wch: 12 }, { wch: 12 }, { wch: 15 }, { wch: 10 }]
  const book = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(book, sheet, 'Payroll')
  XLSX.writeFile(book, `payroll-${period}.xlsx`)
}
