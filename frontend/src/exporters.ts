import type { Report, ReportColumn, ReportRow } from '@/reports'
import type { Sacco } from '@/types'

/**
 * Report downloads that look like the SACCO's own documents: an Excel workbook with the SACCO's
 * heading, a coloured header row, banded rows, totals and print settings; and a PDF with a
 * letterhead, the same table and page numbers. Both libraries load only when someone downloads.
 */

interface Who {
  sacco: Sacco | null
  user: string | null
}

const numeric = (c: ReportColumn) => c.type === 'money' || c.type === 'number'
const isHeading = (r: ReportRow) => r._kind === 'heading'
const headingText = (report: Report, r: ReportRow) => String(report.columns.map((c) => r[c.key]).find((v) => v !== null && v !== undefined && v !== '') ?? '')
const fileName = (report: Report, ext: string) => `${report.title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${new Date().toLocaleDateString('en-CA')}.${ext}`
const printedLine = (who: Who) =>
  `Printed ${new Date().toLocaleString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}${who.user ? ` by ${who.user}` : ''}`

/** The SACCO's colour as hex without '#', falling back to SMACON blue */
const brandHex = (who: Who) => (who.sacco?.brand_color ?? '#2b35af').replace('#', '').toUpperCase()
const rgb = (hex: string): [number, number, number] => [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16)) as [number, number, number]
// A light tint of the brand colour for totals rows
const tint = (hex: string, amount = 0.88) => rgb(hex).map((v) => Math.round(v + (255 - v) * amount)) as [number, number, number]
const toHex = (c: number[]) => c.map((v) => v.toString(16).padStart(2, '0')).join('').toUpperCase()

const SAFFRON_TINT = 'FDF3D7'
const BAND = 'F7F6F3'

function columnLabel(c: ReportColumn, who: Who) {
  return c.type === 'money' ? `${c.label} (${who.sacco?.currency ?? 'UGX'})` : c.label
}

/** 1234567.5 -> "1,234,568"; negatives in brackets, as accountants write them */
function plainMoney(v: unknown) {
  if (v === null || v === undefined || v === '') return ''
  const n = Math.round(Number(v))
  const s = Math.abs(n).toLocaleString('en-US')
  return n < 0 ? `(${s})` : s
}

