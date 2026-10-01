import { registerNumpad } from './numpad.js'
import { install, markStandalone, push, registerServiceWorker } from './pwa.js'

markStandalone()
registerServiceWorker()
install.listen()

window.budgetPush = push
window.budgetInstall = install

document.addEventListener('alpine:init', () => registerNumpad(window.Alpine))

window.addEventListener('load', () => push.sync())

// Immediate navigation feedback for wire:navigate (Livewire's own bar shows up late).
document.addEventListener('livewire:navigate', () => document.documentElement.classList.add('navigating'))
document.addEventListener('livewire:navigated', () => document.documentElement.classList.remove('navigating'))
