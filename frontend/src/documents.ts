import type { jsPDF as JsPDF } from 'jspdf'
import type { Who } from '@/exporters'
import { amountInWords } from '@/receipts'

/**
 * The SACCO's own documents as PDFs: the member card with its QR code, the loan approval letter
 * and the share certificate. Every PDF (these, reports, receipts) puts the SACCO's logo on its
 * letterhead when one has been uploaded, else its initials.
 */

type RGB = [number, number, number]
const SAFFRON: RGB = [238, 169, 15]
const INK: RGB = [21, 24, 33]
const MUTED: RGB = [120, 113, 108]

export const brandRgb = (who: Who): RGB => {
  const hex = (who.sacco?.brand_color ?? '#2b35af').replace('#', '')
  return [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16)) as RGB
}
const initialsOf = (who: Who) => ((who.sacco?.short_name || who.sacco?.name || 'SACCO').replace(/sacco/i, '').trim().slice(0, 2).toUpperCase() || 'S')
const money = (v: string | number) => `${Math.round(Number(v)).toLocaleString('en-US')}`
const longDate = (d: Date | string) => new Date(typeof d === 'string' ? d + 'T00:00:00' : d).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })

// --- The logo ---------------------------------------------------------------------------------

let logoCache: { url: string; data: string | null; ratio: number } | null = null

/** The uploaded logo as a data URL (and its width/height), or null when there is none */
export async function loadLogo(who: Who): Promise<{ data: string; ratio: number } | null> {
  const url = who.sacco?.logo_url
  if (!url) return null
  if (logoCache?.url === url) return logoCache.data ? { data: logoCache.data, ratio: logoCache.ratio } : null
  try {
    const full = /^(https?:)?\/\//.test(url) || url.startsWith('/') ? url : import.meta.env.BASE_URL + url
    const blob = await (await fetch(full, { credentials: 'same-origin' })).blob()
    const data = await new Promise<string>((resolve, reject) => {
      const r = new FileReader()
      r.onload = () => resolve(String(r.result))
      r.onerror = reject
      r.readAsDataURL(blob)
    })
    const ratio = await new Promise<number>((resolve) => {
      const img = new Image()
      img.onload = () => resolve(img.naturalWidth / (img.naturalHeight || 1))
      img.onerror = () => resolve(1)
      img.src = data
    })
    logoCache = { url, data, ratio }
    return { data, ratio }
  } catch {
    logoCache = { url, data: null, ratio: 1 }
    return null
  }
}

/** The SACCO's mark in a square of `size`: the logo, or initials on its colour */
export function drawMark(doc: JsPDF, who: Who, logo: { data: string; ratio: number } | null, x: number, y: number, size: number, onDark = false) {
  if (logo) {
    const w = logo.ratio >= 1 ? size : size * logo.ratio
    const h = logo.ratio >= 1 ? size / logo.ratio : size
    if (onDark) {
      doc.setFillColor(255, 255, 255)
      doc.roundedRect(x - size * 0.08, y - size * 0.08, size * 1.16, size * 1.16, size * 0.2, size * 0.2, 'F')
    }
    doc.addImage(logo.data, x + (size - w) / 2, y + (size - h) / 2, w, h)
    return
  }
  const brand = brandRgb(who)
  if (onDark) {
    doc.setFillColor(255, 255, 255)
  } else {
    doc.setFillColor(...brand)
  }
  doc.roundedRect(x, y, size, size, size * 0.19, size * 0.19, 'F')
  doc.setTextColor(...(onDark ? brand : ([255, 255, 255] as RGB)))
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(size * 0.85)
  doc.text(initialsOf(who), x + size / 2, y + size * 0.64, { align: 'center' })
}

/** The letterhead used on letters: band, mark, name, slogan. Returns where the content starts. */
async function letterhead(doc: JsPDF, who: Who) {
  const W = doc.internal.pageSize.getWidth()
  const brand = brandRgb(who)
  doc.setFillColor(...brand)
  doc.rect(0, 0, W, 4, 'F')
  doc.setFillColor(...SAFFRON)
  doc.rect(0, 4, W, 1, 'F')
  drawMark(doc, who, await loadLogo(who), 18, 12, 15)
  doc.setTextColor(...INK)
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(15)
  doc.text(who.sacco?.name ?? 'SACCO', 37, 18.5)
  doc.setFont('helvetica', 'normal')
  doc.setFontSize(9)
  doc.setTextColor(...MUTED)
  if (who.sacco?.slogan) doc.text(who.sacco.slogan, 37, 23.5)
  doc.setDrawColor(231, 227, 218)
  doc.line(18, 32, W - 18, 32)
  return 42
}

