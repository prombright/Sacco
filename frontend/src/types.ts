export type Role = 'super_admin' | 'admin' | 'staff' | 'member'
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
  /** Their profile photo (a path inside the app), or null */
  photo_url?: string | null
  phone: string | null
  role: Role
  role_label: string
  status: UserStatus
  source: 'manual' | 'api'
  must_change_password: boolean
  /** Members: whether they have seen the first-login welcome */
  welcomed?: boolean
  /** Office accounts: their owner's own member account */
  linked_member_id?: number | null
  /** Members: the office role they hold through office access (null: none) */
  office_role?: Role | null
  /** Office accounts: given to a member, reached with the member's own login */
  office_via_member?: boolean
  /** The other account the logged-in person may switch to now */
  switch_to?: { to: 'member' | 'office'; label: string; full_name: string } | null
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
  /** A mistake and its correction: hidden from the member */
  in_error?: boolean
  /** A standalone entry the super admin may correct or delete */
  editable?: boolean
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
  /** Unpaid interest added to the principal so far */
  interest_added?: string
  paid: string
  total_interest: string
  next_due_date: string | null
  next_due_amount: string
  overdue: string
}

export interface LoanSecurity {
  id: number
  kind: string
  description: string
  value: string | null
  reference: string | null
  kept_at: string | null
  created_at: string
  created_by_name: string | null
  returned_on: string | null
  returned_by_name: string | null
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
  /** Month by month (from November 2026): what the month asked, what was paid in it, unpaid interest added to the principal, how short it fell, what was owed after */
  demanded?: string | null
  paid_in_month?: string | null
  capitalised?: string
  short?: string
  balance_after?: string | null
  cleared_on?: string | null
  /** The calendar month is over */
  month_over?: number | boolean
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
  /** Collateral left with the SACCO (borrower and office only) */
  securities?: LoanSecurity[]
  installments?: Installment[]
  projected?: Installment[]
  payments?: LoanPayment[]
  borrower_eligibility?: { savings: string; owed: string; limit: string } | null
  /** Who approves it (up to the borrower's savings: a super admin; more, or a super admin's loan: an admin), and whether the viewer may */
  decision?: { needs: 'admin' | 'super_admin'; why_needs: string; can: boolean; why_not: string | null }
  borrower_credit?: CreditStanding
  product_name: string | null
  /** Taken off the cheque at payout (an estimate until then) */
  /** The fee comes from the borrower's savings (the cheque is the full loan) */
  fee_from_savings?: boolean
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
  processing_fee_type: 'flat' | 'percent' | 'bands'
  processing_fee: string
  processing_fee_percent: string
  processing_fee_bands: { from: string; to: string | null; fee: string }[]
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
  /** The SACCO's longest loan for members (a loan type may be shorter) */
  sacco_max_months?: number
  min_guarantors: number
  guarantee_cover_percent: number
  min_member_months: number
  guarantor_min_member_months: number
  processing_fee: string
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

/** How well a member repays, from their last 12 instalments that fell due */
export interface CreditStanding {
  grade: 'new' | 'good' | 'fair' | 'poor'
  label: string
  score: number | null
  due: number
  on_time: number
  late: number
  overdue_now: number
  worst_days: number
  /** 100, or less for a member the office marked as a slow payer */
  limit_percent: number
  advice: string
  /** The office's slow-payer mark (the system never sets it) */
  marked?: { since: string; note: string | null; by: string | null } | null
}
