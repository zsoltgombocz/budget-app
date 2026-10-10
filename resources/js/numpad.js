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
 * maxLength: the longest typed string.
 */
export function pressKey(value, key, { decimals = 0, max = null, maxLength = 12 } = {}) {
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
    } else if (/^[0-9]$/.test(key)) {
        const fraction = current.split(',')[1]
        if (fraction !== undefined && fraction.length >= decimals) {
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

        focus(name, label = '') {
            this.active = name
            this.activeLabel = label
        },

        fieldRule(name) {
            return { decimals: this.decimals, maxLength: this.amountMaxLength, max: null, ...(this.amountRules[name] ?? {}) }
        },

        press(key) {
            if (this.active === null || this.active === undefined) {
                return
            }
            this.fields[this.active] = pressKey(this.fields[this.active], key, this.fieldRule(this.active))
        },

        display(name = this.active, fallback = '0') {
            return formatAmount(this.fields[name], { locale: this.amountLocale, fallback })
        },

        filled(name = this.active) {
            return String(this.fields[name] ?? '') !== ''
        },
    }
}

export function registerNumpad(Alpine) {
    Alpine.data('amountFields', amountFields)
}
