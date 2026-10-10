// While a user action is in flight, nothing on the page takes another tap: a double tap on
// "Befizetés", "Mentés", a fixed item tick or "Tovább" must not run the action twice.
// Livewire marks <html data-busy>; app.css turns off pointer events on interactive elements.
// Background refreshes (wire:poll → $refresh) don't lock the page.
const PASSIVE = new Set(['$refresh'])

let pending = 0

const update = () => {
    if (pending > 0) {
        document.documentElement.dataset.busy = ''
    } else {
        delete document.documentElement.dataset.busy
    }
}

export function registerBusyLock(Livewire) {
    Livewire.interceptAction(({ action, onSend, onFinish }) => {
        if (PASSIVE.has(action.name)) {
            return
        }

        let counted = false

        onSend(() => {
            counted = true
            pending++
            update()
        })

        onFinish(() => {
            if (counted) {
                pending = Math.max(0, pending - 1)
                update()
            }
        })
    })
}
