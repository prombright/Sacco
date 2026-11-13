import { createRouter, createWebHistory } from 'vue-router'
import { useAuth } from '@/stores/auth'
import { useDev } from '@/stores/dev'

declare module 'vue-router' {
  interface RouteMeta {
    /** Open to everyone (the login page) */
    public?: boolean
    /** Admins and super admins only */
    admin?: boolean
    /** A member's own money: office accounts (no savings or loans of their own) go to the overview */
    member?: boolean
    title?: string
    /** Which report or kind of entry a shared page shows */
    report?: string
    kind?: string
    loanStatus?: string
    /** The developer portal: its own sign-in, separate from any SACCO's */
    dev?: boolean
  }
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior: (_to, _from, saved) => saved ?? { top: 0 },
  routes: [
    // The SACCO's public homepage: anyone can read it, signed in or not
    { path: '/welcome', name: 'home', component: () => import('@/pages/HomePage.vue'), meta: { public: true, title: 'Welcome' } },
    { path: '/login', name: 'login', component: () => import('@/pages/LoginPage.vue'), meta: { public: true } },
    { path: '/forgot-password', name: 'forgot-password', component: () => import('@/pages/ForgotPasswordPage.vue'), meta: { public: true, title: 'Forgot password' } },
    { path: '/reset-password', name: 'reset-password', component: () => import('@/pages/ResetPasswordPage.vue'), meta: { public: true, title: 'Choose a new password' } },
    { path: '/change-password', name: 'change-password', component: () => import('@/pages/ChangePasswordPage.vue') },
    // Printable, so outside the app's header and menus
    { path: '/withdrawals/:id(\\d+)/slip', name: 'withdrawal-slip', component: () => import('@/pages/withdrawals/WithdrawalSlip.vue') },
    { path: '/statement/print', name: 'statement-print', component: () => import('@/pages/StatementPrintPage.vue'), meta: { member: true } },
    { path: '/members/:id(\\d+)/statement', name: 'member-statement', component: () => import('@/pages/StatementPrintPage.vue'), meta: { admin: true } },
    {
      path: '/',
      component: () => import('@/layouts/AppLayout.vue'),
      children: [
        { path: '', name: 'dashboard', component: () => import('@/pages/DashboardPage.vue'), meta: { member: true, title: 'My dashboard' } },
        { path: 'office', name: 'overview', component: () => import('@/pages/admin/OverviewPage.vue'), meta: { admin: true, title: 'Dashboard' } },
        { path: 'members/import-old', name: 'old-system-import', component: () => import('@/pages/admin/OldSystemImportPage.vue'), meta: { admin: true, title: 'Bring in the old system' } },
        { path: 'members/import', name: 'member-import', component: () => import('@/pages/admin/MemberImportPage.vue'), meta: { admin: true, title: 'Import members' } },
        { path: 'messages', name: 'messages', component: () => import('@/pages/MessagesPage.vue'), meta: { title: 'Messages' } },
        { path: 'admin/committee', name: 'committee', component: () => import('@/pages/admin/CommitteePage.vue'), meta: { admin: true, title: 'Committee decisions' } },
        { path: 'help', name: 'help', component: () => import('@/pages/HelpPage.vue'), meta: { title: 'Help' } },
        { path: 'profile', name: 'profile', component: () => import('@/pages/ProfilePage.vue'), meta: { member: true, title: 'My profile' } },
        { path: 'admin/cashbook', name: 'cashbook', redirect: { name: 'payments' } },
        { path: 'admin/notices', name: 'notices', component: () => import('@/pages/admin/NoticesPage.vue'), meta: { admin: true, title: 'Notices' } },
        { path: 'statement', name: 'statement', component: () => import('@/pages/StatementPage.vue'), meta: { member: true, title: 'My statement' } },
        { path: 'loans', name: 'loans', component: () => import('@/pages/loans/LoansPage.vue'), meta: { member: true, title: 'My loans' } },
        { path: 'loans/apply', name: 'loan-apply', component: () => import('@/pages/loans/ApplyLoanPage.vue'), meta: { member: true, title: 'Apply for a loan' } },
        { path: 'loans/:id(\\d+)', name: 'loan', component: () => import('@/pages/loans/LoanPage.vue'), meta: { title: 'Loan' } },
        { path: 'admin/loans', name: 'admin-loans', component: () => import('@/pages/admin/LoansAdminPage.vue'), meta: { admin: true, title: 'Loan applications' } },
        { path: 'withdrawals', name: 'withdrawals', component: () => import('@/pages/withdrawals/WithdrawalsPage.vue'), meta: { member: true, title: 'Withdraw savings' } },
        { path: 'admin/withdrawals', name: 'admin-withdrawals', component: () => import('@/pages/admin/WithdrawalsAdminPage.vue'), meta: { admin: true, title: 'Withdrawal requests' } },
        { path: 'admin/transaction-import', name: 'transaction-import', component: () => import('@/pages/admin/TransactionImportPage.vue'), meta: { admin: true, title: 'Import transactions' } },
        { path: 'admin/payroll', name: 'payroll', component: () => import('@/pages/admin/PayrollPage.vue'), meta: { admin: true, title: 'Payroll uploads' } },
        { path: 'admin/payroll/:id(\\d+)', name: 'payroll-batch', component: () => import('@/pages/admin/PayrollBatchPage.vue'), meta: { admin: true, title: 'Payroll upload' } },
        { path: 'admin/investments', name: 'investments', component: () => import('@/pages/admin/InvestmentsPage.vue'), meta: { admin: true, title: 'Investments' } },
        { path: 'admin/dividends', name: 'dividends', component: () => import('@/pages/admin/DividendsPage.vue'), meta: { admin: true, title: 'Dividends' } },
        { path: 'admin/dividends/:id(\\d+)', name: 'dividend-run', component: () => import('@/pages/admin/DividendRunPage.vue'), meta: { admin: true, title: 'Dividend' } },
        { path: 'admin/reports', name: 'reports', component: () => import('@/pages/admin/ReportsPage.vue'), meta: { admin: true, title: 'Reports' } },
        { path: 'admin/settings', name: 'settings', component: () => import('@/pages/admin/SettingsPage.vue'), meta: { admin: true, title: 'Setup & rules' } },
        { path: 'users', name: 'users', component: () => import('@/pages/admin/UsersPage.vue'), meta: { admin: true, title: 'Members' } },
        { path: 'members/:id(\\d+)', name: 'member', component: () => import('@/pages/admin/MemberPage.vue'), meta: { admin: true, title: 'Member' } },
        // Each section of the office menu opens on its own dashboard
        { path: 'office/members', name: 'section-members', component: () => import('@/pages/admin/SectionPage.vue'), meta: { admin: true, title: 'Members' } },
        { path: 'office/savings', name: 'section-money', component: () => import('@/pages/admin/SectionPage.vue'), meta: { admin: true, title: 'Savings & shares' } },
        { path: 'office/loans', name: 'section-loans', component: () => import('@/pages/admin/SectionPage.vue'), meta: { admin: true, title: 'Loans' } },
        { path: 'office/cash-book', name: 'section-books', component: () => import('@/pages/admin/SectionPage.vue'), meta: { admin: true, title: 'Cash book' } },
        { path: 'office/reports', name: 'section-reports', component: () => import('@/pages/admin/SectionPage.vue'), meta: { admin: true, title: 'Reports' } },
        { path: 'office/settings', name: 'section-admin', component: () => import('@/pages/admin/SectionPage.vue'), meta: { admin: true, title: 'Settings' } },
        { path: 'admin/membership-fees', name: 'membership-fees', component: () => import('@/pages/admin/MembershipFeesPage.vue'), meta: { admin: true, title: 'Membership fees' } },
        // Members' money
        { path: 'admin/deposit', name: 'deposit', component: () => import('@/pages/admin/DepositPage.vue'), meta: { admin: true, title: 'Deposit' } },
        { path: 'admin/transfers', name: 'transfers', component: () => import('@/pages/admin/TransfersPage.vue'), meta: { admin: true, title: 'Internal transfers' } },
        { path: 'admin/loan-disbursement', name: 'loan-disbursement', component: () => import('@/pages/admin/LoansAdminPage.vue'), meta: { admin: true, title: 'Loan disbursement', loanStatus: 'approved' } },
        // Cash book
        { path: 'cashbook/payments', name: 'payments', component: () => import('@/pages/accounts/CashbookEntriesPage.vue'), meta: { admin: true, title: 'Payments (expenses)', kind: 'expense' } },
        { path: 'cashbook/receipts', name: 'receipts', component: () => import('@/pages/accounts/CashbookEntriesPage.vue'), meta: { admin: true, title: 'Receipts (income)', kind: 'income' } },
        { path: 'accounts/journal', name: 'journal', component: () => import('@/pages/accounts/JournalPage.vue'), meta: { admin: true, title: 'Journal entries' } },
        // Member reports
        { path: 'reports/member-ledger', name: 'member-ledger', component: () => import('@/pages/accounts/MemberLedgerPage.vue'), meta: { admin: true, title: 'Member ledger' } },
        { path: 'reports/members-summary', name: 'members-summary', component: () => import('@/pages/admin/ReportsPage.vue'), meta: { admin: true, title: 'Members summary', report: 'members' } },
        { path: 'reports/loan-portfolio', name: 'loan-portfolio', component: () => import('@/pages/admin/ReportsPage.vue'), meta: { admin: true, title: 'Loan portfolio', report: 'loans' } },
        // Financial reports
        { path: 'accounts/trial-balance', name: 'trial-balance', component: () => import('@/pages/accounts/FinancialReportPage.vue'), meta: { admin: true, title: 'Trial balance', report: 'trial_balance' } },
        { path: 'accounts/balance-sheet', name: 'balance-sheet', component: () => import('@/pages/accounts/FinancialReportPage.vue'), meta: { admin: true, title: 'Balance sheet', report: 'balance_sheet' } },
        { path: 'accounts/income-statement', name: 'income-statement', component: () => import('@/pages/accounts/FinancialReportPage.vue'), meta: { admin: true, title: 'Income statement', report: 'income_statement' } },
        { path: 'accounts/ledger', name: 'account-ledger', component: () => import('@/pages/accounts/FinancialReportPage.vue'), meta: { admin: true, title: 'Account ledger', report: 'account_ledger' } },
        { path: 'reports/year-end', name: 'year-end', component: () => import('@/pages/accounts/YearEndPage.vue'), meta: { admin: true, title: 'Year-end closing' } },
        { path: 'reports/budget', name: 'budget', component: () => import('@/pages/accounts/BudgetPage.vue'), meta: { admin: true, title: 'Budget and actual' } },
        { path: 'reports/annual', name: 'annual-report', component: () => import('@/pages/accounts/AnnualReportPage.vue'), meta: { admin: true, title: 'Annual report' } },
        { path: 'reports/audit', name: 'audit-report', component: () => import('@/pages/admin/ReportsPage.vue'), meta: { admin: true, title: 'Audit report', report: 'audit' } },
        { path: 'accounts/chart', name: 'chart', component: () => import('@/pages/accounts/ChartOfAccountsPage.vue'), meta: { admin: true, title: 'Chart of accounts' } },
        { path: 'accounts/bank-reconciliation', name: 'bank-rec', component: () => import('@/pages/accounts/BankRecPage.vue'), meta: { admin: true, title: 'Bank reconciliation' } },
        { path: 'accounts/bank-reconciliation/:id(\\d+)', name: 'bank-rec-detail', component: () => import('@/pages/accounts/BankRecPage.vue'), meta: { admin: true, title: 'Bank reconciliation' } },
        { path: 'receipts', name: 'receipts-mine', component: () => import('@/pages/ReceiptsPage.vue'), meta: { member: true, title: 'My receipts' } },
        { path: 'members/:id(\\d+)/receipts', name: 'member-receipts', component: () => import('@/pages/ReceiptsPage.vue'), meta: { admin: true, title: 'Receipts' } },
        { path: 'admin/usage', name: 'usage', component: () => import('@/pages/admin/UsagePage.vue'), meta: { admin: true, title: 'Usage & security' } },
        // Administration
        { path: 'admin/loan-interest', name: 'loan-interest', component: () => import('@/pages/accounts/FinancialReportPage.vue'), meta: { admin: true, title: 'Monthly loan interest', report: 'loan_interest' } },
        { path: 'admin/transactions', name: 'transactions', component: () => import('@/pages/admin/TransactionsPage.vue'), meta: { admin: true, title: 'Transactions & reversals' } },
        { path: 'admin/backup', name: 'backup', component: () => import('@/pages/admin/BackupPage.vue'), meta: { admin: true, title: 'Download backup' } },
      ],
    },
    // The developer portal
    { path: '/devportal/login', name: 'dev-login', component: () => import('@/pages/dev/DevLoginPage.vue'), meta: { dev: true, public: true } },
    {
      path: '/devportal',
      component: () => import('@/layouts/DevLayout.vue'),
      meta: { dev: true },
      children: [
        { path: '', name: 'dev-home', component: () => import('@/pages/dev/DevHomePage.vue'), meta: { dev: true } },
        { path: 'saccos/:id(\\d+)', name: 'dev-sacco', component: () => import('@/pages/dev/DevSaccoPage.vue'), meta: { dev: true } },
        { path: 'log', name: 'dev-log', component: () => import('@/pages/dev/DevLogPage.vue'), meta: { dev: true } },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  if (to.meta.dev) {
    const dev = useDev()
    if (!dev.loaded) await dev.load().catch(() => {})
    document.title = 'Developer portal'
    if (to.name === 'dev-login') return dev.developer && !dev.developer.must_change_password ? { name: 'dev-home' } : true
    return dev.developer && !dev.developer.must_change_password ? true : { name: 'dev-login' }
  }
  const auth = useAuth()
  if (!auth.loaded) {
    try {
      await auth.load()
    } catch {
      // The API is unreachable; the login page shows the error when they try
    }
  }

  if (to.name === 'home') return true
  if (to.meta.public) {
    return auth.user ? { name: auth.isAdmin ? 'overview' : 'dashboard' } : true
  }
  if (!auth.user) {
    // Visitors to the front door see the homepage; deeper links go to sign in and come back
    if (to.fullPath === '/') return { name: 'home' }
    return { name: 'login', query: to.fullPath !== '/' ? { next: to.fullPath } : {} }
  }
  // A temporary password must be replaced before anything else
  if (auth.user.must_change_password) {
    return to.name === 'change-password' ? true : { name: 'change-password' }
  }
  if (to.meta.admin && !auth.isAdmin) {
    return { name: 'dashboard' }
  }
  if (to.meta.member && auth.isAdmin) {
    return { name: 'overview' }
  }
  return true
})

router.afterEach((to) => {
  if (to.meta.dev) return
  const name = useAuth().sacco?.short_name || useAuth().sacco?.name || 'SACCO'
  document.title = to.meta.title ? `${to.meta.title} · ${name}` : name
})

// After an update the old page's files are gone: load the new version instead of failing
router.onError((error, to) => {
  if (/dynamically imported module|Importing a module script|Failed to fetch/i.test(String(error?.message)) && !sessionStorage.getItem('sacco-reloaded')) {
    sessionStorage.setItem('sacco-reloaded', '1')
    window.location.assign(import.meta.env.BASE_URL + to.fullPath.replace(/^\//, ''))
  }
})

export default router
