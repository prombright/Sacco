import type { Report, ReportColumn, ReportRow } from '@/reports'
import type { Sacco } from '@/types'
import { drawFooter, drawLetterhead, drawMark, loadLogo } from '@/documents'

/**
 * Report downloads that look like the SACCO's own documents: Excel workbooks with the SACCO's
 * heading, a coloured header row, banded rows, totals and print settings; and PDFs with a
 * letterhead, the same tables and page numbers. Several reports can go in one file (a sheet or a
 * section each), with a cover page - the annual report, a member's statement. Both libraries
 * load only when someone downloads.
 */

export interface Who {
  sacco: Sacco | null
  user: string | null
}

/** The front of a multi-report document */
export interface Cover {
  title: string
  subtitle: string
  /** Big figures on the cover: [label, value] */
  highlights?: [string, string][]
  /** A line under the title, e.g. "Prepared for the Annual General Meeting" */
  note?: string
  /** Month-by-month charts on a page after the cover (one series each, bars) */
  charts?: PdfChart[]
}

/** One series over months for a PDF: a level (savings at each month end) or an amount per month */
export interface PdfChart {
  title: string
  /** A line under the title, e.g. "UGX, at each month end" */
  note?: string
  points: { month: string; value: number }[]
  /** How the value is written on the axis and over the last bar */
  format: (v: number) => string
}

const numeric = (c: ReportColumn) => c.type === 'money' || c.type === 'number'
const isHeading = (r: ReportRow) => r._kind === 'heading'
const headingText = (report: Report, r: ReportRow) => String(report.columns.map((c) => r[c.key]).find((v) => v !== null && v !== undefined && v !== '') ?? '')
const slug = (s: string) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
const fileName = (title: string, ext: string) => `${slug(title)}-${new Date().toLocaleDateString('en-CA')}.${ext}`
const printedLine = (who: Who) =>
  `Printed ${new Date().toLocaleString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}${who.user ? ` by ${who.user}` : ''}`

type RGB = [number, number, number]
/** The SACCO's colour as hex without '#', falling back to SMACON blue */
const brandHex = (who: Who) => (who.sacco?.brand_color ?? '#2b35af').replace('#', '').toUpperCase()
const rgb = (hex: string): RGB => [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16)) as RGB
// A light tint of the brand colour for totals rows
const tint = (hex: string, amount = 0.88) => rgb(hex).map((v) => Math.round(v + (255 - v) * amount)) as RGB
const toHex = (c: number[]) => c.map((v) => v.toString(16).padStart(2, '0')).join('').toUpperCase()
const SAFFRON: RGB = [238, 169, 15]
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

export function downloadExcel(report: Report, who: Who) {
  return downloadWorkbook([report], who, report.title)
}

/** One workbook, one sheet per report */
export async function downloadWorkbook(reports: Report[], who: Who, title: string) {
  const { default: ExcelJS } = await import('exceljs')
  const wb = new ExcelJS.Workbook()
  wb.creator = who.user ?? 'SACCO'
  wb.created = new Date()
  const used = new Set<string>()
  for (const report of reports) {
    let name = report.title.replace(/[\\/?*[\]:]/g, ' ').slice(0, 31)
    while (used.has(name)) name = name.slice(0, 28) + ' ' + used.size
    used.add(name)
    addSheet(wb, name, report, who)
  }
  const buffer = await wb.xlsx.writeBuffer()
  save(new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), fileName(title, 'xlsx'))
}

