import { useAuth } from '@/stores/auth'

/** No-break space and narrow no-break space (U+00A0, U+202F) */
const NO_BREAK_SPACES = new RegExp('[' + String.fromCharCode(0xa0, 0x202f) + ']', 'g')

/**
 * Amounts arrive from the API as decimal strings ("1500000.00") and are only turned into numbers
 * here, for display. Never do arithmetic on money in the browser - the server does it.
 */
export function formatMoney(amount: string | null | undefined, opts: { sign?: boolean } = {}): string {
  const currency = useAuth().sacco?.currency ?? 'UGX'
  const value = Number(amount ?? 0)
  const formatted = new Intl.NumberFormat('en-UG', {
    style: 'currency',
    currency,
    currencyDisplay: 'code',
    // Shillings have no cents in practice; show them only when there are some
    minimumFractionDigits: Number.isInteger(value) ? 0 : 2,
    maximumFractionDigits: 2,
  })
    .format(Math.abs(value))
    // The browser puts a no-break space after "UGX"; the font draws it almost zero-width
    .replace(NO_BREAK_SPACES, ' ')
  if (value < 0) return '−' + formatted
  return opts.sign && value > 0 ? '+' + formatted : formatted
}

export function formatDate(date: string | null | undefined): string {
  if (!date) return ''
  return new Date(date.length === 10 ? date + 'T00:00:00' : date.replace(' ', 'T')).toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

export const transactionTypeLabel: Record<string, string> = {
  opening_balance: 'Opening balance',
  deposit: 'Deposit',
  withdrawal: 'Withdrawal',
  adjustment: 'Adjustment',
  dividend: 'Dividend',
  profit: 'Profit share',
  charge: 'Fee',
  reversal: 'Reversal',
  transfer: 'Transfer',
}

export const accountLabel: Record<string, string> = {
  savings: 'Savings',
  shares: 'Shares',
}
