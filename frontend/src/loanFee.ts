/** A loan type's processing fee: a flat amount, a percentage of the loan, or a fee by loan size */
export interface FeeBand {
  from: string
  to: string | null
  fee: string
}
export interface FeeRule {
  processing_fee_type?: 'flat' | 'percent' | 'bands'
  processing_fee: string
  processing_fee_percent?: string
  processing_fee_bands?: FeeBand[]
}

/** The fee on a loan of this amount, as the server works it out (LoanMath rounds to whole shillings) */
export function loanFee(p: FeeRule | null | undefined, amount: number): number {
  if (!p || !amount) return 0
  if (p.processing_fee_type === 'percent') return Math.round((amount * Number(p.processing_fee_percent ?? 0)) / 100)
  if (p.processing_fee_type === 'bands') {
    const band = (p.processing_fee_bands ?? []).find((b) => amount >= Number(b.from) && (b.to === null || b.to === '' || amount <= Number(b.to)))
    return band ? Number(band.fee) : 0
  }
  return Number(p.processing_fee ?? 0)
}

const n = (v: string | number) => Number(v).toLocaleString('en-UG')

/** A short description, e.g. "10,000 fee", "2% fee", "fee by loan size" */
export function feeText(p: FeeRule): string {
  if (p.processing_fee_type === 'percent') return Number(p.processing_fee_percent) ? `${Number(p.processing_fee_percent)}% fee` : 'no fee'
  if (p.processing_fee_type === 'bands') return (p.processing_fee_bands ?? []).length ? 'fee by loan size' : 'no fee'
  return Number(p.processing_fee) ? `${n(p.processing_fee)} fee` : 'no fee'
}

/** Each band in words: "100,000 to 1,000,000: 10,000" */
export const bandText = (b: FeeBand) => `${n(b.from)} ${b.to ? `to ${n(b.to)}` : 'and above'}: ${n(b.fee)}`
