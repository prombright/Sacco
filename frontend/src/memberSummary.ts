import type { ReportRow } from '@/reports'
import { loadLogo } from '@/documents'
import type { Sacco } from '@/types'

/** One member's line from the Members summary, as labelled figures (for the View window and the PDF) */
export function summaryFacts(row: ReportRow, currency = 'UGX') {
  const money = (v: unknown) => (v === null || v === undefined || v === '' ? '-' : `${currency} ${Math.round(Number(v)).toLocaleString('en-US')}`)
  const date = (v: unknown) => (v ? new Date(String(v)).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '-')
  return {
    member: [
      { label: 'Account no.', value: String(row.account_no ?? '-') },
      { label: 'Employee no.', value: String(row.employee_no || '-') },
      { label: 'Status', value: String(row.status ?? '-') },
      { label: 'Member since', value: date(row.member_since) },
    ],
    money: [
      { label: 'Savings', value: money(row.savings), lead: true },
      { label: 'Interest per month', value: money(row.savings_interest) },
      { label: 'Interest owed to them', value: money(row.interest_held) },
      { label: 'Share capital', value: money(row.shares), lead: true },
      { label: 'Shares', value: row.share_count === null || row.share_count === undefined ? '-' : String(row.share_count) },
      { label: 'Loan balance', value: money(row.loan), lead: true },
    ],
  }
}

/** The member's summary as a one-page A4 PDF with the SACCO's heading and logo */
export async function downloadMemberSummary(row: ReportRow, sacco: Sacco | null, asOf: string) {
  const { jsPDF } = await import('jspdf')
  const logo = await loadLogo({ sacco, user: null })
  const doc = new jsPDF({ unit: 'mm', format: 'a4' })
  const W = doc.internal.pageSize.getWidth()
  const H = doc.internal.pageSize.getHeight()
  const M = 14
  const violet: [number, number, number] = [82, 51, 230]
  const ink: [number, number, number] = [22, 19, 58]
  const muted: [number, number, number] = [107, 104, 144]
  const tint: [number, number, number] = [246, 244, 255]
  const line: [number, number, number] = [226, 217, 255]
  const facts = summaryFacts(row, sacco?.currency ?? 'UGX')

  // heading band: logo, name, slogan
  doc.setFillColor(...violet)
  doc.roundedRect(M, 12, W - 2 * M, 28, 3, 3, 'F')
  let x = M + 6
  if (logo) {
    const s = 16
    const w = s * Math.min(logo.ratio, 1.6)
    doc.setFillColor(255, 255, 255)
    doc.roundedRect(x - 1.5, 18.5, w + 3, s + 3, 2.5, 2.5, 'F')
    doc.addImage(logo.data, x, 20, w, s)
    x += w + 7
  }
  doc.setTextColor(255, 255, 255)
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(15)
  doc.text(sacco?.name ?? 'SACCO', x, 25)
  if (sacco?.slogan) {
    doc.setFont('helvetica', 'normal')
    doc.setFontSize(9)
    doc.text(sacco.slogan, x, 31)
  }
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(10)
  doc.text('MEMBER SUMMARY', W - M - 6, 25, { align: 'right' })
  doc.setFont('helvetica', 'normal')
  doc.setFontSize(8.5)
  doc.text(asOf, W - M - 6, 31, { align: 'right' })

  // the member
  doc.setTextColor(...ink)
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(18)
  doc.text(String(row.full_name ?? ''), M, 54)
  let y = 62
  const colW = (W - 2 * M - 6) / 2
  facts.member.forEach((f, i) => {
    const cx = M + (i % 2) * (colW + 6)
    const cy = y + Math.floor(i / 2) * 16
    doc.setFillColor(...tint)
    doc.setDrawColor(...line)
    doc.roundedRect(cx, cy, colW, 13, 2, 2, 'FD')
    doc.setFont('helvetica', 'normal')
    doc.setFontSize(8)
    doc.setTextColor(...muted)
    doc.text(f.label.toUpperCase(), cx + 4, cy + 5)
    doc.setFont('helvetica', 'bold')
    doc.setFontSize(10.5)
    doc.setTextColor(...ink)
    doc.text(f.value, cx + 4, cy + 10.5)
  })

  // the money, one ruled line each
  y += 38
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(11)
  doc.setTextColor(...violet)
  doc.text('Savings, shares and loans', M, y)
  y += 4
  facts.money.forEach((f) => {
    if (f.lead) {
      doc.setFillColor(...tint)
      doc.rect(M, y, W - 2 * M, 11, 'F')
    }
    doc.setDrawColor(...line)
    doc.line(M, y + 11, W - M, y + 11)
    doc.setFont('helvetica', f.lead ? 'bold' : 'normal')
    doc.setFontSize(10)
    doc.setTextColor(...ink)
    doc.text(f.label, M + 4, y + 7.2)
    doc.text(f.value, W - M - 4, y + 7.2, { align: 'right' })
    y += 11
  })

  // footer
  doc.setFont('helvetica', 'normal')
  doc.setFontSize(8)
  doc.setTextColor(...muted)
  doc.text(`Printed ${new Date().toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}`, M, H - 12)
  doc.text(sacco?.name ?? '', W - M, H - 12, { align: 'right' })

  const safe = String(row.full_name ?? 'member').toLowerCase().replace(/[^a-z0-9]+/g, '-')
  doc.save(`member-summary-${row.account_no ?? ''}-${safe}.pdf`)
}
