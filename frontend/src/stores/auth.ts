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

  async function login(login: string, password: string, saccoCode?: string) {
    apply(await api.post<Session>('/auth/login', { login, password, sacco: saccoCode || undefined }))
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

  return { user, sacco, loaded, isAdmin, isSuperAdmin, load, login, changePassword, logout, forget, useSacco }
})
