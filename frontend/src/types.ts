export type Role = 'super_admin' | 'admin' | 'member'
export type UserStatus = 'active' | 'suspended' | 'left'

export interface User {
  /** Areas this office account may change (members, money, loans, notices) */
  permissions?: string[]
  id: number
  full_name: string
  email: string | null
  employee_no: string | null
  account_no: string | null
  /** Member since; null means "from their first savings entry" */
  joined_on: string | null
  national_id: string | null
  date_of_birth: string | null
  gender: 'female' | 'male' | null
  department: string | null
  position: string | null
  address: string | null
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
  charges_left: string
  overdue_instalments: string
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
  status: 'pending' | 'accepted' | 'declined' | 'withdrawn' | 'released'
  responded_at: string | null
  /** Set when this guarantor was asked to take over another's guarantee */
  replaces_id?: number | null
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

/** What a repayment did (or would do): its split, what is owed after, what falls due next */
export interface PaymentReceipt {
  payment_id: number
  amount: string
  paid_on: string
  penalties: string
  interest: string
  principal: string
  owed_before: string
  owed_after: string
  principal_after: string
  overdue_after: string
  next_due_date: string | null
  next_due_amount: string
  closed: boolean
}

export interface LoanPayment {
  /** How it was split, if it still stands */
  split?: { principal?: string; interest?: string; penalty?: string } | null
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
  product_name: string | null
  /** Taken off the cheque at payout (an estimate until then) */
  processing_fee: string
  settled_early_on: string | null
  /** A top-up: the loan it cleared, and how much went to clear it */
  replaces_loan_id?: number | null
  settled_amount?: string | null
  charges?: LoanCharge[]
  /** Admins, before payout: does the borrower qualify under the SACCO's rules today? */
  assessment?: {
    verdict: 'qualifies' | 'qualifies_with_concerns' | 'does_not_qualify'
    checks: { label: string; level: 'pass' | 'warn' | 'fail'; detail: string }[]
  } | null
  /** What paying it all off today would cost (admins, active loans) */
  settlement?: { amount: string; interest_saved: string } | null
}

export interface LoanCharge {
  id: number
  type: 'penalty' | 'fee'
  amount: string
  paid: string
  charged_on: string
  note: string | null
  waived_at: string | null
}

export interface LoanProduct {
  id: number
  name: string
  description: string | null
  interest_rate_monthly: string
  max_months: number
  savings_multiplier: string
  processing_fee_percent: string
  min_guarantors: number
  guarantee_cover_percent: number
  min_amount: string | null
  max_amount: string | null
  min_member_months: number | null
  max_open: number
  open_months: number[]
  allow_top_up: boolean
  status: 'active' | 'archived'
}

export interface NextOfKin {
  id?: number
  full_name: string
  relationship: string
  phone: string | null
  share: string
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
  processing_fee_percent: string
  product_id: number | null
  product_name: string | null
}

export interface Sacco {
  code: string
  name: string
  short_name: string | null
  logo_url: string | null
  /** A photo for the login page, relative to the app (e.g. brand/smacon-login.jpg) */
  login_image: string | null
  slogan: string | null
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
