import { registerBusyLock } from './busy.js'
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
// Livewire may already be running when this module executes (modules are deferred).
if (window.Livewire) {
    registerBusyLock(window.Livewire)
} else {
    document.addEventListener('livewire:init', () => registerBusyLock(window.Livewire))
}

window.addEventListener('load', () => push.sync())

// Immediate navigation feedback for wire:navigate (Livewire's own bar shows up late).
document.addEventListener('livewire:navigate', () => document.documentElement.classList.add('navigating'))
document.addEventListener('livewire:navigated', () => document.documentElement.classList.remove('navigating'))