function footer(doc: JsPDF, who: Who, note: string) {
  const W = doc.internal.pageSize.getWidth()
  const H = doc.internal.pageSize.getHeight()
  doc.setDrawColor(231, 227, 218)
  doc.line(18, H - 14, W - 18, H - 14)
  doc.setFont('helvetica', 'normal')
  doc.setFontSize(7.5)
  doc.setTextColor(...MUTED)
  doc.text(note, 18, H - 9.5)
  doc.text(`Printed ${longDate(new Date())}${who.user ? ` by ${who.user}` : ''}`, W - 18, H - 9.5, { align: 'right' })
}

async function newDoc(orientation: 'portrait' | 'landscape' = 'portrait', format: string | number[] = 'a4') {
  const { jsPDF } = await import('jspdf')
  return new jsPDF({ orientation, unit: 'mm', format })
}

// --- The member card --------------------------------------------------------------------------

export interface CardMember {
  full_name: string
  account_no: string | null
  employee_no?: string | null
  joined_on?: string | null
  department?: string | null
}

/** A card the size of a bank card (front and back), to print and cut, or keep on the phone */
export async function downloadMemberCard(m: CardMember, who: Who) {
  const QR = (await import('qrcode')).default
  const doc = await newDoc('landscape', [85.6, 54])
  const W = 85.6
  const H = 54
  const brand = brandRgb(who)
  const logo = await loadLogo(who)
  const qr = await QR.toDataURL(`${who.sacco?.code ?? 'sacco'}|${m.account_no ?? ''}|${m.full_name}`, { margin: 0, width: 240, color: { dark: '#151821', light: '#ffffff' } })

  // Front
  doc.setFillColor(...brand)
  doc.rect(0, 0, W, 17, 'F')
  doc.setFillColor(...SAFFRON)
  doc.rect(0, 17, W, 0.8, 'F')
  drawMark(doc, who, logo, 4.5, 4, 9, true)
  doc.setTextColor(255, 255, 255)
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(9.5)
  doc.text(who.sacco?.name ?? 'SACCO', 17, 8.5)
  doc.setFont('helvetica', 'normal')
  doc.setFontSize(6)
  doc.text('MEMBER CARD', 17, 12.5)
  doc.setTextColor(...INK)
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(11)
  doc.text(doc.splitTextToSize(m.full_name, 52), 5, 26)
  const field = (label: string, value: string, y: number) => {
    doc.setFont('helvetica', 'normal')
    doc.setFontSize(5.5)
    doc.setTextColor(...MUTED)
    doc.text(label.toUpperCase(), 5, y)
    doc.setFont('helvetica', 'bold')
    doc.setFontSize(8)
    doc.setTextColor(...INK)
    doc.text(value, 5, y + 3.6)
  }
  field('Account no.', m.account_no ?? '-', 34)
  field('Member since', m.joined_on ? new Date(m.joined_on + 'T00:00:00').toLocaleDateString('en-GB', { month: 'short', year: 'numeric' }) : '-', 43)
  if (m.employee_no) {
    doc.setFont('helvetica', 'normal')
    doc.setFontSize(5.5)
    doc.setTextColor(...MUTED)
    doc.text('EMPLOYEE NO.', 32, 43)
    doc.setFont('helvetica', 'bold')
    doc.setFontSize(8)
    doc.setTextColor(...INK)
    doc.text(m.employee_no, 32, 46.6)
  }
  doc.addImage(qr, 'PNG', W - 27, 23, 23, 23)
  doc.setFillColor(...brand)
  doc.rect(0, H - 2.2, W, 2.2, 'F')

  // Back
  doc.addPage([85.6, 54], 'landscape')
  doc.setFillColor(...brand)
  doc.rect(0, 0, W, 6, 'F')
  doc.setTextColor(...INK)
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(7.5)
  doc.text('This card belongs to a member of', 5, 13)
  doc.text(who.sacco?.name ?? 'the SACCO', 5, 17)
  doc.setFont('helvetica', 'normal')
  doc.setFontSize(6.5)
  doc.setTextColor(...MUTED)
  doc.text(doc.splitTextToSize('Show it at the SACCO office when you deposit, withdraw or ask about a loan. If found, please return it to the SACCO office.', W - 10), 5, 23)
  doc.setDrawColor(...MUTED)
  doc.line(5, 42, 40, 42)
  doc.line(46, 42, 80.6, 42)
  doc.setFontSize(5.5)
  doc.text("Member's signature", 5, 45)
  doc.text('For the SACCO', 46, 45)
  doc.setFillColor(...SAFFRON)
  doc.rect(0, H - 2.2, W, 2.2, 'F')
  doc.save(`member-card-${m.account_no ?? 'member'}.pdf`)
}

