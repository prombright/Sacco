import * as XLSX from 'xlsx'

/**
 * The member import sheet. Headers are matched loosely (case, spaces and punctuation ignored, and
 * the alternatives listed), so a sheet exported from the SACCO's old records usually works as it is.
 */
const COLUMNS = {
  full_name: { header: 'Full name', also: ['name', 'member name', 'staff name', 'names'] },
  email: { header: 'Email', also: ['email address', 'e-mail'] },
  employee_no: { header: 'Employee no', also: ['employee number', 'staff no', 'staff number', 'payroll no', 'emp no'] },
  account_no: { header: 'Account no', also: ['account number', 'a/c no', 'member no', 'sacco no'] },
  phone: { header: 'Phone', also: ['phone number', 'telephone', 'mobile', 'tel'] },
  joined_on: { header: 'Member since', also: ['date joined', 'joined', 'joining date', 'membership date'] },
  savings: { header: 'Opening savings', also: ['savings', 'savings balance'] },
  shares: { header: 'Opening shares', also: ['shares', 'share capital', 'shares balance'] },
} as const

type Field = keyof typeof COLUMNS
export type ImportRow = { row: number } & Record<Field, string>

const normalise = (s: unknown) => String(s ?? '').toLowerCase().replace(/[^a-z0-9]/g, '')

export async function readMemberFile(file: File): Promise<ImportRow[]> {
  // cellDates: Excel dates come back as dates, which we write as YYYY-MM-DD
  const book = XLSX.read(await file.arrayBuffer(), { type: 'array', cellDates: true })
  const sheet = book.Sheets[book.SheetNames[0]]
  if (!sheet) throw new Error('The file has no sheets.')
  const grid = XLSX.utils.sheet_to_json<unknown[]>(sheet, { header: 1, raw: true, defval: '', blankrows: false })

  const wanted = Object.entries(COLUMNS).map(([field, c]) => ({ field: field as Field, names: [c.header, ...c.also].map(normalise) }))
  const headerIndex = grid.slice(0, 10).findIndex((r) => r.some((cell) => wanted[0].names.includes(normalise(cell))))
  if (headerIndex === -1) throw new Error('Couldn\'t find a "Full name" column. Use the template, or check the column headings.')
  const header = grid[headerIndex].map(normalise)
  const at: Partial<Record<Field, number>> = {}
  for (const w of wanted) {
    const i = header.findIndex((h) => w.names.includes(h))
    if (i !== -1) at[w.field] = i
  }

  const text = (v: unknown) => {
    if (v instanceof Date) {
      // Excel dates are whole days; build the date in local time so it doesn't shift a day
      const d = new Date(v.getTime() + v.getTimezoneOffset() * -60000)
      return d.toISOString().slice(0, 10)
    }
    return String(v ?? '').trim()
  }
  return grid
    .slice(headerIndex + 1)
    .map((r, i) => {
      const row = { row: headerIndex + i + 2 } as ImportRow
      for (const f of Object.keys(COLUMNS) as Field[]) row[f] = at[f] === undefined ? '' : text(r[at[f]!])
      return row
    })
    .filter((r) => r.full_name || r.email || r.employee_no || r.account_no)
}

export function downloadMemberTemplate() {
  const header = Object.values(COLUMNS).map((c) => c.header)
  const example = ['Jane Namukasa', 'jane@stmarks.ac.ug', 'T045', '', '0772000000', '2019-02-01', 1250000, 100000]
  const sheet = XLSX.utils.aoa_to_sheet([header, example])
  sheet['!cols'] = [{ wch: 26 }, { wch: 26 }, { wch: 13 }, { wch: 12 }, { wch: 14 }, { wch: 13 }, { wch: 16 }, { wch: 15 }]
  const book = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(book, sheet, 'Members')
  XLSX.writeFile(book, 'members-import-template.xlsx')
}

/** The new members' sign-in details, to print and hand out privately */
export function downloadLoginSlips(created: { full_name: string; account_no: string; login: string; temporary_password: string }[], saccoName: string) {
  const sheet = XLSX.utils.aoa_to_sheet([
    [`${saccoName}: sign-in details for new members`],
    ['Give each member their own line privately. They choose their own password the first time they sign in.'],
    [],
    ['Name', 'Account no', 'Sign in with', 'Temporary password'],
    ...created.map((c) => [c.full_name, c.account_no, c.login, c.temporary_password]),
  ])
  sheet['!cols'] = [{ wch: 28 }, { wch: 12 }, { wch: 28 }, { wch: 20 }]
  const book = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(book, sheet, 'Sign-in details')
  XLSX.writeFile(book, `new-member-logins-${new Date().toLocaleDateString('en-CA')}.xlsx`)
}
