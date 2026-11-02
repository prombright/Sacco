import type { LoanStatus } from '@/types'

export const loanStatus: Record<LoanStatus, { label: string; style: string; explain: string }> = {
  pending_guarantors: {
    label: 'Waiting for guarantors',
    style: 'bg-amber-50 text-amber-800',
    explain: 'Your guarantors have been asked. Once enough of them agree, it goes to the SACCO office.',
  },
  pending_approval: {
    label: 'Waiting for approval',
    style: 'bg-blue-50 text-blue-800',
    explain: 'Your guarantors have agreed. The SACCO office will review it.',
  },
  approved: {
    label: 'Approved',
    style: 'bg-brand/10 text-brand',
    explain: 'Approved. The SACCO office will issue your cheque.',
  },
  active: { label: 'Active', style: 'bg-green-50 text-green-800', explain: '' },
  closed: { label: 'Repaid', style: 'bg-gray-100 text-gray-700', explain: 'Fully repaid.' },
  rejected: { label: 'Rejected', style: 'bg-red-50 text-red-700', explain: '' },
  cancelled: { label: 'Cancelled', style: 'bg-gray-100 text-gray-600', explain: '' },
}

export const guarantorStatus: Record<string, { label: string; style: string }> = {
  pending: { label: 'Waiting', style: 'bg-amber-50 text-amber-800' },
  accepted: { label: 'Agreed', style: 'bg-green-50 text-green-800' },
  declined: { label: 'Declined', style: 'bg-red-50 text-red-700' },
  withdrawn: { label: 'No longer asked', style: 'bg-gray-100 text-gray-600' },
}
