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
}
