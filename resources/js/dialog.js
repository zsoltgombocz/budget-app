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
const openDialogs = []

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

export function registerDialog(Alpine) {
    Alpine.data('appDialog', () => ({
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
                openDialogs.push(this)
                lockScroll()
                // Wait for x-show to reveal the panel; a hidden element cannot take focus.
                requestAnimationFrame(() => {
                    if (this.dialogOpen) {
                        const target = this.$root.querySelector('[data-autofocus]') ?? this.$refs.dialogPanel ?? this.$root
                        target.focus({ preventScroll: true })
                    }
                })

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
            const index = openDialogs.indexOf(this)

            if (index !== -1) {
                openDialogs.splice(index, 1)
                unlockScroll()
            }
        },

        dialogIsTop() {
            return this.dialogOpen && openDialogs.at(-1) === this
        },

        /**
         * True when this dialog should close on the Escape event; marks the event handled so
         * the dialog underneath (which becomes the top one) does not close as well.
         */
        dialogEscape(event) {
            if (event.defaultPrevented || ! this.dialogIsTop()) {
                return false
            }

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
