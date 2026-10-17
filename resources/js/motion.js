// Animations on or off (Settings → Appearance → Animations), kept in this browser like the theme.
// <html data-motion="on|off"> drives app.css: "off" makes every animation and transition instant.
// Without a choice the system decides: a device asking for reduced motion starts with them off.
// The same logic runs inline in partials/head before the first paint; this module keeps the
// attribute through wire:navigate (Livewire copies the new page's <html> attributes over ours).
const KEY = 'motion'

const systemReducesMotion = () => {
    try {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches
    } catch (e) {
        return false
    }
}

export function motionEnabled() {
    let stored = null

    try {
        stored = localStorage.getItem(KEY)
    } catch (e) {}

    return stored === 'on' || (stored !== 'off' && ! systemReducesMotion())
}

export function applyMotion() {
    document.documentElement.dataset.motion = motionEnabled() ? 'on' : 'off'
}

export function setMotion(enabled) {
    try {
        localStorage.setItem(KEY, enabled ? 'on' : 'off')
    } catch (e) {}

    applyMotion()
}

// Elements rendered with the page do not "appear"; only what a later update inserts does
// (a tick after tapping a fixed item), so a page load or a background refresh never animates them.
let settledAt = 0

const pageStarted = () => {
    settledAt = performance.now() + 600
}

export function registerMotion(Alpine) {
    // x-appear: a short pop when the element is inserted after the page has settled.
    Alpine.directive('appear', (el) => {
        if (performance.now() > settledAt) {
            el.classList.add('motion-appear')
        }
    })

    // x-number-change: the main figures fade up gently when their text changes, never on a
    // re-render that leaves the number as it was. Only opacity and transform: no layout shift.
    Alpine.directive('number-change', (el, _directive, { cleanup }) => {
        let text = el.textContent
        const observer = new MutationObserver(() => {
            if (el.textContent === text) {
                return
            }
            text = el.textContent
            el.classList.remove('motion-number')
            void el.offsetWidth
            el.classList.add('motion-number')
        })
        observer.observe(el, { characterData: true, childList: true, subtree: true })
        cleanup(() => observer.disconnect())
    })
}

export function listenForMotion() {
    pageStarted()
    applyMotion()

    document.addEventListener('livewire:navigating', (event) => event.detail?.onSwap?.(applyMotion))
    document.addEventListener('livewire:navigated', () => {
        applyMotion()
        pageStarted()
    })

    try {
        window.matchMedia('(prefers-reduced-motion: reduce)').addEventListener('change', applyMotion)
    } catch (e) {}
}
