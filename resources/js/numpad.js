/**
 * Numeric entry without the system keyboard. Values are kept as typed strings in major
 * units ("8450", "24,99") and converted to the smallest unit on the server.
 */
export function registerNumpad(Alpine) {
    Alpine.data('amountFields', ({ fields = {}, active = null, decimals = 0, locale = 'hu' }) => ({
        fields,
        active,
        decimals,
        formatter: new Intl.NumberFormat(locale, { minimumFractionDigits: 0, maximumFractionDigits: decimals, useGrouping: 'always' }),

        focus(name) {
            this.active = name
        },

        press(key) {
            if (! this.active) return
            let value = String(this.fields[this.active] ?? '')

            if (key === 'del') {
                value = value.slice(0, -1)
            } else if (key === ',') {
                if (this.decimals > 0 && ! value.includes(',')) value = (value || '0') + ','
            } else if (key === '000') {
                if (value && ! value.includes(',')) value += '000'
            } else {
                const [, fraction] = value.split(',')
                if (fraction !== undefined && fraction.length >= this.decimals) return
                if (value === '0') value = ''
                value += key
            }

            this.fields[this.active] = value.slice(0, 12)
        },

        display(name, fallback = '0') {
            const value = String(this.fields[name] ?? '')
            if (value === '') return fallback
            const [whole, fraction] = value.split(',')
            const formatted = this.formatter.format(parseInt(whole || '0', 10))
            return fraction !== undefined ? formatted + ',' + fraction : formatted
        },

        filled(name) {
            return String(this.fields[name] ?? '') !== ''
        },
    }))
}
