import { registerNumpad } from './numpad.js'
import { install, markStandalone, push, registerServiceWorker } from './pwa.js'

markStandalone()
registerServiceWorker()
install.listen()

window.budgetPush = push
window.budgetInstall = install

document.addEventListener('alpine:init', () => registerNumpad(window.Alpine))

window.addEventListener('load', () => push.sync())
