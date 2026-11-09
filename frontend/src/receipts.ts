import { api } from '@/api'
import type { Who } from '@/exporters'
import { drawMark, loadLogo } from '@/documents'

export interface Receipt {
  no: string
  user_id: number
  date: string
  direction: 'in' | 'out'
  title: string
  account: string
  amount: string
  reference: string | null
  details: string | null
  balance_after: string | null
  recorded_by: string | null
  reversed: boolean
  lines: { label: string; amount: string }[]
}
export interface ReceiptMember {
  full_name: string
  account_no: string | null
  employee_no: string | null
}

const ONES = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen']
const TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety']

function under1000(n: number): string {
  const parts: string[] = []
  if (n >= 100) parts.push(`${ONES[Math.floor(n / 100)]} hundred`)
  const rest = n % 100
  if (rest >= 20) parts.push(TENS[Math.floor(rest / 10)] + (rest % 10 ? `-${ONES[rest % 10]}` : ''))
  else if (rest > 0) parts.push(ONES[rest])
  return parts.join(' and ')
}

/** 1250000 -> "One million, two hundred and fifty thousand" */
export function amountInWords(amount: number): string {
  let n = Math.round(amount)
  if (n === 0) return 'Zero'
  const groups: string[] = []
  for (const [size, name] of [[1e9, 'billion'], [1e6, 'million'], [1e3, 'thousand']] as const) {
    if (n >= size) {
      groups.push(`${under1000(Math.floor(n / size))} ${name}`)
      n %= size
    }
  }
  if (n > 0) groups.push(under1000(n))
  const text = groups.join(', ')
  return text.charAt(0).toUpperCase() + text.slice(1)
}

const plain = (v: string | number) => Math.round(Number(v)).toLocaleString('en-US')

/** One receipt on an A5 page with the SACCO's letterhead */
export async function downloadReceipt(r: Receipt, member: ReceiptMember, who: Who) {
  const { jsPDF } = await import('jspdf')
  const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a5' })
  const W = doc.internal.pageSize.getWidth()
  const H = doc.internal.pageSize.getHeight()
  const M = 11
  const hex = (who.sacco?.brand_color ?? '#2b35af').replace('#', '')
  const brand = [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16)) as [number, number, number]
  const name = who.sacco?.name ?? 'SACCO'
  const currency = who.sacco?.currency ?? 'UGX'
  const incoming = r.direction === 'in'

  // Letterhead
  doc.setFillColor(...brand)
  doc.rect(0, 0, W, 3.5, 'F')
  doc.setFillColor(238, 169, 15)
  doc.rect(0, 3.5, W, 0.9, 'F')
  drawMark(doc, who, await loadLogo(who), M, 9, 11)
  doc.setTextColor(21, 24, 33)
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(12)
  doc.text(name, M + 14, 13.5)
  if (who.sacco?.slogan) {
    doc.setFont('helvetica', 'normal')
    doc.setFontSize(8)
    doc.setTextColor(120, 113, 108)
    doc.text(who.sacco.slogan, M + 14, 18)
  }

  // Title and number
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(15)
  doc.setTextColor(21, 24, 33)
  doc.text(incoming ? 'Receipt' : 'Payment advice', M, 32)
  doc.setFontSize(9.5)
  doc.text(r.no, W - M, 30, { align: 'right' })
  doc.setFont('helvetica', 'normal')
  doc.setFontSize(8.5)
  doc.setTextColor(87, 83, 78)
  doc.text(new Date(r.date + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }), W - M, 34.5, { align: 'right' })

  // The amount
  const tint = brand.map((v) => Math.round(v + (255 - v) * 0.9)) as [number, number, number]
  doc.setFillColor(...tint)
  doc.roundedRect(M, 39, W - 2 * M, 26, 2.5, 2.5, 'F')
  doc.setFontSize(8.5)
  doc.setTextColor(87, 83, 78)
  doc.text(incoming ? 'Amount received' : 'Amount paid out', M + 5, 46)
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(19)
  doc.setTextColor(21, 24, 33)
  doc.text(`${currency} ${plain(r.amount)}`, M + 5, 54.5)
  doc.setFont('helvetica', 'normal')
  doc.setFontSize(8)
  doc.setTextColor(87, 83, 78)
  const unit = currency === 'UGX' ? 'Uganda shillings' : currency
  doc.text(doc.splitTextToSize(`${amountInWords(Number(r.amount))} ${unit} only`, W - 2 * M - 10), M + 5, 60)

  // Details
  let y = 74
  const row = (label: string, value: string, bold = false) => {
    doc.setFont('helvetica', 'normal')
    doc.setFontSize(8.5)
    doc.setTextColor(120, 113, 108)
    doc.text(label, M, y)
    doc.setFont('helvetica', bold ? 'bold' : 'normal')
    doc.setTextColor(21, 24, 33)
    const text = doc.splitTextToSize(value, W - 2 * M - 40)
    doc.text(text, W - M, y, { align: 'right' })
    y += 4.4 * text.length
    doc.setDrawColor(231, 227, 218)
    doc.setLineWidth(0.15)
    doc.line(M, y - 2, W - M, y - 2)
    y += 2.2
  }
  row(incoming ? 'Received from' : 'Paid to', member.full_name, true)
  if (member.account_no) row('Account no.', member.account_no)
  row('For', `${r.title} · ${r.account}`)
  if (r.reference) row('Reference', r.reference)
  if (r.details) row('Details', r.details)
  for (const l of r.lines) row(l.label, `${currency} ${plain(l.amount)}`)
  if (r.balance_after !== null) row(`${r.account} balance after`, `${currency} ${plain(r.balance_after)}`, true)
  if (r.recorded_by) row('Recorded by', r.recorded_by)

  if (r.reversed) {
    doc.setTextColor(220, 80, 80)
    doc.setFont('helvetica', 'bold')
    doc.setFontSize(46)
    doc.text('REVERSED', W / 2, H / 2 + 8, { align: 'center', angle: 28 })
  }

  // Footer
  doc.setDrawColor(231, 227, 218)
  doc.line(M, H - 13, W - M, H - 13)
  doc.setFont('helvetica', 'normal')
  doc.setFontSize(7)
  doc.setTextColor(120, 113, 108)
  doc.text('This receipt is produced by the system and is valid without a signature.', M, H - 9.5)
  doc.text(`Printed ${new Date().toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}${who.user ? ` by ${who.user}` : ''} · ${name}`, M, H - 6)
  doc.save(`receipt-${r.no}.pdf`)
}

/** Fetches a receipt by number and downloads it */
export async function downloadReceiptNo(no: string, who: Who) {
  const res = await api.get<{ receipt: Receipt; member: ReceiptMember }>(`/receipts/${no}`)
  await downloadReceipt(res.receipt, res.member, who)
}
