/**
 * Service worker registration, cache hygiene and Web Push subscription.
 */
const isAuthenticated = () => document.querySelector('meta[name="vapid-public-key"]') !== null

export function registerServiceWorker() {
    if (! ('serviceWorker' in navigator)) return

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {})
    })

    // Signed out (or session expired): drop cached personal pages.
    if (! isAuthenticated() && 'caches' in window) {
        caches.delete('pages')
    }

    document.addEventListener('submit', (event) => {
        if (event.target instanceof HTMLFormElement && event.target.action.endsWith('/logout') && 'caches' in window) {
            caches.delete('pages')
        }
    })
}

function urlBase64ToUint8Array(base64) {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4)
    const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'))
    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)))
}

function meta(name) {
    return document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') ?? ''
}

async function send(method, subscription) {
    const response = await fetch(meta('push-subscription-url'), {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': meta('csrf-token'),
        },
        body: JSON.stringify(method === 'DELETE' ? { endpoint: subscription.endpoint } : subscription.toJSON()),
    })

    if (! response.ok) throw new Error(`Push subscription request failed: ${response.status}`)
}

export const push = {
    supported() {
        return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
    },

    standalone() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true
    },

    permission() {
        return 'Notification' in window ? Notification.permission : 'unsupported'
    },

    async current() {
        if (! this.supported()) return null
        const registration = await navigator.serviceWorker.ready
        return registration.pushManager.getSubscription()
    },

    async subscribe() {
        if (! this.supported()) return false

        const permission = await Notification.requestPermission()
        if (permission !== 'granted') return false

        const registration = await navigator.serviceWorker.ready
        const subscription = (await registration.pushManager.getSubscription())
            ?? await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(meta('vapid-public-key')),
            })

        await send('POST', subscription)
        return true
    },

    async unsubscribe() {
        const subscription = await this.current()
        if (! subscription) return
        await send('DELETE', subscription)
        await subscription.unsubscribe()
    },

    /** Keep the server in sync when the browser rotated the subscription. */
    async sync() {
        if (! isAuthenticated() || this.permission() !== 'granted') return
        const subscription = await this.current()
        if (subscription) await send('POST', subscription).catch(() => {})
    },
}
