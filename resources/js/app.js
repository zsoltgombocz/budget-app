import { registerNumpad } from './numpad.js'
import { install, markStandalone, push, registerServiceWorker } from './pwa.js'
import { lockScroll, unlockScroll } from './scroll-lock.js'

markStandalone()
registerServiceWorker()
install.listen()

window.budgetPush = push
window.budgetInstall = install
window.appScrollLock = { lock: lockScroll, unlock: unlockScroll }

// The app's confirmation dialog (components/confirm-dialog): resolves true on confirm.
// Options: title, body, confirm (button label), danger; optionally highlight (one emphasised
// line, e.g. the exchange rate) and note (smaller muted text under it).
window.appConfirm = (options) => new Promise((resolve) => {
    window.dispatchEvent(new CustomEvent('app-confirm', { detail: { ...options, resolve } }))
})

document.addEventListener('alpine:init', () => registerNumpad(window.Alpine))

window.addEventListener('load', () => push.sync())

// Immediate navigation feedback for wire:navigate (Livewire's own bar shows up late).
document.addEventListener('livewire:navigate', () => document.documentElement.classList.add('navigating'))
document.addEventListener('livewire:navigated', () => document.documentElement.classList.remove('navigating'))

// A server round trip that takes a while (opening a sheet, saving) shows the same top bar, so a
// slow tap never looks like nothing happened. Background refreshes (wire:poll) stay silent.
const BUSY_AFTER_MS = 600
let busyRequests = 0
let busyTimer = null

document.addEventListener('livewire:init', () => {
    window.Livewire.interceptRequest(({ request, onSend, onCancel, onFinish }) => {
        const actions = [...request.messages].flatMap((message) => [...message.actions])
        if (actions.length === 0 || actions.every((action) => action.name === '$refresh')) return

        let pending = false
        const done = () => {
            if (! pending) return
            pending = false
            busyRequests = Math.max(0, busyRequests - 1)
            if (busyRequests > 0) return
            clearTimeout(busyTimer)
            busyTimer = null
            document.documentElement.classList.remove('busy')
        }

        onSend(() => {
            pending = true
            busyRequests++
            busyTimer ??= setTimeout(() => document.documentElement.classList.add('busy'), BUSY_AFTER_MS)
        })
        onCancel(done)
        onFinish(done)
    })
})
