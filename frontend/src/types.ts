export type Role = 'super_admin' | 'admin' | 'member'
export type UserStatus = 'active' | 'suspended' | 'left'

export interface User {
  id: number
  full_name: string
  email: string | null
  employee_no: string | null
  account_no: string | null
  /** Member since; null means "from their first savings entry" */
  joined_on: string | null
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

export type LoanStatus = 'pending_guarantors' | 'pending_approval' | 'approved' | 'active' | 'closed' | 'rejected' | 'cancelled'

export interface LoanPosition {
  principal_left: string
  interest_left: string
  total_left: string
  paid: string
  total_interest: string
  next_due_date: string | null
  next_due_amount: string
  overdue: string
}

export interface LoanGuarantor {
  id: number
  guarantor_id: number
  full_name: string
  account_no: string | null
  /** What they agreed to (or, while pending, what was asked) */
  amount: string
  /** What the borrower asked them for */
  requested_amount: string | null
  status: 'pending' | 'accepted' | 'declined' | 'withdrawn'
  responded_at: string | null
}

export interface Installment {
  number: number
  due_date: string
  principal: string
  interest: string
  principal_paid?: string
  interest_paid?: string
  total?: string
}

export interface LoanPayment {
  id: number
  amount: string
  paid_on: string
  reference: string | null
  narration: string | null
  reversal_of: number | null
  reversed_by: number | null
  created_by_name: string | null
}

export interface Loan {
  id: number
  loan_no: string
  user_id: number
  borrower_name: string
  borrower_account_no: string | null
  amount: string
  months: number
  interest_rate_monthly: string
  purpose: string | null
  status: LoanStatus
  decision_note: string | null
  disbursed_on: string | null
  disbursement_ref: string | null
  closed_on: string | null
  created_at: string
  position: LoanPosition | null
  guarantee_shortfall: { guarantors: number; amount: string } | null
  // Only on the full loan
  guarantors?: LoanGuarantor[]
  installments?: Installment[]
  projected?: Installment[]
  payments?: LoanPayment[]
  borrower_eligibility?: { savings: string; owed: string; limit: string } | null
}

export interface LoanRules {
  interest_rate_monthly: string
  interest_method: string
  savings_multiplier: string
  max_months: number
  min_guarantors: number
  guarantee_cover_percent: number
  min_member_months: number
  guarantor_min_member_months: number
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

/** A member chosen from the member search (names only, never balances) */
export interface PickedMember {
  id: number
  full_name: string
  account_no: string | null
}
