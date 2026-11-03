import * as XLSX from 'xlsx'

export interface ReportColumn {
  key: string
  label: string
  type: 'text' | 'money' | 'date' | 'number'
}

export interface Report {
  title: string
  subtitle: string
  columns: ReportColumn[]
  rows: Record<string, string | number | null>[]
  totals: Record<string, string> | null
}

/** Downloads a report as an Excel file: real numbers in money columns, a title, and a totals row */
export function downloadReport(report: Report, saccoName: string) {
  const header = report.columns.map((c) => c.label)
  const value = (c: ReportColumn, v: string | number | null) => {
    if (v === null || v === '') return ''
    if (c.type === 'money' || c.type === 'number') return Number(v)
    return v
  }
  const body = report.rows.map((r) => report.columns.map((c) => value(c, r[c.key])))
  const aoa: (string | number)[][] = [[saccoName], [`${report.title} · ${report.subtitle}`], [], header, ...body]
  if (report.totals) {
    aoa.push(report.columns.map((c, i) => (i === 0 ? 'Total' : c.key in report.totals! ? Number(report.totals![c.key]) : '')))
  }

  const sheet = XLSX.utils.aoa_to_sheet(aoa)
  // Thousands separators on money columns
  const first = 4
  report.columns.forEach((c, ci) => {
    if (c.type !== 'money') return
    for (let ri = first; ri < aoa.length; ri++) {
      const cell = sheet[XLSX.utils.encode_cell({ r: ri, c: ci })]
      if (cell && typeof cell.v === 'number') cell.z = Number.isInteger(cell.v) ? '#,##0' : '#,##0.00'
    }
  })
  sheet['!cols'] = report.columns.map((c) => ({ wch: Math.max(c.label.length + 2, c.type === 'text' ? 18 : 14) }))

  const book = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(book, sheet, report.title.slice(0, 31))
  const stamp = new Date().toLocaleDateString('en-CA')
  XLSX.writeFile(book, `${report.title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${stamp}.xlsx`)
}
