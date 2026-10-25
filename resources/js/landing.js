// The landing page: a few small live parts, all of them working without each other.
// Without this script every section is complete and readable; it only adds the motion and the demo.

document.documentElement.classList.add('js')

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
const format = new Intl.NumberFormat('hu-HU', { maximumFractionDigits: 0 })

// Sections rise in once as they enter.
const observer = new IntersectionObserver((entries) => {
    for (const entry of entries) {
        if (entry.isIntersecting) {
            entry.target.classList.add('is-in')
            observer.unobserve(entry.target)
        }
    }
}, { rootMargin: '0px 0px -10% 0px' })

document.querySelectorAll('.rise, .hero-card').forEach((element) => observer.observe(element))

// The number on the hero card counts down from the salary to what is left, like a month does.
const heroNumber = document.querySelector('[data-count-to]')

if (heroNumber && ! reduceMotion) {
    const from = Number(heroNumber.dataset.countFrom)
    const to = Number(heroNumber.dataset.countTo)
    const started = performance.now() + 600
    const duration = 1800

    const tick = (now) => {
        const progress = Math.min(1, Math.max(0, (now - started) / duration))
        const eased = 1 - Math.pow(1 - progress, 4)
        heroNumber.textContent = format.format(Math.round(from + (to - from) * eased))
        if (progress < 1) requestAnimationFrame(tick)
    }

    heroNumber.textContent = format.format(from)
    requestAnimationFrame(tick)
}

// The demo, as in the app: an amount and a budget; the month-end number and the budget follow.
const demo = document.querySelector('[data-demo]')

if (demo) {
    const input = demo.querySelector('[data-amount]')
    const chips = demo.querySelectorAll('[data-budget]')
    const left = demo.querySelector('[data-demo-left]')
    const budgetName = demo.querySelector('[data-demo-budget]')
    const budgetLeft = demo.querySelector('[data-demo-budget-left]')
    const base = Number(left.dataset.base)
    let chosen = chips[0]

    const amount = () => Math.min(999999999, Number(input.value.replace(/\D/g, '')) || 0)

    const update = () => {
        const value = amount()
        left.textContent = format.format(base - value)
        budgetName.textContent = chosen.dataset.name
        budgetLeft.textContent = format.format(Number(chosen.dataset.left) - value)
    }

    // Where field-sizing is not supported, the field follows the length of the amount.
    const fit = () => { if (! CSS.supports('field-sizing', 'content')) input.style.width = `${Math.max(2, input.value.length + 0.5)}ch` }

    input.addEventListener('input', () => {
        const value = amount()
        input.value = value ? format.format(value) : ''
        fit()
        update()
    })

    fit()

    chips.forEach((chip) => {
        chip.addEventListener('click', () => {
            chosen = chip
            chips.forEach((other) => other.setAttribute('aria-checked', String(other === chip)))
            update()
        })
    })

    update()
}

// How it works: four tabs, each over its own progress bar; it moves on by itself while in view.
const stages = document.querySelector('[data-stages]')

if (stages) {
    const tabs = [...stages.querySelectorAll('[data-stage]')]
    const panels = stages.querySelectorAll('[data-clause]')
    const screens = stages.querySelectorAll('[data-screen]')
    const duration = 6000
    let current = 0
    let elapsed = 0
    let last = null
    let visible = false
    let held = false

    const show = (index, focus = false) => {
        current = (index + tabs.length) % tabs.length
        elapsed = 0

        tabs.forEach((tab, i) => {
            const on = i === current
            tab.setAttribute('aria-selected', String(on))
            tab.tabIndex = on ? 0 : -1
            tab.style.setProperty('--p', i < current ? '1' : '0')
        })
        panels.forEach((panel, i) => { panel.hidden = i !== current })
        screens.forEach((screen, i) => {
            screen.classList.toggle('is-current', i === current)
            if (screen instanceof HTMLVideoElement) {
                if (i === current && ! reduceMotion) {
                    screen.currentTime = 0
                    screen.play().catch(() => {})
                } else {
                    screen.pause()
                }
            }
        })

        if (reduceMotion) tabs[current].style.setProperty('--p', '1')
        if (focus) tabs[current].focus()
    }

    const frame = (now) => {
        if (last !== null && visible && ! held && ! reduceMotion) {
            elapsed += now - last
            tabs[current].style.setProperty('--p', String(Math.min(1, elapsed / duration)))
            if (elapsed >= duration) show(current + 1)
        }
        last = now
        requestAnimationFrame(frame)
    }

    tabs.forEach((tab, i) => {
        tab.addEventListener('click', () => show(i))
        tab.addEventListener('keydown', (event) => {
            const moves = { ArrowRight: current + 1, ArrowLeft: current - 1, Home: 0, End: tabs.length - 1 }
            if (event.key in moves) {
                event.preventDefault()
                show(moves[event.key], true)
            }
        })
    })

    stages.addEventListener('pointerenter', () => { held = true })
    stages.addEventListener('pointerleave', () => { held = false })
    stages.addEventListener('focusin', () => { held = true })
    stages.addEventListener('focusout', () => { held = false })
    new IntersectionObserver(([entry]) => { visible = entry.isIntersecting }, { threshold: 0.4 }).observe(stages)

    show(0)
    requestAnimationFrame(frame)
}

// The app's switches: on, a band fills and says what it does.
document.querySelectorAll('[data-switch]').forEach((button) => {
    const band = button.closest('.band')

    button.addEventListener('click', () => {
        const on = button.getAttribute('aria-checked') !== 'true'
        button.setAttribute('aria-checked', String(on))
        band.classList.toggle('is-on', on)
    })
})
