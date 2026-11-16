// Service worker registration, the "new version" prompt, and the install
// prompt that Chrome and Android offer.
import { ref } from 'vue'
import { registerSW } from 'virtual:pwa-register'

// Set when the browser offers to install the app (Chrome, Edge, Android).
export const installPromptEvent = ref(null)
export const isStandalone = ref(
  typeof window !== 'undefined' && (
    window.matchMedia?.('(display-mode: standalone)').matches ||
    window.navigator.standalone === true
  )
)

// Toasts mount with the app, which can be a moment after the worker reports.
function whenToastReady(fn, tries = 0) {
  if (window.$toast) {
    fn()
  } else if (tries < 50) {
    setTimeout(() => whenToastReady(fn, tries + 1), 200)
  }
}

export function setupPwa() {
  if (typeof window === 'undefined') return

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault()
    installPromptEvent.value = event
  })

  window.addEventListener('appinstalled', () => {
    installPromptEvent.value = null
    isStandalone.value = true
  })

  if (!('serviceWorker' in navigator)) return

  const updateSW = registerSW({
    immediate: true,
    onNeedRefresh() {
      whenToastReady(() => {
        window.$toast.info('A new version of Sunwise is ready.', {
          duration: 0,
          action: { label: 'Reload', onClick: () => updateSW(true) }
        })
      })
    },
    onRegisteredSW(swUrl, registration) {
      // Check for a new version every hour while the app stays open.
      if (registration) {
        setInterval(() => {
          if (navigator.onLine) registration.update().catch(() => {})
        }, 60 * 60 * 1000)
      }
    },
    onRegisterError(error) {
      console.error('Service worker registration failed:', error)
    }
  })
}

/** Show the browser's install dialog. Returns true if the user accepted. */
export async function promptInstall() {
  const event = installPromptEvent.value
  if (!event) return false
  event.prompt()
  const choice = await event.userChoice
  installPromptEvent.value = null
  return choice?.outcome === 'accepted'
}
