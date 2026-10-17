/**
 * Amount entry on the app's own numpad (x-ui.numpad) instead of the system keyboard.
 *
 * Values are kept as typed strings in major units ("8450", "24,99") and converted to the
 * smallest unit on the server (Money::parse). Every screen with a numpad mixes amountFields()
 * into its Alpine data, so the keys behave the same everywhere:
 *
 *     Alpine.data('page', ({ decimals, locale }) => ({
 *         ...window.amountFields({ decimals, locale, rules: { dueDay: { decimals: 0, max: 31 } } }),
 *     }))
 *
 * x-ui.numpad calls press(key), x-ui.amount-row calls focus(name, label) and reads display()
 * and filled(); x-ui.amount-pad reads active, activeLabel and display().
 */
const formatters = new Map()

function integerFormatter(locale) {
    if (! formatters.has(locale)) {
        formatters.set(locale, new Intl.NumberFormat(locale, { maximumFractionDigits: 0, useGrouping: 'always' }))
    }

    return formatters.get(locale)
}

/**
 * The value after pressing one numpad key.
 *
 * decimals: fraction digits the field takes (0 = no comma); max: the largest allowed whole
 * number (a day of the month) — a key that would go over it starts the number again;
 * maxWhole: the most digits before the comma; maxLength: the longest typed string.
 */
export function pressKey(value, key, { decimals = 0, max = null, maxWhole = null, maxLength = 12 } = {}) {
    const current = String(value ?? '')
    let next

    if (key === 'del') {
        return current.slice(0, -1)
    }

    if (key === ',') {
        if (decimals === 0 || current.includes(',')) {
            return current
        }
        next = (current || '0') + ','
    } else if (key === '000') {
        if (current === '' || current === '0' || current.includes(',') || max !== null) {
            return current
        }
        next = current + '000'
        if (maxWhole !== null && next.length > maxWhole) {
            return current
        }
    } else if (/^[0-9]$/.test(key)) {
        const [whole, fraction] = current.split(',')
        if (fraction !== undefined && fraction.length >= decimals) {
            return current
        }
        if (fraction === undefined && maxWhole !== null && whole.length >= maxWhole && whole !== '0') {
            return current
        }
        next = (current === '0' ? '' : current) + key
        if (max !== null && parseInt(next, 10) > max) {
            next = key
        }
    } else {
        return current
    }

    return next.length > maxLength ? current : next
}

/**
 * A typed value with thousands grouping: "1234567,5" → "1 234 567,5"; empty → fallback.
 */
export function formatAmount(value, { locale = 'hu', fallback = '0' } = {}) {
    const text = String(value ?? '').replace('.', ',')

    if (text === '') {
        return fallback
    }

    const [whole, fraction] = text.split(',')
    const formatted = integerFormatter(locale).format(parseInt(whole || '0', 10))

    return fraction !== undefined ? `${formatted},${fraction}` : formatted
}

/**
 * Numpad state for one or more amount fields. rules overrides decimals/max/maxLength per
 * field name (e.g. an APR with 3 decimals, a due day up to 31).
 */
export function amountFields({ fields = {}, active = null, decimals = 0, locale = 'hu', maxLength = 12, rules = {} } = {}) {
    return {
        fields,
        active,
        activeLabel: '',
        decimals,
        amountLocale: locale,
        amountMaxLength: maxLength,
        amountRules: rules,
        /** True until the first key after opening a filled field: that key replaces the value (like selected text). */
        amountFresh: false,

        focus(name, label = '') {
            this.active = name
            this.activeLabel = label
            this.amountFresh = this.filled(name)
        },

        /** Opens a field with a value to correct: the first key replaces it, the next ones append. */
        editAmount(name, value, label = '') {
            this.fields[name] = String(value ?? '').replace('.', ',')
            this.focus(name, label)
        },

        /** The unit shown after a field's amount: rules[name].symbol (null for none), otherwise the fallback (the currency). */
        amountSymbol(name, fallback = '') {
            const rule = this.amountRules[name]

            return rule && 'symbol' in rule ? (rule.symbol ?? '') : fallback
        },

        fieldRule(name) {
            return { decimals: this.decimals, maxLength: this.amountMaxLength, max: null, maxWhole: null, ...(this.amountRules[name] ?? {}) }
        },

        /** A screen can define amountGuard() returning false to ignore keys (e.g. while saving). */
        press(key) {
            if (this.active === null || this.active === undefined) {
                return
            }
            if (typeof this.amountGuard === 'function' && ! this.amountGuard()) {
                return
            }
            // The first key after opening a filled field starts over, except Backspace, which edits it.
            const current = this.amountFresh && key !== 'del' ? '' : this.fields[this.active]
            this.amountFresh = false
            this.fields[this.active] = pressKey(current, key, this.fieldRule(this.active))
        },

        display(name = this.active, fallback = '0') {
            return formatAmount(this.fields[name], { locale: this.amountLocale, fallback })
        },

        filled(name = this.active) {
            return String(this.fields[name] ?? '') !== ''
        },
    }
}

const EDITABLE = 'input, textarea, select, [contenteditable="true"]'

/**
 * Physical keyboard on a visible x-ui.numpad: digits, Backspace, comma or period and Enter.
 * Ignored while typing into a text field, when the numpad is hidden, or when another dialog
 * (e.g. a confirmation) sits on top of the numpad's sheet. Enter on a numpad key runs the
 * numpad's enter action instead of pressing that key again; on any other button it stays native.
 */
export function numpadKeydown(event, numpad, press, enter) {
    if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || numpad.offsetParent === null) {
        return
    }

    const target = event.target instanceof Element ? event.target : null

    if (target?.closest(EDITABLE)) {
        return
    }

    const dialog = numpad.closest('[role="dialog"]')
    const focusInside = dialog === null || dialog.contains(document.activeElement) || document.activeElement === document.body

    if (! focusInside) {
        return
    }

    let key = null

    if (/^[0-9]$/.test(event.key)) {
        key = event.key
    } else if (event.key === 'Backspace') {
        key = 'del'
    } else if (event.key === ',' || event.key === '.' || event.key === 'Decimal') {
        key = numpad.querySelector('[data-key=","]') ? ',' : null
    } else if (event.key === 'Enter') {
        if (target?.closest('a, button') && ! target.closest('[data-numpad]')) {
            return
        }
        event.preventDefault()
        enter()

        return
    }

    if (key !== null) {
        event.preventDefault()
        press(key)
    }
}

export function registerNumpad(Alpine) {
    Alpine.data('amountFields', amountFields)
}