// --- The loan approval letter -----------------------------------------------------------------

export interface LetterLoan {
  loan_no: string
  product_name: string | null
  amount: string
  months: number
  interest_rate_monthly: string
  purpose: string | null
  processing_fee: string
  decided_at?: string | null
  disbursed_on: string | null
  borrower_name: string
  borrower_account_no: string | null
  guarantors?: { full_name: string; amount: string; status: string }[]
  installments?: { total?: string; principal: string; interest: string }[]
  processing_fee_percent?: string
  /** A top-up: what went to clear the earlier loan */
  settled_amount?: string | null
}

/** The formal letter telling a member their loan is approved, with its terms */
export async function downloadLoanLetter(l: LetterLoan, who: Who) {
  const doc = await newDoc()
  const W = doc.internal.pageSize.getWidth()
  let y = await letterhead(doc, who)
  const cur = who.sacco?.currency ?? 'UGX'
  const amount = Number(l.amount)
  const rate = Number(l.interest_rate_monthly) / 100
  const first = l.installments?.[0]
  const instalment = first ? Number(first.principal) + Number(first.interest) : rate ? (amount * rate) / (1 - (1 + rate) ** -l.months) : amount / l.months
  const cleared = Number(l.settled_amount ?? 0)
  const fee = Number(l.processing_fee) || Math.round((amount * Number(l.processing_fee_percent ?? 0)) / 100)

  doc.setFont('helvetica', 'normal')
  doc.setFontSize(10)
  doc.setTextColor(...INK)
  doc.text(longDate(l.decided_at ? l.decided_at.slice(0, 10) : new Date()), 18, y)
  doc.text(`Our ref: ${l.loan_no}`, W - 18, y, { align: 'right' })
  y += 10
  doc.text(l.borrower_name, 18, y)
  if (l.borrower_account_no) doc.text(`Account no. ${l.borrower_account_no}`, 18, y + 5)
  y += 16
  doc.text(`Dear ${l.borrower_name.split(' ')[0]},`, 18, y)
  y += 9
  doc.setFont('helvetica', 'bold')
  doc.text(`RE: APPROVAL OF YOUR ${(l.product_name ?? 'loan').toUpperCase()} APPLICATION (${l.loan_no})`, 18, y)
  y += 8
  doc.setFont('helvetica', 'normal')
  const para = (text: string) => {
    const lines = doc.splitTextToSize(text, W - 36)
    doc.text(lines, 18, y)
    y += lines.length * 5 + 3
  }
  para(`We are pleased to inform you that your application for a ${l.product_name ?? 'loan'} of ${cur} ${money(amount)} (${amountInWords(amount)} ${cur === 'UGX' ? 'Uganda shillings' : cur} only)${l.purpose ? ` for ${l.purpose.replace(/\.$/, '')}` : ''} has been approved on the following terms:`)

  const { default: autoTable } = await import('jspdf-autotable')
  const rows: [string, string][] = [
    ['Amount of the loan', `${cur} ${money(amount)}`],
    ['Interest', `${l.interest_rate_monthly}% a month on the reducing balance`],
    ['Repayment period', `${l.months} months`],
    ['Monthly instalment', `${cur} ${money(instalment)}`],
    ['Processing fee', fee ? `${cur} ${money(fee)} (taken from the payout)` : 'None'],
    ...(cleared ? ([['Used to clear your earlier loan', `${cur} ${money(cleared)}`]] as [string, string][]) : []),
    ['Amount you receive', `${cur} ${money(amount - fee - cleared)}`],
  ]
  const brand = brandRgb(who)
  autoTable(doc, {
    startY: y,
    margin: { left: 18, right: 18 },
    body: rows,
    theme: 'plain',
    styles: { font: 'helvetica', fontSize: 9.5, cellPadding: 2.2, textColor: INK, lineColor: [231, 227, 218], lineWidth: { bottom: 0.15 } as never },
    columnStyles: { 0: { cellWidth: 60, textColor: MUTED }, 1: { fontStyle: 'bold' } },
    didParseCell: (d) => {
      if (d.row.index === rows.length - 1) d.cell.styles.fillColor = brand.map((v) => Math.round(v + (255 - v) * 0.9)) as RGB
    },
  })
  y = (doc as unknown as { lastAutoTable: { finalY: number } }).lastAutoTable.finalY + 8
  const guarantors = (l.guarantors ?? []).filter((g) => g.status === 'accepted')
  if (guarantors.length) para(`Guaranteed by: ${guarantors.map((g) => `${g.full_name} (${cur} ${money(g.amount)})`).join(', ')}.`)
  para('The instalments are deducted from your salary each month through the payroll. You may pay off the loan early at any time, and pay interest only for the months already started. Please collect your cheque from the SACCO office and bring your member card or national ID.')
  para('Thank you for saving with us.')
  y += 4
  doc.text('Yours faithfully,', 18, y)
  y += 22
  doc.setDrawColor(...MUTED)
  doc.line(18, y, 78, y)
  doc.line(W - 78, y, W - 18, y)
  doc.setFontSize(9)
  doc.setTextColor(...MUTED)
  doc.text('Chairperson, Credit Committee', 18, y + 5)
  doc.text('Treasurer / Manager', W - 78, y + 5)
  footer(doc, who, `${who.sacco?.name ?? 'SACCO'} · Loan ${l.loan_no}`)
  doc.save(`loan-approval-${l.loan_no}.pdf`)
}

