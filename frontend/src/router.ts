import { createRouter, createWebHistory } from 'vue-router'
import { useAuth } from '@/stores/auth'

declare module 'vue-router' {
  interface RouteMeta {
    /** Open to everyone (the login page) */
    public?: boolean
    /** Admins and super admins only */
    admin?: boolean
    /** A member's own money: office accounts (no savings or loans of their own) go to the overview */
    member?: boolean
    title?: string
  }
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/login', name: 'login', component: () => import('@/pages/LoginPage.vue'), meta: { public: true } },
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
        { path: 'office', name: 'overview', component: () => import('@/pages/admin/OverviewPage.vue'), meta: { admin: true, title: 'Overview' } },
        { path: 'members/import', name: 'member-import', component: () => import('@/pages/admin/MemberImportPage.vue'), meta: { admin: true, title: 'Import members' } },
        { path: 'profile', name: 'profile', component: () => import('@/pages/ProfilePage.vue'), meta: { member: true, title: 'My profile' } },
        { path: 'admin/cashbook', name: 'cashbook', component: () => import('@/pages/admin/CashbookPage.vue'), meta: { admin: true, title: 'Income & expenses' } },
        { path: 'admin/notices', name: 'notices', component: () => import('@/pages/admin/NoticesPage.vue'), meta: { admin: true, title: 'Notices' } },
        { path: 'statement', name: 'statement', component: () => import('@/pages/StatementPage.vue'), meta: { member: true, title: 'My statement' } },
        { path: 'loans', name: 'loans', component: () => import('@/pages/loans/LoansPage.vue'), meta: { member: true, title: 'My loans' } },
        { path: 'loans/apply', name: 'loan-apply', component: () => import('@/pages/loans/ApplyLoanPage.vue'), meta: { member: true, title: 'Apply for a loan' } },
        { path: 'loans/:id(\\d+)', name: 'loan', component: () => import('@/pages/loans/LoanPage.vue'), meta: { title: 'Loan' } },
        { path: 'admin/loans', name: 'admin-loans', component: () => import('@/pages/admin/LoansAdminPage.vue'), meta: { admin: true, title: 'Loan applications' } },
        { path: 'withdrawals', name: 'withdrawals', component: () => import('@/pages/withdrawals/WithdrawalsPage.vue'), meta: { member: true, title: 'Withdraw savings' } },
        { path: 'admin/withdrawals', name: 'admin-withdrawals', component: () => import('@/pages/admin/WithdrawalsAdminPage.vue'), meta: { admin: true, title: 'Withdrawal requests' } },
        { path: 'admin/payroll', name: 'payroll', component: () => import('@/pages/admin/PayrollPage.vue'), meta: { admin: true, title: 'Payroll uploads' } },
        { path: 'admin/payroll/:id(\\d+)', name: 'payroll-batch', component: () => import('@/pages/admin/PayrollBatchPage.vue'), meta: { admin: true, title: 'Payroll upload' } },
        { path: 'admin/investments', name: 'investments', component: () => import('@/pages/admin/InvestmentsPage.vue'), meta: { admin: true, title: 'Investments' } },
        { path: 'admin/dividends', name: 'dividends', component: () => import('@/pages/admin/DividendsPage.vue'), meta: { admin: true, title: 'Dividends' } },
        { path: 'admin/dividends/:id(\\d+)', name: 'dividend-run', component: () => import('@/pages/admin/DividendRunPage.vue'), meta: { admin: true, title: 'Dividend' } },
        { path: 'admin/reports', name: 'reports', component: () => import('@/pages/admin/ReportsPage.vue'), meta: { admin: true, title: 'Reports' } },
        { path: 'admin/settings', name: 'settings', component: () => import('@/pages/admin/SettingsPage.vue'), meta: { admin: true, title: 'SACCO rules' } },
        { path: 'users', name: 'users', component: () => import('@/pages/admin/UsersPage.vue'), meta: { admin: true, title: 'Members' } },
        { path: 'members/:id(\\d+)', name: 'member', component: () => import('@/pages/admin/MemberPage.vue'), meta: { admin: true, title: 'Member' } },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuth()
  if (!auth.loaded) {
    try {
      await auth.load()
    } catch {
      // The API is unreachable; the login page shows the error when they try
    }
  }

  if (to.meta.public) {
    return auth.user ? { name: auth.isAdmin ? 'overview' : 'dashboard' } : true
  }
  if (!auth.user) {
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

export default router
