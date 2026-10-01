import { registerNumpad } from './numpad.js'
import { install, push, registerServiceWorker } from './pwa.js'

registerServiceWorker()
install.listen()

window.budgetPush = push
window.budgetInstall = install

document.addEventListener('alpine:init', () => registerNumpad(window.Alpine))

window.addEventListener('load', () => push.sync())