function addSheet(wb: import('exceljs').Workbook, sheetName: string, report: Report, who: Who) {
  const brand = brandHex(who)
  const cols = report.columns
  const wide = cols.length > 6
  const ws = wb.addWorksheet(sheetName, {
    views: [{ state: 'frozen', ySplit: 6, showGridLines: false }],
    pageSetup: { paperSize: 9, orientation: wide ? 'landscape' : 'portrait', fitToPage: true, fitToWidth: 1, fitToHeight: 0, margins: { left: 0.4, right: 0.4, top: 0.5, bottom: 0.6, header: 0.2, footer: 0.3 } },
    headerFooter: { oddFooter: `&L&8${who.sacco?.name ?? ''} · ${report.title}&R&8Page &P of &N` },
    properties: { tabColor: { argb: 'FF' + brand } },
  })
  const last = Math.max(cols.length, 2)

  // Heading block
  const heading = (row: number, text: string, font: Partial<import('exceljs').Font>, height: number) => {
    ws.mergeCells(row, 1, row, last)
    const cell = ws.getCell(row, 1)
    cell.value = text
    cell.font = { name: 'Calibri', ...font }
    cell.alignment = { vertical: 'middle' }
    ws.getRow(row).height = height
  }
  heading(1, who.sacco?.name ?? 'SACCO', { size: 16, bold: true, color: { argb: 'FF' + brand } }, 26)
  heading(2, report.title, { size: 13, bold: true, color: { argb: 'FF151821' } }, 20)
  heading(3, report.subtitle, { size: 10, color: { argb: 'FF57534E' } }, 16)
  heading(4, printedLine(who), { size: 9, italic: true, color: { argb: 'FF78716C' } }, 14)
  // A saffron rule under the heading, like the letterhead
  for (let c = 1; c <= last; c++) ws.getCell(5, c).border = { top: { style: 'medium', color: { argb: 'FFEEA90F' } } }
  ws.getRow(5).height = 6

  // Column header
  const head = ws.getRow(6)
  cols.forEach((c, i) => {
    const cell = head.getCell(i + 1)
    cell.value = columnLabel(c, who)
    cell.font = { name: 'Calibri', bold: true, color: { argb: 'FFFFFFFF' }, size: 10 }
    cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF' + brand } }
    cell.alignment = { vertical: 'middle', horizontal: numeric(c) ? 'right' : 'left', wrapText: true }
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
  if (!report.rows.length) {
    ws.mergeCells(r, 1, r, last)
    ws.getCell(r, 1).value = 'Nothing to show for this period.'
    ws.getCell(r, 1).font = { name: 'Calibri', italic: true, color: { argb: 'FF78716C' } }
    r++
  }

  // Totals
  if (report.totals && report.rows.length) {
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
    r++
  }

  // Lines for the reviewer to sign
  if (report.signoff?.length) {
    r += 2
    report.signoff.forEach((label, i) => {
      const cell = ws.getCell(r, 1 + i * 2)
      cell.value = `${label}: ____________________`
      cell.font = { name: 'Calibri', size: 10, color: { argb: 'FF57534E' } }
    })
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
    ws.autoFilter = { from: { row: 6, column: 1 }, to: { row: 6 + report.rows.length, column: cols.length } }
  }
}

// --- PDF --------------------------------------------------------------------------------------

export function downloadPdf(report: Report, who: Who) {
  return downloadPdfBook([report], who, report.title)
}

/** One PDF: an optional cover page, then each report (the first on the letterhead page) */
export async function downloadPdfBook(reports: Report[], who: Who, title: string, cover?: Cover) {
  const [{ jsPDF }, { default: autoTable }] = await Promise.all([import('jspdf'), import('jspdf-autotable')])
  const brand = rgb(brandHex(who))
  const landscapeAny = !cover && reports.length === 1 && reports[0].columns.length > 5
  const doc = new jsPDF({ orientation: landscapeAny ? 'landscape' : 'portrait', unit: 'mm', format: 'a4' })
  const M = 14
  const name = who.sacco?.name ?? 'SACCO'
  const W = () => doc.internal.pageSize.getWidth()
  const H = () => doc.internal.pageSize.getHeight()

  const logo = await loadLogo(who)

  /** The letterhead: band, mark, name, slogan. Returns where the content starts. */
  const letterhead = () => drawLetterhead(doc, who, logo, M)

  /** A report's title block (with the balanced badge for statements). Returns where its table starts. */
  const titleBlock = (report: Report, y: number) => {
    doc.setFont('helvetica', 'bold')
    doc.setFontSize(16)
    doc.setTextColor(21, 24, 33)
    doc.text(report.title, M, y)
    doc.setFont('helvetica', 'normal')
    doc.setFontSize(9.5)
    doc.setTextColor(87, 83, 78)
    const sub = doc.splitTextToSize(report.subtitle.replace(/ · (NOT )?BALANCED.*$| · balanced$/, ''), W() - 2 * M - 40)
    doc.text(sub, M, y + 6)
    if (report.balanced !== undefined) {
      const ok = report.balanced
      const label = ok ? 'BALANCED' : 'NOT BALANCED'
      doc.setFont('helvetica', 'bold')
      doc.setFontSize(8)
      const w = doc.getTextWidth(label) + 6
      doc.setFillColor(...((ok ? [220, 252, 231] : [254, 226, 226]) as RGB))
      doc.roundedRect(W() - M - w, y - 4.5, w, 6, 3, 3, 'F')
      doc.setTextColor(...((ok ? [4, 120, 87] : [185, 28, 28]) as RGB))
      doc.text(label, W() - M - w / 2, y - 0.4, { align: 'center' })
    }
    return y + 6 + sub.length * 4.5 + 3
  }

  // Cover page
  if (cover) {
    const w = W()
    const h = H()
    doc.setFillColor(...brand)
    doc.rect(0, 0, w, h * 0.58, 'F')
    doc.setFillColor(...SAFFRON)
    doc.rect(0, h * 0.58, w, 2.5, 'F')
    // Rings, as on the app
    doc.setDrawColor(...SAFFRON)
    doc.setLineWidth(1.2)
    doc.circle(w - 18, 22, 46, 'S')
    doc.setDrawColor(255, 255, 255)
    doc.setLineWidth(0.3)
    doc.circle(w - 30, h * 0.5, 30, 'S')
    drawMark(doc, who, logo, M + 4, 32, 18, true)
    doc.setTextColor(255, 255, 255)
    doc.setFontSize(13)
    doc.text(name.toUpperCase(), M + 2, 68)
    if (who.sacco?.slogan) {
      doc.setFont('helvetica', 'normal')
      doc.setFontSize(10)
      doc.text(who.sacco.slogan, M + 2, 74)
    }
    doc.setFont('helvetica', 'bold')
    doc.setFontSize(30)
    doc.text(doc.splitTextToSize(cover.title, w - 2 * M - 10), M + 2, 115)
    doc.setFont('helvetica', 'normal')
    doc.setFontSize(12)
    doc.text(cover.subtitle, M + 2, 128)
    if (cover.note) {
      doc.setFontSize(10)
      doc.setTextColor(253, 243, 215)
      doc.text(cover.note, M + 2, 136)
    }
    // Highlights in a grid below the band
    const hl = cover.highlights ?? []
    const colW = (w - 2 * M) / 3
    hl.forEach(([label, value], i) => {
      const x = M + (i % 3) * colW
      const y = h * 0.58 + 22 + Math.floor(i / 3) * 30
      doc.setFillColor(...SAFFRON)
      doc.rect(x, y - 9, 10, 1.2, 'F')
      doc.setFont('helvetica', 'bold')
      doc.setFontSize(15)
      doc.setTextColor(21, 24, 33)
      doc.text(value, x, y)
      doc.setFont('helvetica', 'normal')
      doc.setFontSize(8.5)
      doc.setTextColor(120, 113, 108)
      doc.text(doc.splitTextToSize(label, colW - 6), x, y + 6)
    })
    // Contents
    doc.setFont('helvetica', 'bold')
    doc.setFontSize(9)
    doc.setTextColor(21, 24, 33)
    const cy = h - 18 - reports.length * 5
    doc.text('Contents', M, cy - 4)
    doc.setFont('helvetica', 'normal')
    doc.setTextColor(87, 83, 78)
    reports.forEach((rp, i) => doc.text(`${i + 1}.  ${rp.title}`, M, cy + 2 + i * 5))
  }

  // The trends page: up to four charts, two by two, one series each, on one axis from zero
  if (cover?.charts?.length) {
    doc.addPage('a4', 'portrait')
    doc.setFillColor(...brand)
    doc.rect(0, 0, W(), 2.5, 'F')
    doc.setFont('helvetica', 'bold')
    doc.setFontSize(8)
    doc.setTextColor(120, 113, 108)
    doc.text(name, M, 10)
    doc.setFontSize(16)
    doc.setTextColor(21, 24, 33)
    doc.text('Month by month', M, 22)
    doc.setFont('helvetica', 'normal')
    doc.setFontSize(9.5)
    doc.setTextColor(87, 83, 78)
    doc.text('The last twelve months, or since the books began', M, 28)
    const gap = 10
    const cw = (W() - 2 * M - gap) / 2
    const ch = 92
    cover.charts.slice(0, 4).forEach((c, i) => {
      const x0 = M + (i % 2) * (cw + gap)
      const y0 = 38 + Math.floor(i / 2) * (ch + 14)
      doc.setFont('helvetica', 'bold')
      doc.setFontSize(10.5)
      doc.setTextColor(21, 24, 33)
      doc.text(c.title, x0, y0 + 4)
      if (c.note) {
        doc.setFont('helvetica', 'normal')
        doc.setFontSize(8)
        doc.setTextColor(120, 113, 108)
        doc.text(c.note, x0, y0 + 9)
      }
      const vals = c.points.map((p) => p.value)
      const hi = Math.max(0, ...vals)
      const lo = Math.min(0, ...vals)
      // Round gridlines: a step near a third of the range
      const span = hi - lo || 1
      const mag = 10 ** Math.floor(Math.log10(span / 3))
      const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= span / 3)!
      const top = Math.ceil(hi / step) * step || step
      const bottom = Math.floor(lo / step) * step
      const axisW = 16
      const px = x0 + axisW
      const pw = cw - axisW
      const py = y0 + 18
      const ph = ch - 30
      const yOf = (v: number) => py + ph - ((v - bottom) / (top - bottom)) * ph
      doc.setFont('helvetica', 'normal')
      doc.setFontSize(7)
      for (let t = bottom; t <= top + step / 2; t += step) {
        const y = yOf(t)
        doc.setDrawColor(...((t === 0 ? [168, 162, 158] : [231, 227, 218]) as RGB))
        doc.setLineWidth(t === 0 ? 0.3 : 0.15)
        doc.line(px, y, px + pw, y)
        doc.setTextColor(120, 113, 108)
        doc.text(c.format(t), px - 2, y + 1, { align: 'right' })
      }
      const n = c.points.length || 1
      const slot = pw / n
      const bw = Math.min(9, slot * 0.6)
      c.points.forEach((p, k) => {
        const cx = px + slot * k + slot / 2
        const y1 = yOf(Math.max(0, p.value))
        const y2 = yOf(Math.min(0, p.value))
        doc.setFillColor(...((p.value < 0 ? [185, 28, 28] : brand) as RGB))
        if (y2 - y1 > 0.2) doc.rect(cx - bw / 2, y1, bw, y2 - y1, 'F')
        doc.setFont('helvetica', 'normal')
        doc.setFontSize(6.5)
        doc.setTextColor(120, 113, 108)
        const d = new Date(p.month + '-01T00:00:00')
        doc.text(d.toLocaleDateString('en-GB', { month: 'short' }) + (d.getMonth() === 0 || k === 0 ? ` ${String(d.getFullYear()).slice(2)}` : ''), cx, py + ph + 5, { align: 'center' })
      })
      // The latest figure, written over its bar
      const last = c.points[c.points.length - 1]
      if (last) {
        doc.setFont('helvetica', 'bold')
        doc.setFontSize(8)
        doc.setTextColor(21, 24, 33)
        doc.text(c.format(last.value), px + pw, yOf(Math.max(0, last.value)) - 2, { align: 'right' })
      }
    })
  }

  reports.forEach((report, index) => {
    const cols = report.columns
    let startY: number
    if (cover || index > 0) {
      doc.addPage('a4', cols.length > 6 ? 'landscape' : 'portrait')
      doc.setFillColor(...brand)
      doc.rect(0, 0, W(), 2.5, 'F')
      doc.setFont('helvetica', 'bold')
      doc.setFontSize(8)
      doc.setTextColor(120, 113, 108)
      doc.text(name, M, 10)
      startY = titleBlock(report, 22)
    } else {
      startY = titleBlock(report, letterhead())
    }

    const body: { content: string; colSpan?: number; styles: Record<string, unknown> }[][] = report.rows.map((row) => {
      if (isHeading(row)) {
        return [{ content: headingText(report, row).toUpperCase(), colSpan: cols.length, styles: { fontStyle: 'bold' as const, fillColor: [253, 243, 215] as RGB, textColor: [68, 64, 60] as RGB, fontSize: 8 } }]
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
    if (!body.length) body.push([{ content: 'Nothing to show for this period.', colSpan: cols.length, styles: { fontStyle: 'italic' as const, textColor: [120, 113, 108] as RGB, fontSize: 8.5 } }])
    const foot = report.totals && report.rows.length
      ? [cols.map((c, i) => ({ content: i === 0 ? 'Total' : c.key in report.totals! ? plainMoney(report.totals![c.key]) : '', styles: { halign: (numeric(c) ? 'right' : 'left') as 'right' | 'left' } }))]
      : undefined

    autoTable(doc, {
      startY,
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
      didDrawPage: (d) => {
        // Continuation pages of a long table: the slim band and the SACCO's name again
        if (d.pageNumber > 1 && d.cursor && d.settings.startY !== d.cursor.y) {
          doc.setFillColor(...brand)
          doc.rect(0, 0, W(), 2.5, 'F')
          doc.setFont('helvetica', 'bold')
          doc.setFontSize(8)
          doc.setTextColor(120, 113, 108)
          doc.text(`${name} · ${report.title} (continued)`, M, 10)
        }
      },
    })
    // Lines for the reviewer to sign, after the table
    if (report.signoff?.length) {
      let y = (doc as unknown as { lastAutoTable: { finalY: number } }).lastAutoTable.finalY + 18
      if (y > H() - 40) {
        doc.addPage()
        y = 30
      }
      const gap = 10
      const w = (W() - 2 * M - gap * (report.signoff.length - 1)) / report.signoff.length
      doc.setDrawColor(120, 113, 108)
      doc.setLineWidth(0.3)
      doc.setFont('helvetica', 'normal')
      doc.setFontSize(8)
      doc.setTextColor(120, 113, 108)
      report.signoff.forEach((label, i) => {
        const x = M + i * (w + gap)
        doc.line(x, y, x + w, y)
        doc.text(label, x, y + 4.5)
      })
    }
  })

  // Footer on every page but the cover
  const pages = doc.getNumberOfPages()
  for (let p = cover ? 2 : 1; p <= pages; p++) {
    doc.setPage(p)
    drawFooter(doc, who, `${name} · ${title}`, M, `Page ${p} of ${pages}`)
  }
  doc.save(fileName(title, 'pdf'))
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
