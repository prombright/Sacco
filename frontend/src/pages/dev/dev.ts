import type { Sacco } from '@/types'

/** What the developer portal shows about each SACCO */
export interface DevSacco extends Sacco {
  id: number
  domain: string | null
  about: string | null
  status: 'active' | 'paused'
  created_at: string
}

export interface Waiting {
  loans_to_approve: number
  loans_to_pay_out: number
  loans_waiting_guarantors: number
  withdrawals_to_approve: number
  withdrawals_to_pay: number
  members_not_signed_in: number
}

export interface SaccoStats {
  members: number
  office_accounts: number
  savings: string
  shares: string
  loans_outstanding: string
  active_loans: number
  arrears: string
  loans_in_arrears: number
  waiting: Waiting
  waiting_total: number
  signed_in_30_days: number
  last_activity: string | null
  funds: { month: string; value: string }[]
}

export interface PortalLogRow {
  developer: string
  sacco: string | null
  action: string
  text: string
  details: string
  ip: string | null
  at: string
}

export function ago(at: string | null) {
  if (!at) return 'never'
  const mins = Math.round((Date.now() - new Date(at.replace(' ', 'T')).getTime()) / 60000)
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins} min ago`
  const h = Math.round(mins / 60)
  if (h < 24) return `${h} h ago`
  const d = Math.round(h / 24)
  if (d === 1) return 'yesterday'
  if (d < 45) return `${d} days ago`
  return new Date(at.replace(' ', 'T')).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
}
