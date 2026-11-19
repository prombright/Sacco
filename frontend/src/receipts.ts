import { api } from '@/api'
import type { Who } from '@/exporters'
import { loadLogo } from '@/documents'

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

/** The QR code for a receipt, with the SACCO's logo in a white square in the middle */
async function receiptQr(text: string, logo: { data: string } | null): Promise<string> {
  const QR = await import('qrcode')
  const size = 600
  const canvas = document.createElement('canvas')
  // high error correction, so the logo over the middle does not stop it scanning
  await QR.toCanvas(canvas, text, { errorCorrectionLevel: 'H', margin: 1, width: size, color: { dark: '#16133a', light: '#ffffff' } })
  if (logo) {
    const img = await new Promise<HTMLImageElement | null>((resolve) => {
      const i = new Image()
      i.onload = () => resolve(i)
      i.onerror = () => resolve(null)
      i.src = logo.data
    })
    if (img) {
      const ctx = canvas.getContext('2d')!
      const box = size * 0.24
      const x = (size - box) / 2
      ctx.fillStyle = '#ffffff'
      ctx.beginPath()
      ctx.roundRect(x - 8, x - 8, box + 16, box + 16, 18)
      ctx.fill()
      const r = img.naturalWidth / (img.naturalHeight || 1)
      const w = r >= 1 ? box : box * r
      const h = r >= 1 ? box / r : box
      ctx.drawImage(img, (size - w) / 2, (size - h) / 2, w, h)
    }
  }
  return canvas.toDataURL('image/png')
}

/**
 * One receipt as a till slip (80 mm wide, as long as it needs): the SACCO at the top, the
 * receipt's details in typewriter lettering between dashed rules, a stamp, who recorded it, and a
 * QR code with the logo in the middle that carries the receipt's details.
 */
