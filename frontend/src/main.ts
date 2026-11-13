import { createApp } from 'vue'
import { createPinia } from 'pinia'
// Inter: sharp on phone screens, with figures that line up in columns (one variable file, every weight)
import '@fontsource-variable/inter/opsz.css'
import './style.css'
import App from './App.vue'
import router from './router'
import { handleAuthProblems } from './api'
import { installMotion } from './motion'
import { setUpPwa } from './pwa'
import { useAuth } from './stores/auth'
import { useTheme } from './stores/theme'

const app = createApp(App)
app.use(createPinia())
app.use(router)

/**
 * v-can="'money'": hides a change button from office accounts not allowed in that area (the
 * server refuses them anyway). Members acting on their own account always see their buttons.
 */
const toggleCan = (el: HTMLElement, area: string) => {
  const auth = useAuth()
  el.style.display = !auth.user || auth.user.role === 'member' || auth.can(area) ? '' : 'none'
}
app.directive<HTMLElement, string>('can', { mounted: (el, b) => toggleCan(el, b.value), updated: (el, b) => toggleCan(el, b.value) })

// Light, dark or follow the device (already applied before the page drew; this keeps it in step)
useTheme()

// The server ended the session, or wants the temporary password changed: go to the right page
handleAuthProblems((status, code) => {
  const auth = useAuth()
  if (code === 'must_change_password') {
    router.push({ name: 'change-password' })
  } else if (status === 401 || status === 419) {
    auth.forget()
    router.push({ name: 'login' })
  }
})

installMotion(router)
setUpPwa()
app.mount('#app')
