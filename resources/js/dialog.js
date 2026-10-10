import { lockScroll, unlockScroll } from './scroll-lock.js'

/**
 * Behaviour shared by every sheet and dialog (x-ui.sheet, components/confirm-dialog):
 * the page behind does not scroll (iOS-safe lock), focus moves into the dialog when it opens
 * and back to whatever opened it when it closes, Tab stays inside, and Escape closes only
 * the topmost one.
 *
 * Usage: x-data="appDialog" x-effect="dialogSync(<open expression>)" on the dialog root,
 * x-on:keydown.escape.window="dialogEscape($event) && (<close statement>)", and x-ref="dialogPanel"
 * (tabindex="-1") on the element that receives focus. The names are prefixed so they do not
 * shadow the surrounding Alpine scope, whose expressions the root still evaluates.
 */
// Ids, not objects: Alpine hands methods a fresh scope proxy per expression, so `this` differs.
const openDialogs = []
let nextId = 0

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

export function registerDialog(Alpine) {
    Alpine.data('appDialog', () => ({
        dialogId: ++nextId,
        dialogOpen: false,
        dialogOpener: null,

        dialogSync(open) {
            open = Boolean(open)

            if (open === this.dialogOpen) {
                return
            }

            this.dialogOpen = open

            if (open) {
                this.dialogOpener = document.activeElement instanceof HTMLElement ? document.activeElement : null
                openDialogs.push(this.dialogId)
                lockScroll()
                // x-show reveals the panel a frame or two later (transition); a hidden element
                // cannot take focus, so retry for a few frames.
                const focusIn = (tries) => {
                    if (! this.dialogOpen) {
                        return
                    }
                    const target = this.$root.querySelector('[data-autofocus]') ?? this.$refs.dialogPanel ?? this.$root
                    target.focus({ preventScroll: true })
                    if (document.activeElement !== target && tries > 0) {
                        requestAnimationFrame(() => focusIn(tries - 1))
                    }
                }
                requestAnimationFrame(() => focusIn(10))

                return
            }

            this.dialogRelease()
            const opener = this.dialogOpener
            this.dialogOpener = null
            const focusInside = this.$root.contains(document.activeElement) || document.activeElement === document.body
            if (opener?.isConnected && focusInside) {
                opener.focus({ preventScroll: true })
            }
        },

        dialogRelease() {
            const index = openDialogs.indexOf(this.dialogId)

            if (index !== -1) {
                openDialogs.splice(index, 1)
                unlockScroll()
            }
        },

        dialogIsTop() {
            return this.dialogOpen && openDialogs.at(-1) === this.dialogId
        },

        /**
         * True when this dialog should close on the Escape event; marks the event handled so
         * the dialog underneath (which becomes the top one) does not close as well.
         */
        dialogEscape(event) {
            if (event.dialogHandled || event.defaultPrevented || ! this.dialogIsTop()) {
                return false
            }

            event.dialogHandled = true
            event.preventDefault()

            return true
        },

        /** Keeps Tab and Shift+Tab inside the dialog. */
        dialogTrap(event) {
            if (! this.dialogIsTop()) {
                return
            }

            const focusable = [...this.$root.querySelectorAll(FOCUSABLE)].filter((element) => element.offsetParent !== null || element === document.activeElement)

            if (focusable.length === 0) {
                event.preventDefault()

                return
            }

            const first = focusable[0]
            const last = focusable[focusable.length - 1]

            if (event.shiftKey && (document.activeElement === first || ! this.$root.contains(document.activeElement) || document.activeElement === this.$refs.dialogPanel)) {
                event.preventDefault()
                last.focus()
            } else if (! event.shiftKey && (document.activeElement === last || ! this.$root.contains(document.activeElement))) {
                event.preventDefault()
                first.focus()
            }
        },

        destroy() {
            this.dialogRelease()
        },
    }))
}
