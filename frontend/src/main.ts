import { createApp } from 'vue'
import { createPinia } from 'pinia'
import '@fontsource/inter/400.css'
import '@fontsource/inter/500.css'
import '@fontsource/inter/600.css'
import './style.css'
import App from './App.vue'
import router from './router'
import { handleAuthProblems } from './api'
import { useAuth } from './stores/auth'

const app = createApp(App)
app.use(createPinia())
app.use(router)

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

app.mount('#app')
