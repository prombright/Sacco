export type WithdrawalStatus = 'pending' | 'approved' | 'paid' | 'rejected' | 'cancelled'

export interface Withdrawal {
  id: number
  request_no: string
  user_id: number
  full_name: string
  account_no: string | null
  employee_no: string | null
  amount: string
  reason: string | null
  status: WithdrawalStatus
  decided_by_name: string | null
  decided_at: string | null
  decision_note: string | null
  cheque_no: string | null
  paid_on: string | null
  paid_by_name: string | null
  created_at: string
  /** Admin list only: what the member could still withdraw, leaving this request out */
  available_now?: string
}

export const withdrawalStatus: Record<WithdrawalStatus, { label: string; style: string; explain: string }> = {
  pending: { label: 'Waiting for approval', style: 'bg-amber-50 text-amber-800', explain: 'The SACCO office will review your request.' },
  approved: { label: 'Approved', style: 'bg-blue-50 text-blue-800', explain: 'Approved. Your cheque is being prepared.' },
  paid: { label: 'Cheque issued', style: 'bg-green-50 text-green-800', explain: '' },
  rejected: { label: 'Rejected', style: 'bg-red-50 text-red-700', explain: '' },
  cancelled: { label: 'Cancelled', style: 'bg-gray-100 text-gray-600', explain: '' },
}

const ONES = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen']
const TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety']

function belowThousand(n: number): string {
  const parts: string[] = []
  if (n >= 100) {
    parts.push(ONES[Math.floor(n / 100)] + ' hundred')
    n %= 100
  }
  if (n >= 20) {
    parts.push(TENS[Math.floor(n / 10)] + (n % 10 ? '-' + ONES[n % 10] : ''))
  } else if (n > 0) {
    parts.push(ONES[n])
  }
  return parts.join(' and ')
}

/** 1250000 -> "One million, two hundred and fifty thousand" - for the withdrawal slip */
export function amountInWords(amount: string | number): string {
  let n = Math.floor(Number(amount))
  if (n === 0) return 'Zero'
  const groups: string[] = []
  for (const [size, name] of [[1e9, 'billion'], [1e6, 'million'], [1e3, 'thousand'], [1, '']] as const) {
    if (n >= size) {
      groups.push(belowThousand(Math.floor(n / size)) + (name ? ' ' + name : ''))
      n %= size
    }
  }
  const words = groups.join(', ')
  return words.charAt(0).toUpperCase() + words.slice(1)
}