function plainDate(v: unknown) {
  if (!v) return ''
  return new Date(String(v) + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
}

// --- Excel ------------------------------------------------------------------------------------

export async function downloadExcel(report: Report, who: Who) {
  const { default: ExcelJS } = await import('exceljs')
  const brand = brandHex(who)
  const wb = new ExcelJS.Workbook()
  wb.creator = who.user ?? 'SACCO'
  wb.created = new Date()
  const cols = report.columns
  const wide = cols.length > 6
  const ws = wb.addWorksheet(report.title.replace(/[\\/?*[\]:]/g, ' ').slice(0, 31), {
    views: [{ state: 'frozen', ySplit: 6, showGridLines: false }],
    pageSetup: { paperSize: 9, orientation: wide ? 'landscape' : 'portrait', fitToPage: true, fitToWidth: 1, fitToHeight: 0, margins: { left: 0.4, right: 0.4, top: 0.5, bottom: 0.6, header: 0.2, footer: 0.3 } },
    headerFooter: { oddFooter: `&L&8${who.sacco?.name ?? ''} · ${report.title}&R&8Page &P of &N` },
  })
  const last = cols.length

  // Heading block
  const heading = (row: number, text: string, font: Partial<import('exceljs').Font>, height: number) => {
    ws.mergeCells(row, 1, row, last)
    const cell = ws.getCell(row, 1)
    cell.value = text
    cell.font = { name: 'Calibri', ...font }
    cell.alignment = { vertical: 'middle' }
    ws.getRow(row).height = height
  }
  heading(1, who.sacco?.name ?? 'SACCO', { size: 16, bold: true, color: { argb: 'FF' + brand } }, 24)
  heading(2, report.title, { size: 13, bold: true, color: { argb: 'FF151821' } }, 20)
  heading(3, report.subtitle, { size: 10, color: { argb: 'FF57534E' } }, 16)
  heading(4, printedLine(who), { size: 9, italic: true, color: { argb: 'FF78716C' } }, 14)
  ws.getCell(1, 1).border = {}

  // Column header
  const head = ws.getRow(6)
  cols.forEach((c, i) => {
    const cell = head.getCell(i + 1)
    cell.value = columnLabel(c, who)
    cell.font = { name: 'Calibri', bold: true, color: { argb: 'FFFFFFFF' }, size: 10 }
    cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF' + brand } }
    cell.alignment = { vertical: 'middle', horizontal: numeric(c) ? 'right' : 'left', wrapText: true }
    cell.border = { bottom: { style: 'medium', color: { argb: 'FF' + brand } } }
  })
  head.height = 24

  // Body
  let r = 7
  let band = false
  const thin = { style: 'thin' as const, color: { argb: 'FFE7E3DA' } }
  for (const row of report.rows) {
    const x = ws.getRow(r)
    if (isHeading(row)) {
      ws.mergeCells(r, 1, r, last)
      const cell = x.getCell(1)
      cell.value = headingText(report, row).toUpperCase()
      cell.font = { name: 'Calibri', bold: true, size: 10, color: { argb: 'FF44403C' } }
      cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF' + SAFFRON_TINT } }
      x.height = 20
      band = false
      r++
      continue
    }
    cols.forEach((c, i) => {
      const cell = x.getCell(i + 1)
      const v = row[c.key]
      if (v === null || v === undefined || v === '') {
        cell.value = null
      } else if (numeric(c)) {
        cell.value = Number(v)
        cell.numFmt = c.type === 'money' ? '#,##0;[Red](#,##0);"-"' : '#,##0'
      } else if (c.type === 'date') {
        cell.value = new Date(String(v) + 'T00:00:00Z')
        cell.numFmt = 'd mmm yyyy'
      } else {
        cell.value = String(v).replace(/^ {2}/, '    ')
      }
      cell.font = { name: 'Calibri', size: 10, bold: row._kind === 'subtotal' || row._kind === 'total' }
      cell.alignment = { vertical: 'middle', horizontal: numeric(c) ? 'right' : 'left' }
      cell.border = { bottom: thin }
      if (row._kind === 'total') {
        cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF' + toHex(tint(brand)) } }
        cell.border = { top: { style: 'double', color: { argb: 'FF151821' } }, bottom: { style: 'medium', color: { argb: 'FF151821' } } }
        cell.font = { name: 'Calibri', size: 11, bold: true }
      } else if (row._kind === 'subtotal') {
        cell.border = { top: { style: 'thin', color: { argb: 'FF151821' } }, bottom: thin }
      } else if (band) {
        cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF' + BAND } }
      }
    })
    x.height = 18
    band = !band
    r++
  }

  // Totals
  if (report.totals) {
    const x = ws.getRow(r)
    cols.forEach((c, i) => {
      const cell = x.getCell(i + 1)
      if (i === 0) cell.value = 'Total'
      else if (c.key in report.totals!) {
        cell.value = Number(report.totals![c.key])
        cell.numFmt = '#,##0;[Red](#,##0);"-"'
      }
      cell.font = { name: 'Calibri', size: 11, bold: true }
      cell.alignment = { horizontal: numeric(c) ? 'right' : 'left', vertical: 'middle' }
      cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF' + toHex(tint(brand)) } }
      cell.border = { top: { style: 'medium', color: { argb: 'FF' + brand } }, bottom: { style: 'double', color: { argb: 'FF' + brand } } }
    })
    x.height = 22
  }

  // Widths from the content
  cols.forEach((c, i) => {
    const longest = Math.max(
      columnLabel(c, who).length,
      ...report.rows.filter((x) => !isHeading(x)).slice(0, 500).map((x) => (numeric(c) ? plainMoney(x[c.key]).length + 2 : c.type === 'date' ? 12 : String(x[c.key] ?? '').length)),
    )
    ws.getColumn(i + 1).width = Math.min(Math.max(longest + 3, numeric(c) ? 14 : 10), 60)
  })
  ws.pageSetup.printTitlesRow = '6:6'
  if (report.rows.length && !report.rows.some(isHeading)) {
    ws.autoFilter = { from: { row: 6, column: 1 }, to: { row: 6 + report.rows.length, column: last } }
  }

  const buffer = await wb.xlsx.writeBuffer()
  save(new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), fileName(report, 'xlsx'))
}

// --- PDF --------------------------------------------------------------------------------------

