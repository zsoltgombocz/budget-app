/**
 * The "How it works" spotlight tour (components/tour): a dim scrim over the whole app with a
 * cut-out around the element being explained, and a card next to it with the step's text.
 *
 * Each screen renders its own steps (App\Support\Tour); a step points at the elements marked
 * data-tour="{id}" (several elements with the same id are spotlighted together, each with its
 * data-tour-pad, default 6px) and is skipped when none of them is on screen. The last step of a
 * screen can lead on to the next screen: the section to resume, the steps done so far and the
 * step counts of the screens still to come are kept in sessionStorage, and that screen's tour
 * starts by itself after wire:navigate. One counter runs across the screens ("5 / 15"): the
 * first screen gets the later screens' counts as `upcoming`. Finishing, skipping or leaving
 * with Escape marks the intro as seen, scrolls back to the top and says where to find the tour again.
 *
 * Usage: x-data="appTour({ section, steps, open, next, upcoming, seenUrl, labels })" (components/tour).
 */
const STORAGE_KEY = 'app-tour'
const FOCUSABLE = 'button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'
const PADDING = 6
const GAP = 14
const MARGIN = 12

function motionOff() {
    return document.documentElement.dataset.motion === 'off' || window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

/** @returns {{ section: string, done: number, later: Object<string, number> } | null} */
function readResume() {
    try {
        const raw = sessionStorage.getItem(STORAGE_KEY)
        if (! raw) {
            return null
        }
        const resume = JSON.parse(raw)

        return resume && typeof resume.section === 'string' ? resume : null
    } catch (e) {
        return null
    }
}

function writeResume(resume) {
    try {
        if (resume) {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(resume))
        } else {
            sessionStorage.removeItem(STORAGE_KEY)
        }
    } catch (e) {}
}

function padOf(element) {
    const pad = parseFloat(element.dataset.tourPad)

    return Number.isFinite(pad) ? pad : PADDING
}

function isShown(element) {
    if (element.closest('[data-tour-root]')) {
        return false
    }
    const box = element.getBoundingClientRect()

    return box.width > 0 && box.height > 0 && getComputedStyle(element).visibility !== 'hidden'
}

