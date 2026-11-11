import type { CreditStanding, Loan } from '@/types'
import type { LoanStatus } from '@/types'

export const loanStatus: Record<LoanStatus, { label: string; style: string; dot: string; explain: string }> = {
  pending_guarantors: {
    label: 'Waiting for guarantors',
    dot: 'bg-amber-400',
    style: 'bg-amber-50 text-amber-800',
    explain: 'Your guarantors have been asked. Once enough of them agree, it goes to the SACCO office.',
  },
  pending_approval: {
    label: 'Waiting for approval',
    dot: 'bg-navy-600',
    style: 'bg-navy-50 text-navy-800',
    explain: 'Your guarantors have agreed. The SACCO office will review it.',
  },
  approved: {
    label: 'Approved',
    dot: 'bg-saffron-500',
    style: 'bg-saffron-100 text-saffron-700',
    explain: 'Approved. The SACCO office will issue your cheque.',
  },
  active: { label: 'Active', dot: 'bg-emerald-600', style: 'bg-green-50 text-green-800', explain: '' },
  closed: { label: 'Repaid', dot: 'bg-gray-400', style: 'bg-gray-100 text-gray-700', explain: 'Fully repaid.' },
  rejected: { label: 'Rejected', dot: 'bg-red-500', style: 'bg-red-50 text-red-700', explain: '' },
  cancelled: { label: 'Cancelled', dot: 'bg-gray-300', style: 'bg-gray-100 text-gray-600', explain: '' },
}

export const guarantorStatus: Record<string, { label: string; style: string }> = {
  pending: { label: 'Waiting', style: 'bg-amber-50 text-amber-800' },
  accepted: { label: 'Agreed', style: 'bg-green-50 text-green-800' },
  declined: { label: 'Declined', style: 'bg-red-50 text-red-700' },
  withdrawn: { label: 'No longer asked', style: 'bg-gray-100 text-gray-600' },
  released: { label: 'Released', style: 'bg-gray-100 text-gray-600' },
}

/** "9 of the last 12 instalments paid on time, the longest delay 40 days" */
export function creditSummary(c: CreditStanding): string {
  if (c.grade === 'new') return 'No instalments have fallen due yet.'
  let text = `${c.on_time} of the last ${c.due} instalment${c.due === 1 ? '' : 's'} paid on time`
  if (c.late) text += `, the longest delay ${c.worst_days} days`
  if (c.overdue_now) text += `; ${c.overdue_now} unpaid now`
  return text + '.'
}

/**
 * A loan's money at a glance: what was borrowed, the interest on it, the total to repay, what's
 * been paid and what's left. Before payout the interest comes from the expected schedule.
 */
export function loanFigures(loan: Loan) {
  const p = loan.position
  const borrowed = Number(loan.amount)
  const interest = p ? Number(p.total_interest) : (loan.projected ?? []).reduce((sum, i) => sum + Number(i.interest), 0)
  const total = borrowed + interest
  const paid = p ? Number(p.paid) : 0
  const left = p ? Number(p.total_left) : total
  // Penalties still owed sit inside "left" but not in "total"
  const penalties = p ? Number(p.charges_left) : 0
  const percentPaid = total > 0 ? Math.min(100, Math.round((paid / total) * 100)) : 0
  return { borrowed, interest, total, paid, left, penalties, percentPaid, known: !!p || !!loan.projected?.length }
}
