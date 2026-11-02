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
