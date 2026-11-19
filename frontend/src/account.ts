import type { Sacco } from '@/types'

/**
 * A member's account number as the member sees it: the SACCO's two-letter prefix, then the number
 * (SMACON SACCO, account 299 → SM299). Only for showing; the stored number stays as it is.
 * A number that already starts with letters is shown unchanged.
 */
export function accountLabel(no: string | number | null | undefined, sacco: Sacco | null | undefined): string {
  if (no === null || no === undefined || no === '') return '-'
  const s = String(no).trim()
  if (/^[A-Za-z]/.test(s)) return s
  const prefix = (sacco?.short_name || sacco?.name || '').replace(/[^A-Za-z]/g, '').slice(0, 2).toUpperCase()
  return prefix + s
}
