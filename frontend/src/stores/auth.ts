import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, setCsrfToken } from '@/api'
import type { Sacco, Session, User } from '@/types'

export const useAuth = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const sacco = ref<Sacco | null>(null)
  const loaded = ref(false)

  const isSuperAdmin = computed(() => user.value?.role === 'super_admin')
  const isAdmin = computed(() => user.value?.role === 'admin' || isSuperAdmin.value)
  /** Whether this office account may make changes in an area (the super admin always may) */
  const can = (area: string) => isSuperAdmin.value || !!user.value?.permissions?.includes(area)
  /** An admin who can look and download but not change anything */
  const viewOnly = computed(() => user.value?.role === 'admin' && !(user.value.permissions?.length))

  function apply(session: Session) {
    user.value = session.user
    if (session.sacco) {
      useSacco(session.sacco)
    }
    setCsrfToken(session.csrf_token)
    loaded.value = true
  }

  /** Shows the SACCO's name and colour across the app */
  function useSacco(s: Sacco) {
    sacco.value = s
    document.title = s.short_name || s.name
    const hex = s.brand_color.replace('#', '')
    const rgb = [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16)).join(' ')
    document.documentElement.style.setProperty('--brand', rgb)
  }

  async function load() {
    apply(await api.get<Session>('/auth/me'))
  }

  /** Returns true when a two-step code is needed next */
  async function login(login: string, password: string, saccoCode?: string, remember = false) {
    const res = await api.post<Session & { two_step?: boolean }>('/auth/login', { login, password, sacco: saccoCode || undefined, remember })
    if (res.two_step) return true
    apply(res)
    return false
  }

  async function twoStep(code: string) {
    apply(await api.post<Session>('/auth/two-step', { code }))
  }

  async function changePassword(current_password: string, new_password: string) {
    apply(await api.post<Session>('/auth/change-password', { current_password, new_password }))
  }

  async function logout() {
    try {
      await api.post('/auth/logout')
    } finally {
      user.value = null
      setCsrfToken(null)
    }
  }

  /** The session ended on the server (timed out, account suspended) */
  function forget() {
    user.value = null
    setCsrfToken(null)
  }

  return { user, sacco, loaded, isAdmin, isSuperAdmin, can, viewOnly, load, login, twoStep, changePassword, logout, forget, useSacco }
})
