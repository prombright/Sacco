import type { Component } from 'vue'
import {
  ArrowDownLeftIcon,
  ArrowsRightLeftIcon,
  ArrowTrendingUpIcon,
  ArrowUpRightIcon,
  ArrowUpTrayIcon,
  ArrowUturnLeftIcon,
  BanknotesIcon,
  BookOpenIcon,
  BriefcaseIcon,
  BuildingLibraryIcon,
  BuildingOffice2Icon,
  CalculatorIcon,
  ChartBarIcon,
  CheckBadgeIcon,
  CircleStackIcon,
  ClipboardDocumentCheckIcon,
  Cog6ToothIcon,
  DocumentTextIcon,
  IdentificationIcon,
  InboxStackIcon,
  ListBulletIcon,
  LockClosedIcon,
  MegaphoneIcon,
  PresentationChartLineIcon,
  ReceiptPercentIcon,
  ScaleIcon,
  ShieldCheckIcon,
  DocumentChartBarIcon,
  Squares2X2Icon,
  TableCellsIcon,
  UserGroupIcon,
  UserPlusIcon,
  UsersIcon,
  WalletIcon,
  DocumentArrowUpIcon,
  EnvelopeIcon,
} from '@heroicons/vue/24/outline'
import type { Waiting } from '@/stores/office'
import { formatMoney } from '@/money'

/** The figures shown on the section dashboards (GET /office/sections) */
export interface SectionStats {
  waiting: Waiting
  deposits_month: string
  transfers_month: number
  transactions_month: number
  fees_unpaid: number
  fees_not_charged: number
  fee_set: boolean
  members: number
  last_payroll: string | null
  active_loans: number
  loans_outstanding: string
  interest_month: string
  payments_month: string
  receipts_month: string
  journals_month: number
  balanced: boolean
  income_year: string
  surplus_year: string
  accounts: number
  last_backup: string | null
  investments: string
}

export interface SectionItem {
  name: string
  label: string
  description: string
  icon: Component
  /** Other pages that belong under this one */
  also?: string[]
  /** A live figure for the card */
  figure?: (s: SectionStats) => string | null
  /** Things waiting here (also shown in the menu) */
  badge?: (w: Waiting) => number
  superOnly?: boolean
  /** Only for accounts allowed to change this area (the page is all about making changes) */
  needs?: string
}

export interface Section {
  key: string
  label: string
  /** The section's own dashboard */
  hub: string
  icon: Component
  /** Tailwind gradient for the section's tiles */
  tone: string
  intro: string
  items: SectionItem[]
}

const short = (v: string | number) => {
  const n = Number(v)
  return n >= 1e9 ? `${+(n / 1e9).toFixed(1)}B` : n >= 1e6 ? `${+(n / 1e6).toFixed(1)}M` : n >= 1e3 ? `${Math.round(n / 1e3)}K` : String(Math.round(n))
}
const month = new Date().toLocaleDateString('en-GB', { month: 'long' })

