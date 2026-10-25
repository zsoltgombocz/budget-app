// The landing page: a few small live parts, all of them working without each other.
// Without this script every section is complete and readable; it only adds the motion and the demo.

document.documentElement.classList.add('js')

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
const format = new Intl.NumberFormat('hu-HU', { maximumFractionDigits: 0 })

// Sections rise in once as they enter; the hero slot opens like a shutter.
const observer = new IntersectionObserver((entries) => {
    for (const entry of entries) {
        if (entry.isIntersecting) {
            entry.target.classList.add('is-in')
            observer.unobserve(entry.target)
        }
    }
}, { rootMargin: '0px 0px -10% 0px' })

document.querySelectorAll('.rise, .slot').forEach((element) => observer.observe(element))

// The number in the first sentence counts down from the salary to what is left, like a month does.
const slotNumber = document.querySelector('[data-count-to]')

if (slotNumber && ! reduceMotion) {
    const from = Number(slotNumber.dataset.countFrom)
    const to = Number(slotNumber.dataset.countTo)
    const started = performance.now() + 500
    const duration = 1800

    const tick = (now) => {
        const progress = Math.min(1, Math.max(0, (now - started) / duration))
        const eased = 1 - Math.pow(1 - progress, 4)
        slotNumber.textContent = format.format(Math.round(from + (to - from) * eased))
        if (progress < 1) requestAnimationFrame(tick)
    }

    slotNumber.textContent = format.format(from)
    requestAnimationFrame(tick)
}

// "Type what you spent": the amount comes off the month-end number and its budget, as in the app.
const typing = document.querySelector('[data-typing]')

if (typing) {
    const input = typing.querySelector('input')
    const left = document.querySelector('[data-left]')
    const budgetName = document.querySelector('[data-budget-name]')
    const budgetLeft = document.querySelector('[data-budget-left]')
    const base = Number(left.dataset.base)
    const budgets = JSON.parse(typing.dataset.budgets)

    const pick = (text) => budgets.find((budget) => budget.words.some((word) => text.includes(word))) ?? budgets[0]

    const update = () => {
        const text = input.value.toLowerCase()
        const amount = Number((text.match(/\d[\d\s.]*/)?.[0] ?? '0').replace(/[\s.]/g, '')) || 0
        const budget = pick(text)
        left.textContent = format.format(base - amount)
        budgetName.textContent = budget.name
        budgetLeft.textContent = format.format(budget.left - amount)
    }

    input.addEventListener('input', update)

    document.querySelectorAll('[data-example]').forEach((button) => {
        button.addEventListener('click', () => {
            input.value = button.dataset.example
            update()
            input.focus()
        })
    })

    update()
}

// One slider drives the clause and the screen of each stage.
const stages = document.querySelector('[data-stages]')

if (stages) {
    const range = stages.querySelector('input[type=range]')
    const clauses = stages.querySelectorAll('[data-clause]')
    const screens = stages.querySelectorAll('[data-screen]')
    const names = stages.querySelectorAll('[data-stage]')
    const fill = stages.querySelector('[data-fill]')

    const show = (index) => {
        range.value = String(index)
        clauses.forEach((clause, i) => { clause.hidden = i !== index })
        screens.forEach((screen, i) => {
            screen.classList.toggle('is-current', i === index)
            // The recording plays only while its stage is shown, and not for reduced motion.
            if (screen instanceof HTMLVideoElement) {
                if (i === index && ! reduceMotion) screen.play().catch(() => {})
                else screen.pause()
            }
        })
        names.forEach((name, i) => (i === index ? name.setAttribute('aria-current', 'step') : name.removeAttribute('aria-current')))
        fill.style.width = `${(index / (screens.length - 1)) * 100}%`
        range.setAttribute('aria-valuetext', names[index].textContent.trim())
    }

    range.addEventListener('input', () => show(Number(range.value)))
    names.forEach((name, i) => name.addEventListener('click', () => show(i)))
    show(0)
}

// The app's switches: on, a band fills and says what it does in numbers.
document.querySelectorAll('[data-switch]').forEach((button) => {
    const band = button.closest('.band')

    button.addEventListener('click', () => {
        const on = button.getAttribute('aria-checked') !== 'true'
        button.setAttribute('aria-checked', String(on))
        band.classList.toggle('is-on', on)
    })
})
