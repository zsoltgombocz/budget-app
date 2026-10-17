import { registerBusyLock } from './busy.js'
import { registerDialog } from './dialog.js'
import { listenForMotion, motionEnabled, registerMotion, setMotion } from './motion.js'
import { amountFields, formatAmount, numpadKeydown, registerNumpad } from './numpad.js'
import { install, markStandalone, push, registerServiceWorker } from './pwa.js'
import { lockScroll, unlockScroll } from './scroll-lock.js'
import { registerTour } from './tour.js'

markStandalone()
listenForMotion()
registerServiceWorker()
install.listen()

window.budgetPush = push
window.budgetInstall = install
window.appScrollLock = { lock: lockScroll, unlock: unlockScroll }
window.amountFields = amountFields
window.formatAmount = formatAmount
window.numpadKeydown = numpadKeydown
window.appMotion = { enabled: motionEnabled, set: setMotion }

// The app's confirmation dialog (components/confirm-dialog): resolves true on confirm.
// Options: title, body, confirm (button label), danger, icon (tile glyph); optionally highlight (one emphasised
// line, e.g. the exchange rate) and note (smaller muted text under it).
window.appConfirm = (options) => new Promise((resolve) => {
    window.dispatchEvent(new CustomEvent('app-confirm', { detail: { ...options, resolve } }))
})

document.addEventListener('alpine:init', () => {
    registerNumpad(window.Alpine)
    registerDialog(window.Alpine)
    registerMotion(window.Alpine)
    registerTour(window.Alpine)
})
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