/** The office menu: a few sections, each opening on a dashboard of its modules */
export const sections: Section[] = [
  {
    key: 'members',
    label: 'Members',
    hub: 'section-members',
    icon: UsersIcon,
    tone: 'from-navy-600 to-navy-800',
    intro: 'The people of the SACCO: their records, their fees, and news for them.',
    items: [
      { name: 'users', label: 'Member register', description: 'Everyone, with balances, details and next of kin', icon: UsersIcon, also: ['member', 'member-statement'], figure: (s) => `${s.members} active members` },
      { name: 'member-import', label: 'Add members from Excel', description: 'Bring many members in at once', icon: UserPlusIcon, needs: 'members' },
      { name: 'membership-fees', label: 'Membership & subscription', description: 'Membership once, subscription yearly: charge, collect, waive', icon: IdentificationIcon, figure: (s) => (!s.fee_set ? 'Fee not set yet' : s.fees_not_charged ? `${s.fees_not_charged} not yet charged this year` : `${s.fees_unpaid} unpaid this year`) },
      { name: 'notices', label: 'Notices', description: 'Announcements on members\' dashboards', icon: MegaphoneIcon },
    ],
  },
  {
    key: 'money',
    label: 'Savings & shares',
    hub: 'section-money',
    icon: WalletIcon,
    tone: 'from-emerald-500 to-emerald-700',
    intro: 'Money coming in and going out of members\' accounts.',
    items: [
      { name: 'deposit', label: 'Deposit', description: 'Cash or bank slips into savings or shares', icon: BanknotesIcon, needs: 'money', figure: (s) => `${formatMoney(s.deposits_month)} in ${month}` },
      { name: 'payroll', label: 'Payroll deductions', description: 'Upload the month\'s payroll file', icon: ArrowUpTrayIcon, also: ['payroll-batch'], figure: (s) => (s.last_payroll ? `Last: ${new Date(s.last_payroll + '-01').toLocaleDateString('en-GB', { month: 'long', year: 'numeric' })}` : 'None posted yet') },
      { name: 'transaction-import', label: 'Import transactions', description: 'Dated deposits, withdrawals and repayments from Excel', icon: DocumentArrowUpIcon, superOnly: true },
      { name: 'transfers', label: 'Internal transfers', description: 'Savings to shares, to a colleague, or to a loan', icon: ArrowsRightLeftIcon, figure: (s) => `${s.transfers_month} in ${month}` },
      { name: 'admin-withdrawals', label: 'Withdrawals', description: 'Requests to approve and cheques to issue', icon: InboxStackIcon, badge: (w) => w.withdrawals_to_approve + w.withdrawals_to_pay },
      { name: 'transactions', label: 'Transactions & reversals', description: 'Every entry, and putting mistakes right', icon: ArrowUturnLeftIcon, figure: (s) => `${s.transactions_month} entries in ${month}` },
    ],
  },
  {
    key: 'loans',
    label: 'Loans',
    hub: 'section-loans',
    icon: ClipboardDocumentCheckIcon,
    tone: 'from-saffron-400 to-saffron-600',
    intro: 'From application to the last repayment.',
    items: [
      { name: 'admin-loans', label: 'Loan applications', description: 'Check, approve or turn down', icon: ClipboardDocumentCheckIcon, also: ['loan'], badge: (w) => w.loans_to_approve, figure: (s) => `${s.waiting.loans_waiting_guarantors} waiting for guarantors` },
      { name: 'loan-disbursement', label: 'Loan disbursement', description: 'Approved loans to pay out', icon: CheckBadgeIcon, badge: (w) => w.loans_to_pay_out },
      { name: 'loan-interest', label: 'Monthly loan interest', description: 'Interest due, paid and owed each month', icon: CalculatorIcon, figure: (s) => `${formatMoney(s.interest_month)} due in ${month}` },
      { name: 'loan-portfolio', label: 'Loan portfolio', description: 'Every loan not yet repaid', icon: BriefcaseIcon, figure: (s) => `${s.active_loans} active · ${short(s.loans_outstanding)} owed` },
    ],
  },
  {
    key: 'books',
    label: 'Cash book',
    hub: 'section-books',
    icon: BookOpenIcon,
    tone: 'from-sky-500 to-sky-700',
    intro: 'The SACCO\'s own money: what it pays, what it receives, and the journal.',
    items: [
      { name: 'payments', label: 'Payments (expenses)', description: 'Stationery, bank charges, meetings…', icon: ArrowUpRightIcon, figure: (s) => `${formatMoney(s.payments_month)} in ${month}` },
      { name: 'receipts', label: 'Receipts (income)', description: 'Bank interest, grants and other income', icon: ArrowDownLeftIcon, figure: (s) => `${formatMoney(s.receipts_month)} in ${month}` },
      { name: 'journal', label: 'Journal entries', description: 'Every entry in the books; add your own', icon: BookOpenIcon, figure: (s) => `${s.journals_month} typed in this month` },
      { name: 'bank-rec', label: 'Bank reconciliation', description: 'Agree the books with the bank statement', icon: BuildingOffice2Icon, also: ['bank-rec-detail'] },
      { name: 'investments', label: 'Investments', description: 'Where the SACCO\'s money is invested', icon: ArrowTrendingUpIcon, figure: (s) => `${short(s.investments)} invested` },
      { name: 'dividends', label: 'Dividends & profit', description: 'Year-end payouts to members', icon: ReceiptPercentIcon, also: ['dividend-run'] },
    ],
  },
  {
    key: 'reports',
    label: 'Reports',
    hub: 'section-reports',
    icon: ChartBarIcon,
    tone: 'from-violet-500 to-violet-700',
    intro: 'Statements for the committee, the AGM and the auditors. Each downloads as Excel or PDF, or prints.',
    items: [
      { name: 'annual-report', label: 'Annual report', description: 'The year in one PDF for the AGM and auditors', icon: DocumentChartBarIcon, figure: (s) => `${short(s.income_year)} income this year` },
      { name: 'budget', label: 'Budget and actual', description: 'Planned income and spending against the real figures', icon: CalculatorIcon },
      { name: 'year-end', label: 'Year-end closing', description: 'Close a year and lock its books', icon: LockClosedIcon },
      { name: 'balance-sheet', label: 'Balance sheet', description: 'What the SACCO owns and owes', icon: BuildingLibraryIcon, figure: (s) => (s.balanced ? 'Balanced' : 'Not balanced: check') },
      { name: 'income-statement', label: 'Income statement', description: 'Income, expenses and the surplus', icon: PresentationChartLineIcon, figure: (s) => `${short(s.surplus_year)} surplus this year` },
      { name: 'trial-balance', label: 'Trial balance', description: 'Every account; debits equal credits', icon: ScaleIcon },
      { name: 'account-ledger', label: 'Account ledger', description: 'Every entry in one account', icon: TableCellsIcon },
      { name: 'chart', label: 'Chart of accounts', description: 'The accounts in the books', icon: ListBulletIcon, figure: (s) => `${s.accounts} accounts` },
      { name: 'member-ledger', label: 'Member ledger', description: 'One member\'s statement, ready to print', icon: DocumentTextIcon },
      { name: 'monthly-statements', label: 'Monthly statements', description: "Each member's month, to print or send", icon: EnvelopeIcon },
      { name: 'members-summary', label: 'Members summary', description: 'Every member\'s savings, shares and loans', icon: UserGroupIcon },
      { name: 'audit-report', label: 'Audit report', description: 'Who did what, when, from where', icon: ShieldCheckIcon },
      { name: 'reports', label: 'More reports', description: 'Arrears and the transactions journal', icon: PresentationChartLineIcon },
    ],
  },
  {
    key: 'admin',
    label: 'Settings',
    hub: 'section-admin',
    icon: Cog6ToothIcon,
    tone: 'from-slate-500 to-slate-700',
    intro: 'The SACCO\'s rules and keeping its data safe.',
    items: [
      { name: 'usage', label: 'Usage & security', description: 'Who uses the system, failed logins, two-step', icon: ShieldCheckIcon },
      { name: 'settings', label: 'Setup & rules', description: 'Rates, fees, shares, loan types', icon: Cog6ToothIcon },
      { name: 'committee', label: 'Committee decisions', description: 'Rules waiting for the committee, and what it decided', icon: ScaleIcon },
      { name: 'backup', label: 'Download backup', description: 'A copy of everything to keep safe', icon: CircleStackIcon, superOnly: true, figure: (s) => (s.last_backup ? `Last: ${new Date(s.last_backup.replace(' ', 'T')).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}` : 'Never downloaded') },
    ],
  },
]

export const dashboardIcon = Squares2X2Icon

/** The section a page belongs to (its hub, or one of its modules) */
export function sectionOf(routeName: string): Section | undefined {
  return sections.find((s) => s.hub === routeName || s.items.some((i) => i.name === routeName || i.also?.includes(routeName)))
}
