import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, setDevCsrfToken } from '@/api'

export interface Developer {
  id: number
  full_name: string
  email: string
  must_change_password: boolean
  last_login_at: string | null
}
interface DevSession {
  developer: Developer | null
  csrf_token: string | null
}

/** The developer logged in to the developer portal (separate from any SACCO login) */
export const useDev = defineStore('dev', () => {
  const developer = ref<Developer | null>(null)
  const loaded = ref(false)

  function apply(s: DevSession) {
    developer.value = s.developer
    setDevCsrfToken(s.csrf_token)
    loaded.value = true
  }

  async function load() {
    apply(await api.get<DevSession>('/dev/me'))
  }

  async function login(email: string, password: string) {
    apply(await api.post<DevSession>('/dev/login', { email, password }))
  }

  async function changePassword(current_password: string, new_password: string) {
    apply(await api.post<DevSession>('/dev/change-password', { current_password, new_password }))
  }

  async function logout() {
    try {
      await api.post('/dev/logout')
    } finally {
      developer.value = null
      setDevCsrfToken(null)
    }
  }

  return { developer, loaded, load, login, changePassword, logout }
})
