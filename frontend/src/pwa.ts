import { ref } from 'vue'

/**
 * The app on phones: the manifest and icon, the service worker, and the browser's offer to
 * install it (kept until someone presses Install).
 */
interface InstallEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

export const canInstall = ref(false)
export const installed = ref(window.matchMedia('(display-mode: standalone)').matches)
/** iPhones and iPads install from Safari's Share menu instead */
export const isIos = /iPhone|iPad|iPod/i.test(navigator.userAgent) && !installed.value

let offer: InstallEvent | null = null

export function setUpPwa() {
  const base = import.meta.env.BASE_URL
  const link = (rel: string, href: string) => {
    const el = document.createElement('link')
    el.rel = rel
    el.href = href
    document.head.appendChild(el)
  }
  link('manifest', `${base}api/public/manifest`)
  link('apple-touch-icon', `${base}brand/apple-touch-icon.png`)

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault()
    offer = e as InstallEvent
    canInstall.value = true
  })
  window.addEventListener('appinstalled', () => {
    installed.value = true
    canInstall.value = false
  })

  if (import.meta.env.PROD && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register(`${base}sw.js`, { scope: base }).catch(() => {
        // The app works the same without it
      })
    })
  }
}

export async function install() {
  if (!offer) return
  await offer.prompt()
  const choice = await offer.userChoice
  if (choice.outcome === 'accepted') installed.value = true
  offer = null
  canInstall.value = false
}
