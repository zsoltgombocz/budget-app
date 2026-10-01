/**
 * Service worker registration, cache hygiene and Web Push subscription.
 */
const isAuthenticated = () => document.querySelector('meta[name="vapid-public-key"]') !== null

/**
 * Mark the installed iPhone app so CSS can keep content below the status bar.
 */
export function markStandalone() {
    const ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
    const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true

    if (ios && standalone) document.documentElement.classList.add('ios-standalone')
}

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

/**
 * Install prompt: Chrome/Android fire beforeinstallprompt; keep it so the app can show
 * its own install button. iOS never fires it, the UI shows Share → Add to Home Screen instead.
 */
export const install = {
    deferred: null,

    listen() {
        window.addEventListener('beforeinstallprompt', (event) => {
            event.preventDefault()
            this.deferred = event
            window.dispatchEvent(new CustomEvent('install-available'))
        })

        window.addEventListener('appinstalled', () => {
            this.deferred = null
            window.dispatchEvent(new CustomEvent('install-done'))
        })
    },

    available() {
        return this.deferred !== null
    },

    ios() {
        return /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
    },

    /**
     * Which install route applies to this phone and browser.
     *  - prompt:         the browser offered a native install prompt
     *  - ios-safari:     Share → Add to Home Screen
     *  - ios-other:      Chrome/Firefox/Edge/in-app on iOS (Share → Add to Home Screen from 16.4, or open in Safari)
     *  - android-chrome: Chrome menu → Install app (prompt not offered yet)
     *  - android-other:  Firefox, Samsung or an in-app browser → open in Chrome
     *  - none:           desktop without a prompt, or already installed
     */
    platform() {
        const ua = navigator.userAgent
        const inApp = /FBAN|FBAV|Instagram|Line\/|MicroMessenger|Messenger|TikTok|Snapchat|Twitter/i.test(ua)

        if (this.installed()) return { route: 'none', browser: 'standalone' }
        if (this.available()) return { route: 'prompt', browser: 'native' }

        if (this.ios()) {
            const version = parseFloat((ua.match(/OS (\d+)_(\d+)/) || []).slice(1).join('.')) || 0
            const browser = inApp ? 'inapp' : /CriOS/.test(ua) ? 'chrome' : /FxiOS/.test(ua) ? 'firefox' : /EdgiOS/.test(ua) ? 'edge' : 'safari'
            return { route: browser === 'safari' ? 'ios-safari' : 'ios-other', browser, version }
        }

        if (/Android/i.test(ua)) {
            const browser = inApp ? 'inapp' : /SamsungBrowser/.test(ua) ? 'samsung' : /Firefox/.test(ua) ? 'firefox' : /EdgA/.test(ua) ? 'edge' : /Chrome/.test(ua) ? 'chrome' : 'other'
            return { route: browser === 'chrome' ? 'android-chrome' : 'android-other', browser }
        }

        return { route: 'none', browser: 'desktop' }
    },

    /** Link that reopens the current origin in Safari (iOS 17+). */
    safariUrl(path = '/login') {
        return 'x-safari-' + location.origin + path
    },

    /** Android intent link that reopens the page in Chrome. */
    chromeUrl(path = '/login') {
        return 'intent://' + location.host + path + '#Intent;scheme=https;package=com.android.chrome;S.browser_fallback_url=' + encodeURIComponent(location.origin + path) + ';end'
    },

    installed() {
        return push.standalone()
    },

    async prompt() {
        if (! this.deferred) return false
        this.deferred.prompt()
        const { outcome } = await this.deferred.userChoice
        this.deferred = null
        return outcome === 'accepted'
    },
}