export async function downloadReceipt(r: Receipt, member: ReceiptMember, who: Who) {
  const { jsPDF } = await import('jspdf')
  const logo = await loadLogo(who)
  const name = who.sacco?.name ?? 'SACCO'
  const currency = who.sacco?.currency ?? 'UGX'
  const incoming = r.direction === 'in'
  const money = (v: string | number) => `${currency} ${plain(v)}`
  const date = new Date(r.date + 'T00:00:00')
  const qr = await receiptQr(
    [`${name} receipt ${r.no}`, `${incoming ? 'Received from' : 'Paid to'}: ${member.full_name}${member.account_no ? ` (${member.account_no})` : ''}`, `${r.title} - ${r.account}`, `Amount: ${money(r.amount)}`, `Date: ${date.toLocaleDateString('en-GB')}`, r.reversed ? 'REVERSED' : ''].filter(Boolean).join('\n'),
    logo,
  )

  const W = 80
  const M = 5
  const ink: [number, number, number] = [22, 19, 58]
  const grey: [number, number, number] = [110, 106, 128]

  /** Lays the slip out on `doc` and returns how tall it is (run once to measure, once to draw) */
  const lay = (doc: InstanceType<typeof jsPDF>) => {
    let y = 7
    const center = (text: string, size: number, style: 'normal' | 'bold' | 'italic' = 'normal', color = ink, space = 0) => {
      doc.setFont('courier', style)
      doc.setFontSize(size)
      doc.setTextColor(...color)
      // jsPDF centres without the letter spacing, so measure each line with it and place it by hand
      const widthOf = (t: string) => doc.getTextWidth(t) + space * Math.max(0, t.length - 1)
      const words = text.split(' ')
      const lines: string[] = []
      for (const w of words) {
        const next = lines.length ? `${lines[lines.length - 1]} ${w}` : w
        if (lines.length && widthOf(next) <= W - 2 * M) lines[lines.length - 1] = next
        else lines.push(w)
      }
      for (const line of lines) {
        doc.text(line, (W - widthOf(line)) / 2, y, { charSpace: space })
        y += size * 0.42
      }
    }
    const dashed = (gap = 3) => {
      y += gap / 2
      doc.setDrawColor(150, 146, 170)
      doc.setLineWidth(0.2)
      doc.setLineDashPattern([0.8, 0.8], 0)
      doc.line(M, y, W - M, y)
      doc.setLineDashPattern([], 0)
      // room for the next line's capitals above its baseline
      y += gap + 3
    }
    const row = (label: string, value: string, bold = false, size = 8.5) => {
      doc.setFont('courier', bold ? 'bold' : 'normal')
      doc.setFontSize(size)
      doc.setTextColor(...ink)
      doc.text(label, M, y)
      const lines = doc.splitTextToSize(value, W - 2 * M - doc.getTextWidth(label) - 3)
      doc.text(lines, W - M, y, { align: 'right' })
      y += size * 0.42 * lines.length + 1.4
    }
    const block = (label: string, lines: string[]) => {
      doc.setFont('courier', 'bold')
      doc.setFontSize(8.5)
      doc.setTextColor(...ink)
      doc.text(label, M, y)
      y += 4
      doc.setFont('courier', 'normal')
      for (const l of lines) {
        for (const part of doc.splitTextToSize(l, W - 2 * M)) {
          doc.text(part, M, y)
          y += 3.8
        }
      }
      y += 1.5
    }

    // the SACCO
    if (logo) {
      const h = 16
      const w = Math.min(h * logo.ratio, 40)
      doc.addImage(logo.data, (W - w) / 2, y, w, w / logo.ratio)
      y += w / logo.ratio + 6
    } else y += 2
    center(name.toUpperCase(), 13, 'bold', ink, 1.1)
    y += 1
    center(who.sacco?.slogan || 'Savings & Credit Cooperative', 8, 'normal', grey)
    center(location.host, 8, 'normal', ink)
    dashed()
    center(incoming ? 'PAYMENT RECEIPT' : 'PAYMENT ADVICE', 10.5, 'bold', ink, 1)
    dashed(2.5)

    // when and which
    row(`DATE: ${date.toLocaleDateString('en-GB')}`, `ACC: ${member.account_no ?? '-'}`)
    row('RECEIPT #', r.no)
    if (r.reference) row('REFERENCE', r.reference)
    dashed(2.5)

    // who and what for
    block(incoming ? 'RECEIVED FROM:' : 'PAID TO:', [member.full_name, ...(member.employee_no ? [`Employee no. ${member.employee_no}`] : [])])
    block('FOR:', [`${r.title} - ${r.account}`, ...(r.details ? [r.details] : [])])
    dashed(2.5)

    // the money
    for (const l of r.lines) row(l.label.toUpperCase(), money(l.amount))
    y += 1
    row(incoming ? 'AMOUNT PAID' : 'AMOUNT OUT', money(r.amount), true, 10.5)
    doc.setFont('courier', 'italic')
    doc.setFontSize(7.5)
    doc.setTextColor(...grey)
    const unit = currency === 'UGX' ? 'Uganda shillings' : currency
    for (const l of doc.splitTextToSize(`(${amountInWords(Number(r.amount))} ${unit} only)`, W - 2 * M)) {
      doc.text(l, M, y)
      y += 3.3
    }
    y += 1.5
    doc.setDrawColor(...ink)
    doc.setLineWidth(0.35)
    doc.line(M, y, W - M, y)
    doc.line(M, y + 0.9, W - M, y + 0.9)
    y += 5
    row('ACCOUNT', r.account.toUpperCase())
    if (r.balance_after !== null) row('BALANCE AFTER', money(r.balance_after), true)

    // the stamp
    y += 4
    const stamp = r.reversed ? 'REVERSED' : incoming ? 'RECEIVED' : 'PAID OUT'
    const tone: [number, number, number] = r.reversed ? [210, 55, 70] : incoming ? [196, 122, 20] : [106, 31, 245]
    const ang = (-6 * Math.PI) / 180
    const cx = W / 2
    const cy = y + 5
    const bw = 44
    const bh = 11
    const corner = (dx: number, dy: number): [number, number] => [cx + dx * Math.cos(ang) - dy * Math.sin(ang), cy + dx * Math.sin(ang) + dy * Math.cos(ang)]
    const pts = [corner(-bw / 2, -bh / 2), corner(bw / 2, -bh / 2), corner(bw / 2, bh / 2), corner(-bw / 2, bh / 2)]
    doc.setDrawColor(...tone)
    doc.setLineWidth(0.8)
    for (let i = 0; i < 4; i++) doc.line(pts[i][0], pts[i][1], pts[(i + 1) % 4][0], pts[(i + 1) % 4][1])
    doc.setFont('courier', 'bold')
    doc.setFontSize(12)
    doc.setTextColor(...tone)
    doc.text(stamp, cx, cy + 1.5, { align: 'center', angle: 6, charSpace: 1 })
    y += 16

    // who recorded it
    dashed(2.5)
    doc.setFont('courier', 'bold')
    doc.setFontSize(8.5)
    doc.setTextColor(...ink)
    const by = 'RECORDED BY: '
    const byW = doc.getTextWidth(by)
    doc.setFont('courier', 'normal')
    const person = r.recorded_by ?? 'The system'
    const total = byW + doc.getTextWidth(person)
    doc.setFont('courier', 'bold')
    doc.text(by, (W - total) / 2, y)
    doc.setFont('courier', 'normal')
    doc.text(person, (W - total) / 2 + byW, y)
    y += 7
    doc.text('Signature:', M + 4, y)
    doc.setLineWidth(0.25)
    doc.line(M + 25, y + 0.5, W - M - 4, y + 0.5)
    y += 3
    dashed(3)

    // the QR code, in a rounded frame
    const q = 30
    doc.setDrawColor(...ink)
    doc.setLineWidth(0.3)
    doc.roundedRect((W - q) / 2 - 2, y - 2, q + 4, q + 4, 2.5, 2.5, 'S')
    doc.addImage(qr, 'PNG', (W - q) / 2, y, q, q)
    y += q + 8

    center(`THANK YOU FOR SAVING WITH ${name.toUpperCase()}!`, 8.5, 'bold', ink, 0.4)
    y += 1.5
    center('Produced by the SACCO system.', 7.5, 'normal', grey)
    center('Keep this receipt for your records.', 7.5, 'normal', grey)

    if (r.reversed) {
      doc.setFont('courier', 'bold')
      doc.setFontSize(26)
      doc.setTextColor(230, 120, 130)
      doc.text('REVERSED', W / 2, y / 2, { align: 'center', angle: 30 })
    }
    return y + 4
  }

  // measure, then draw on a page exactly as long as the slip
  const height = lay(new jsPDF({ unit: 'mm', format: [W, 400] }))
  const doc = new jsPDF({ unit: 'mm', format: [W, height] })
  lay(doc)
  doc.save(`receipt-${r.no}.pdf`)
}

/** Fetches a receipt by number and downloads it */
export async function downloadReceiptNo(no: string, who: Who) {
  const res = await api.get<{ receipt: Receipt; member: ReceiptMember }>(`/receipts/${no}`)
  await downloadReceipt(res.receipt, res.member, who)
}
