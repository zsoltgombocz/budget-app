import { registerNumpad } from './numpad.js'
import { install, markStandalone, push, registerServiceWorker } from './pwa.js'

markStandalone()
registerServiceWorker()
install.listen()

window.budgetPush = push
window.budgetInstall = install

// The app's confirmation dialog (components/confirm-dialog): resolves true on confirm.
window.appConfirm = (options) => new Promise((resolve) => {
    window.dispatchEvent(new CustomEvent('app-confirm', { detail: { ...options, resolve } }))
})

document.addEventListener('alpine:init', () => registerNumpad(window.Alpine))

window.addEventListener('load', () => push.sync())

// Immediate navigation feedback for wire:navigate (Livewire's own bar shows up late).
document.addEventListener('livewire:navigate', () => document.documentElement.classList.add('navigating'))
document.addEventListener('livewire:navigated', () => document.documentElement.classList.remove('navigating'))