export function registerTour(Alpine) {
    Alpine.data('appTour', (config) => ({
        tourActive: false,
        tourIndex: 0,
        tourSteps: [],
        tourPlaced: false,
        tourAnnouncement: '',
        tourSpot: { x: 0, y: 0, width: 0, height: 0, radius: 16 },
        tourTip: { top: 0, arrow: null, arrowX: 0 },
        tourFrame: null,
        tourRun: 0,
        tourListeners: null,
        tourScrolling: false,
        tourInstant: false,
        tourOffset: 0,
        tourLater: {},

        init() {
            const resume = readResume()
            if (resume?.section === config.section) {
                writeResume(null)
                this.tourOffset = Number(resume.done) || 0
                this.tourLater = resume.later ?? {}
                this.tourStart()
            } else if (config.open) {
                this.tourOffset = 0
                this.tourLater = config.upcoming ?? {}
                this.tourStart()
            }
        },

        destroy() {
            this.tourTeardown()
        },

        get tourStep() {
            return this.tourSteps[this.tourIndex] ?? { id: '', title: '', body: [] }
        },

        get tourIsLast() {
            return this.tourIndex === this.tourSteps.length - 1
        },

        get tourTotal() {
            return this.tourOffset + this.tourSteps.length + Object.values(this.tourLater).reduce((sum, count) => sum + (Number(count) || 0), 0)
        },

        get tourCounter() {
            return `${this.tourOffset + this.tourIndex + 1} / ${this.tourTotal}`
        },

        get tourNextLabel() {
            if (! this.tourIsLast) {
                return config.labels.next
            }

            return config.next ? config.next.label : config.labels.done
        },

        get tourSkipLabel() {
            return this.tourIsLast ? config.labels.finish : config.labels.skip
        },

        get tourSpotStyle() {
            const { x, y, width, height, radius } = this.tourSpot

            return `left: ${x}px; top: ${y}px; width: ${width}px; height: ${height}px; border-radius: ${radius}px`
        },

        get tourTipStyle() {
            return `top: ${this.tourTip.top}px`
        },

        tourTargets(step) {
            return [...document.querySelectorAll(`[data-tour~="${step.id}"]`)].filter(isShown)
        },

        tourStart() {
            // Wait for the page's entrance animation (and Livewire's first paint) before measuring.
            setTimeout(() => {
                this.tourSteps = config.steps.filter((step) => this.tourTargets(step).length > 0)
                if (this.tourSteps.length === 0) {
                    return
                }
                this.tourActive = true
                document.documentElement.dataset.touring = ''
                this.tourListen()
                this.tourGo(0, true)
            }, motionOff() ? 0 : 450)
        },

        tourListen() {
            const update = () => this.tourSchedule()
            const resize = new ResizeObserver(update)
            resize.observe(document.body)
            window.addEventListener('resize', update)
            window.addEventListener('scroll', update, { passive: true })
            this.tourListeners = () => {
                resize.disconnect()
                window.removeEventListener('resize', update)
                window.removeEventListener('scroll', update)
            }
        },

        tourTeardown() {
            delete document.documentElement.dataset.touring
            this.tourListeners?.()
            this.tourListeners = null
            if (this.tourFrame) {
                cancelAnimationFrame(this.tourFrame)
            }
        },

        tourSchedule() {
            if (this.tourFrame) {
                return
            }
            this.tourFrame = requestAnimationFrame(() => {
                this.tourFrame = null
                this.tourPosition()
            })
        },

        /**
         * The visible area: below the status bar and the screen's sticky band (data-tour-sticky),
         * above the tab bar and the home indicator. A target inside one of them lifts that limit.
         */
        tourViewport(targets, scrolling = false) {
            const probe = getComputedStyle(this.$refs.tourSafe)
            let top = parseFloat(probe.paddingTop) || 0
            let bottom = window.innerHeight - (parseFloat(probe.paddingBottom) || 0)
            const holds = (element) => targets.some((target) => element.contains(target))
            document.querySelectorAll('[data-tour-sticky]').forEach((sticky) => {
                if (holds(sticky)) {
                    return
                }
                // Only a stuck band covers the page; while scrolling down to a target it will be stuck.
                const stickyTop = parseFloat(getComputedStyle(sticky).top) || 0
                const box = sticky.getBoundingClientRect()
                if (scrolling) {
                    top = Math.max(top, stickyTop + box.height)
                } else if (box.top <= stickyTop + 1) {
                    top = Math.max(top, box.bottom)
                }
            })
            const tabBar = document.querySelector('[data-tour="tabs"]')?.closest('nav')
            if (tabBar && ! holds(tabBar)) {
                bottom = Math.min(bottom, tabBar.getBoundingClientRect().top)
            }

            // The card itself may cover the tab bar (on a short phone there is no room otherwise).
            const screenBottom = window.innerHeight - (parseFloat(probe.paddingBottom) || 0)

            return { top: top + MARGIN, bottom: bottom - MARGIN, tipBottom: screenBottom - MARGIN }
        },

        /** Whether the targets sit in a fixed element (the tab bar): scrolling does not move them. */
        tourFixed(targets) {
            return targets.every((target) => {
                for (let element = target; element; element = element.parentElement) {
                    if (getComputedStyle(element).position === 'fixed') {
                        return true
                    }
                }

                return false
            })
        },

        tourRect(targets) {
            const boxes = targets.map((target) => {
                const box = target.getBoundingClientRect()
                const pad = padOf(target)

                return { top: box.top - pad, left: box.left - pad, bottom: box.bottom + pad, right: box.right + pad }
            })
            const top = Math.min(...boxes.map((box) => box.top))
            const left = Math.min(...boxes.map((box) => box.left))
            const bottom = Math.max(...boxes.map((box) => box.bottom))
            const right = Math.max(...boxes.map((box) => box.right))

            return { top, left, bottom, right, height: bottom - top }
        },

        async tourGo(index, first = false) {
            const run = ++this.tourRun
            this.tourIndex = index
            this.tourPlaced = false
            await this.$nextTick()

            const targets = this.tourTargets(this.tourStep)
            if (targets.length === 0) {
                // The element went away meanwhile (e.g. a re-render): skip it in the same direction.
                this.tourSteps.splice(index, 1)
                if (this.tourSteps.length === 0) {
                    return this.tourEnd()
                }

                return this.tourGo(Math.min(index, this.tourSteps.length - 1), first)
            }

            // The card's new text is laid out before it is measured for the scroll.
            await new Promise((resolve) => requestAnimationFrame(resolve))
            await this.tourScrollTo(targets)
            if (run !== this.tourRun) {
                return
            }
            await this.tourOpen(targets)
            if (this.tourPosition() && run === this.tourRun) {
                // The element ended up cropped by the card (it was measured before it settled): scroll once more.
                await this.tourScrollTo(targets)
                if (run !== this.tourRun) {
                    return
                }
                await this.tourOpen(targets)
                this.tourPosition()
            }
            this.tourPlaced = true

            // Keep focus on the button that was pressed; otherwise (first step, or the button is gone) the card takes it.
            const active = document.activeElement
            if (first || ! this.$refs.tourTip.contains(active) || active.offsetParent === null) {
                this.tourFocus(10)
            }
            if (! first) {
                this.tourAnnouncement = `${this.tourCounter}. ${this.tourStep.title}. ${this.tourStep.body.join(' ')}`
            }
        },

        /** Back from a scroll with the hole closed (tourInstant): it opens again from the element's centre. */
        async tourOpen(targets) {
            if (! this.tourInstant) {
                return
            }
            const rect = this.tourRect(targets)
            this.tourSpot = { ...this.tourSpot, x: (rect.left + rect.right) / 2, y: (rect.top + rect.bottom) / 2, width: 0, height: 0 }
            await this.$nextTick()
            await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)))
            this.tourInstant = false
        },

        /** Focuses the card; retried for a few frames while it is still being revealed. */
        tourFocus(tries) {
            const tip = this.$refs.tourTip
            tip.focus({ preventScroll: true })
            if (this.tourActive && document.activeElement !== tip && tries > 0) {
                requestAnimationFrame(() => this.tourFocus(tries - 1))
            }
        },

        /** Scrolls the page so the target and the card fit on screen together (the card below, or above). */
        async tourScrollTo(targets) {
            const rect = this.tourRect(targets)
            const view = this.tourViewport(targets, true)
            const tip = this.$refs.tourTip.offsetHeight + GAP
            const room = view.tipBottom - view.top
            const fitsBelow = rect.top >= view.top && rect.bottom <= view.bottom && rect.bottom + tip <= view.tipBottom
            const fitsAbove = rect.top - tip >= view.top && rect.bottom <= view.bottom

            if (fitsBelow || fitsAbove || this.tourFixed(targets)) {
                return
            }

            const delta = rect.height + tip <= room
                ? rect.top - (view.top + Math.min((room - rect.height - tip) / 3, Math.max(0, view.bottom - view.top - rect.height)))
                : rect.top - view.top
            const maxScroll = document.documentElement.scrollHeight - window.innerHeight
            const target = Math.max(0, Math.min(maxScroll, window.scrollY + delta))

            if (Math.abs(target - window.scrollY) < 1) {
                return
            }

            const smooth = ! motionOff()
            if (smooth) {
                // While the page scrolls the hole would trail behind its element: close it at once (the
                // scrim stays whole) and open it again on the element once the page stands still.
                this.tourScrolling = true
                this.tourInstant = true
                this.tourSpot = { ...this.tourSpot, x: -40, y: -40, width: 0, height: 0 }
                await this.$nextTick()
            }
            window.scrollTo({ top: target, behavior: smooth ? 'smooth' : 'auto' })

            if (! smooth) {
                return
            }

            // Wait for the smooth scroll to settle: the position stays put for a few frames.
            await new Promise((resolve) => {
                let last = window.scrollY
                let still = 0
                const started = performance.now()
                const tick = () => {
                    still = Math.abs(window.scrollY - last) < 0.5 ? still + 1 : 0
                    last = window.scrollY
                    if (still >= 4 || performance.now() - started > 1200) {
                        this.tourScrolling = false
                        resolve()
                    } else {
                        requestAnimationFrame(tick)
                    }
                }
                requestAnimationFrame(tick)
            })
        },

        /** Places the spotlight and the card; true when the card had to crop the lit element. */
        tourPosition() {
            if (! this.tourActive || this.tourScrolling) {
                return false
            }
            const targets = this.tourTargets(this.tourStep)
            if (targets.length === 0) {
                return false
            }

            const rect = this.tourRect(targets)
            const view = this.tourViewport(targets)
            const width = document.documentElement.clientWidth
            // The spotlight never runs off the screen: a tall card is lit as far as it shows.
            const top = Math.max(rect.top, view.top - MARGIN + 2)
            const bottom = Math.min(rect.bottom, view.bottom + MARGIN)
            const left = Math.max(rect.left, 4)
            const right = Math.min(rect.right, width - 4)
            const radius = parseFloat(getComputedStyle(targets[0]).borderTopLeftRadius) || 0

            const tipElement = this.$refs.tourTip
            const tipHeight = tipElement.offsetHeight
            const tipBox = tipElement.getBoundingClientRect()
            let tipTop
            let spotBottom = bottom
            let arrow = null

            let spotTop = top

            if (bottom + GAP + tipHeight <= view.tipBottom) {
                tipTop = bottom + GAP
                arrow = 'top'
            } else if (top - GAP - tipHeight >= view.top) {
                tipTop = top - GAP - tipHeight
                arrow = 'bottom'
            } else if (top - view.top > view.tipBottom - bottom) {
                // No room on either side (a tall card): the card goes where there is more room and
                // the spotlight lights the part of the element it leaves free.
                tipTop = view.top
                spotTop = Math.min(bottom - 24, tipTop + tipHeight + GAP)
                arrow = 'bottom'
            } else {
                tipTop = view.tipBottom - tipHeight
                spotBottom = Math.max(top + 24, tipTop - GAP)
                arrow = 'top'
            }

            tipTop = Math.max(view.top, Math.min(tipTop, view.tipBottom - tipHeight))

            this.tourSpot = {
                x: left,
                y: spotTop,
                width: Math.max(0, right - left),
                height: Math.max(0, spotBottom - spotTop),
                radius: Math.min(radius + PADDING, 30),
            }

            this.tourTip = {
                top: Math.round(tipTop),
                arrow,
                arrowX: Math.round(Math.max(24, Math.min(tipBox.width - 24, (left + right) / 2 - tipBox.left))),
            }

            return spotTop > top + 0.5 || spotBottom < bottom - 0.5
        },

        tourForward() {
            if (! this.tourIsLast) {
                this.tourGo(this.tourIndex + 1)

                return
            }

            if (config.next) {
                const later = { ...this.tourLater }
                delete later[config.next.section]
                writeResume({ section: config.next.section, done: this.tourOffset + this.tourSteps.length, later })
                this.tourEnd({ keepResume: true })
                window.Livewire ? window.Livewire.navigate(config.next.url) : (window.location.href = config.next.url)

                return
            }

            this.tourEnd()
        },

        tourBack() {
            if (this.tourIndex > 0) {
                this.tourGo(this.tourIndex - 1)
            }
        },

        tourEscape(event) {
            if (! this.tourActive || event.defaultPrevented) {
                return
            }
            event.preventDefault()
            this.tourEnd()
        },

        tourEnd({ keepResume = false } = {}) {
            if (! keepResume) {
                writeResume(null)
            }
            this.tourRun++
            this.tourActive = false
            this.tourPlaced = false
            this.tourScrolling = false
            this.tourInstant = false
            this.tourTeardown()

            if (! keepResume) {
                // Back to where the screen starts, and a word on where the tour lives.
                window.scrollTo({ top: 0, behavior: motionOff() ? 'auto' : 'smooth' })
                window.dispatchEvent(new CustomEvent('app-toast', { detail: { title: config.labels.again, icon: 'lightbulb' } }))
            }

            if (config.seenUrl) {
                const token = document.querySelector('meta[name="csrf-token"]')?.content ?? ''
                fetch(config.seenUrl, { method: 'POST', keepalive: true, headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' } }).catch(() => {})
            }

            const url = new URL(window.location.href)
            if (url.searchParams.has('bemutato')) {
                url.searchParams.delete('bemutato')
                history.replaceState(history.state, '', url)
            }

            if (this.$refs.tourTip.contains(document.activeElement)) {
                document.activeElement.blur()
            }
        },

        /** Keeps Tab and Shift+Tab inside the card. */
        tourTrap(event) {
            const focusable = [...this.$refs.tourTip.querySelectorAll(FOCUSABLE)].filter((element) => element.offsetParent !== null)
            if (focusable.length === 0) {
                event.preventDefault()

                return
            }
            const first = focusable[0]
            const last = focusable[focusable.length - 1]
            const active = document.activeElement

            if (event.shiftKey && (active === first || active === this.$refs.tourTip || ! this.$refs.tourTip.contains(active))) {
                event.preventDefault()
                last.focus()
            } else if (! event.shiftKey && (active === last || ! this.$refs.tourTip.contains(active))) {
                event.preventDefault()
                first.focus()
            }
        },
    }))
}