// --- The share certificate --------------------------------------------------------------------

/** A certificate of the member's shares, on a landscape page with a border */
export async function downloadShareCertificate(m: CardMember, shares: number, sharePrice: number, capital: number, who: Who) {
  const doc = await newDoc('landscape')
  const W = doc.internal.pageSize.getWidth()
  const H = doc.internal.pageSize.getHeight()
  const brand = brandRgb(who)
  const cur = who.sacco?.currency ?? 'UGX'
  const logo = await loadLogo(who)

  // Borders: brand, then a fine saffron line inside
  doc.setDrawColor(...brand)
  doc.setLineWidth(2.2)
  doc.rect(9, 9, W - 18, H - 18)
  doc.setDrawColor(...SAFFRON)
  doc.setLineWidth(0.6)
  doc.rect(13, 13, W - 26, H - 26)

  drawMark(doc, who, logo, W / 2 - 9, 21, 18)
  doc.setTextColor(...INK)
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(16)
  doc.text((who.sacco?.name ?? 'SACCO').toUpperCase(), W / 2, 50, { align: 'center' })
  if (who.sacco?.slogan) {
    doc.setFont('helvetica', 'italic')
    doc.setFontSize(9.5)
    doc.setTextColor(...MUTED)
    doc.text(who.sacco.slogan, W / 2, 56, { align: 'center' })
  }
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(28)
  doc.setTextColor(...brand)
  doc.text('SHARE CERTIFICATE', W / 2, 74, { align: 'center' })
  doc.setFillColor(...SAFFRON)
  doc.rect(W / 2 - 25, 78, 50, 1, 'F')

  const year = new Date().getFullYear()
  doc.setFont('helvetica', 'normal')
  doc.setFontSize(9)
  doc.setTextColor(...MUTED)
  doc.text(`Certificate no. SC-${m.account_no ?? 'M'}-${year}`, 22, 30)
  doc.text(`Account no. ${m.account_no ?? '-'}`, W - 22, 30, { align: 'right' })

  doc.setFontSize(12)
  doc.setTextColor(...INK)
  doc.text('This is to certify that', W / 2, 92, { align: 'center' })
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(22)
  doc.text(m.full_name, W / 2, 104, { align: 'center' })
  doc.setDrawColor(...MUTED)
  doc.setLineWidth(0.2)
  doc.line(W / 2 - 70, 107, W / 2 + 70, 107)
  doc.setFont('helvetica', 'normal')
  doc.setFontSize(12)
  const text = shares > 0 && sharePrice > 0
    ? `is the registered holder of ${shares.toLocaleString('en-US')} fully paid share${shares === 1 ? '' : 's'} of ${cur} ${money(sharePrice)} each in ${who.sacco?.name ?? 'the SACCO'}, held under its rules and by-laws${capital > shares * sharePrice ? `, with a further ${cur} ${money(capital - shares * sharePrice)} paid towards the next share` : ''}.`
    : `holds a share capital of ${cur} ${money(capital)} in ${who.sacco?.name ?? 'the SACCO'}, held under its rules and by-laws.`
  doc.text(doc.splitTextToSize(text, W - 80), W / 2, 118, { align: 'center' })
  doc.setFontSize(10)
  doc.setTextColor(...MUTED)
  doc.text(`Total share capital ${cur} ${money(capital)} (${amountInWords(capital)} ${cur === 'UGX' ? 'Uganda shillings' : cur} only)`, W / 2, 134, { align: 'center' })

  doc.text(`Given on ${longDate(new Date())}`, W / 2, 148, { align: 'center' })
  doc.setDrawColor(...MUTED)
  doc.line(40, 175, 110, 175)
  doc.line(W - 110, 175, W - 40, 175)
  doc.setFontSize(9)
  doc.text('Chairperson', 75, 181, { align: 'center' })
  doc.text('Secretary', W - 75, 181, { align: 'center' })
  doc.save(`share-certificate-${m.account_no ?? 'member'}.pdf`)
}
