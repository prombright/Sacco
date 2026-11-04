import * as XLSX from 'xlsx'

export interface ReportColumn {
  key: string
  label: string
  type: 'text' | 'money' | 'date' | 'number'
}

/** A row's cells by column key; `_kind` marks statement lines that aren't accounts, `_` keys aren't shown */
export type ReportRow = Record<string, string | number | null | undefined> & {
  _kind?: 'heading' | 'subtotal' | 'total'
  _account_id?: number
}

export interface Report {
  title: string
  subtitle: string
  columns: ReportColumn[]
  rows: ReportRow[]
  totals: Record<string, string> | null
  /** Statements say whether debits equal credits / assets equal liabilities and equity */
  balanced?: boolean
}

/** Downloads a report as an Excel file: real numbers in money columns, a title, and a totals row */
export function downloadReport(report: Report, saccoName: string) {
  const header = report.columns.map((c) => c.label)
  const value = (c: ReportColumn, v: string | number | null | undefined) => {
    if (v === null || v === undefined || v === '') return ''
    if (c.type === 'money' || c.type === 'number') return Number(v)
    return v
  }
  const body = report.rows.map((r) => {
    // A heading's title goes in the first column, whichever column holds it
    if (r._kind === 'heading') {
      const text = report.columns.map((c) => r[c.key]).find((v) => v !== null && v !== undefined && v !== '')
      return [String(text ?? '').toUpperCase(), ...report.columns.slice(1).map(() => '')]
    }
    return report.columns.map((c) => value(c, r[c.key]))
  })
  const printed = `Printed ${new Date().toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}`
  const aoa: (string | number)[][] = [[saccoName], [report.title], [report.subtitle], [printed], [], header, ...body]
  if (report.totals) {
    aoa.push(report.columns.map((c, i) => (i === 0 ? 'Total' : c.key in report.totals! ? Number(report.totals![c.key]) : '')))
  }

  const sheet = XLSX.utils.aoa_to_sheet(aoa)
  // Thousands separators on money columns
  const first = 6
  report.columns.forEach((c, ci) => {
    if (c.type !== 'money') return
    for (let ri = first; ri < aoa.length; ri++) {
      const cell = sheet[XLSX.utils.encode_cell({ r: ri, c: ci })]
      if (cell && typeof cell.v === 'number') cell.z = Number.isInteger(cell.v) ? '#,##0' : '#,##0.00'
    }
  })
  sheet['!cols'] = report.columns.map((c) => ({ wch: Math.max(c.label.length + 2, c.type === 'text' ? 22 : 15) }))

  const book = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(book, sheet, report.title.replace(/[\\/?*[\]:]/g, ' ').slice(0, 31))
  const stamp = new Date().toLocaleDateString('en-CA')
  XLSX.writeFile(book, `${report.title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${stamp}.xlsx`)
}