export async function downloadPdf(report: Report, who: Who) {
  const [{ jsPDF }, { default: autoTable }] = await Promise.all([import('jspdf'), import('jspdf-autotable')])
  const brand = rgb(brandHex(who))
  const cols = report.columns
  const landscape = cols.length > 5
  const doc = new jsPDF({ orientation: landscape ? 'landscape' : 'portrait', unit: 'mm', format: 'a4' })
  const W = doc.internal.pageSize.getWidth()
  const H = doc.internal.pageSize.getHeight()
  const M = 14
  const name = who.sacco?.name ?? 'SACCO'
  const initials = (who.sacco?.short_name || name).replace(/sacco/i, '').trim().slice(0, 2).toUpperCase() || 'S'

  // Letterhead (first page)
  doc.setFillColor(...brand)
  doc.rect(0, 0, W, 4, 'F')
  doc.setFillColor(238, 169, 15)
  doc.rect(0, 4, W, 1, 'F')
  doc.setFillColor(...brand)
  doc.roundedRect(M, 11, 13, 13, 2.5, 2.5, 'F')
  doc.setTextColor(255, 255, 255)
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(11)
  doc.text(initials, M + 6.5, 19.3, { align: 'center' })
  doc.setTextColor(21, 24, 33)
  doc.setFontSize(14)
  doc.text(name, M + 17, 16.5)
  if (who.sacco?.slogan) {
    doc.setFont('helvetica', 'normal')
    doc.setFontSize(9)
    doc.setTextColor(120, 113, 108)
    doc.text(who.sacco.slogan, M + 17, 21.5)
  }
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(16)
  doc.setTextColor(21, 24, 33)
  doc.text(report.title, M, 35)
  doc.setFont('helvetica', 'normal')
  doc.setFontSize(9.5)
  doc.setTextColor(87, 83, 78)
  doc.text(doc.splitTextToSize(report.subtitle, W - 2 * M), M, 41)
  if (report.balanced !== undefined) {
    const ok = report.balanced
    const label = ok ? 'BALANCED' : 'NOT BALANCED'
    doc.setFont('helvetica', 'bold')
    doc.setFontSize(8)
    const w = doc.getTextWidth(label) + 6
    doc.setFillColor(...((ok ? [220, 252, 231] : [254, 226, 226]) as [number, number, number]))
    doc.roundedRect(W - M - w, 30.5, w, 6, 3, 3, 'F')
    doc.setTextColor(...((ok ? [4, 120, 87] : [185, 28, 28]) as [number, number, number]))
    doc.text(label, W - M - w / 2, 34.6, { align: 'center' })
  }

  const body = report.rows.map((row) => {
    if (isHeading(row)) {
      return [{ content: headingText(report, row).toUpperCase(), colSpan: cols.length, styles: { fontStyle: 'bold' as const, fillColor: [253, 243, 215] as [number, number, number], textColor: [68, 64, 60] as [number, number, number], fontSize: 8 } }]
    }
    return cols.map((c) => {
      const v = row[c.key]
      const text = c.type === 'money' ? plainMoney(v) : c.type === 'date' ? plainDate(v) : c.type === 'number' ? (v === null || v === undefined || v === '' ? '' : Number(v).toLocaleString('en-US')) : String(v ?? '')
      const styles: Record<string, unknown> = {}
      if (row._kind === 'subtotal') styles.fontStyle = 'bold'
      if (row._kind === 'total') {
        styles.fontStyle = 'bold'
        styles.fillColor = tint(toHex(brand))
        styles.fontSize = 10
      }
      if (c.type === 'money' && Number(v) < 0) styles.textColor = [185, 28, 28]
      return { content: text, styles }
    })
  })
  const foot = report.totals
    ? [cols.map((c, i) => ({ content: i === 0 ? 'Total' : c.key in report.totals! ? plainMoney(report.totals![c.key]) : '', styles: { halign: (numeric(c) ? 'right' : 'left') as 'right' | 'left' } }))]
    : undefined

  autoTable(doc, {
    startY: 47,
    margin: { left: M, right: M, top: 16, bottom: 16 },
    head: [cols.map((c) => ({ content: columnLabel(c, who), styles: { halign: (numeric(c) ? 'right' : 'left') as 'right' | 'left' } }))],
    body,
    foot,
    showFoot: 'lastPage',
    theme: 'plain',
    styles: { font: 'helvetica', fontSize: 8.5, cellPadding: { top: 1.8, bottom: 1.8, left: 2, right: 2 }, textColor: [21, 24, 33], lineColor: [231, 227, 218], lineWidth: { bottom: 0.15 } as never, overflow: 'linebreak' },
    headStyles: { fillColor: brand, textColor: [255, 255, 255], fontStyle: 'bold', fontSize: 8.5 },
    footStyles: { fillColor: tint(toHex(brand)), textColor: [21, 24, 33], fontStyle: 'bold', fontSize: 9.5 },
    alternateRowStyles: { fillColor: [250, 249, 246] },
    columnStyles: Object.fromEntries(cols.map((c, i) => [i, { halign: numeric(c) ? 'right' : 'left', cellWidth: numeric(c) ? 'wrap' : c.type === 'date' ? 22 : 'auto' }])),
    didDrawPage: () => {
      // A slim band and the SACCO's name on every page after the first
      const page = doc.getNumberOfPages()
      if (page > 1) {
        doc.setFillColor(...brand)
        doc.rect(0, 0, W, 2.5, 'F')
        doc.setFont('helvetica', 'bold')
        doc.setFontSize(8)
        doc.setTextColor(120, 113, 108)
        doc.text(`${name} · ${report.title}`, M, 10)
      }
    },
  })

  // Footer on every page
  const pages = doc.getNumberOfPages()
  for (let p = 1; p <= pages; p++) {
    doc.setPage(p)
    doc.setDrawColor(231, 227, 218)
    doc.setLineWidth(0.2)
    doc.line(M, H - 11, W - M, H - 11)
    doc.setFont('helvetica', 'normal')
    doc.setFontSize(7.5)
    doc.setTextColor(120, 113, 108)
    doc.text(printedLine(who), M, H - 7)
    doc.text(`Page ${p} of ${pages}`, W - M, H - 7, { align: 'right' })
  }
  doc.save(fileName(report, 'pdf'))
}

function save(blob: Blob, name: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 2000)
}
