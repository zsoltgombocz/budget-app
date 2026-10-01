import { push, registerServiceWorker } from './pwa.js'

registerServiceWorker()

window.budgetPush = push

window.addEventListener('load', () => push.sync())
