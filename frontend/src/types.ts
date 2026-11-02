export type Role = 'super_admin' | 'admin' | 'member'
export type UserStatus = 'active' | 'suspended' | 'left'

export interface User {
  id: number
  full_name: string
  email: string | null
  employee_no: string | null
  account_no: string | null
  phone: string | null
  role: Role
  role_label: string
  status: UserStatus
  source: 'manual' | 'api'
  must_change_password: boolean
  last_login_at: string | null
  created_at: string
  /** Included in the users list */
  balances?: Balances
}

export type Account = 'savings' | 'shares'

/** Decimal strings, e.g. "1500000.00" */
export interface Balances {
  savings: string
  shares: string
}

export interface Transaction {
  id: number
  account: Account
  type: 'opening_balance' | 'deposit' | 'withdrawal' | 'adjustment' | 'dividend' | 'reversal'
  amount: string
  balance_after: string
  value_date: string
  reference: string | null
  narration: string | null
  reversal_of: number | null
  reversed_by: number | null
  created_by_name: string | null
  created_at: string
}

export interface Page<T> {
  total: number
  page: number
  per_page: number
  transactions: T[]
}

export interface Sacco {
  code: string
  name: string
  short_name: string | null
  logo_url: string | null
  brand_color: string
  currency: string
}

export interface Session {
  user: User | null
  sacco: Sacco | null
  csrf_token: string | null
}
