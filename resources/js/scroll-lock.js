/**
 * Keeps the page behind a dialog from scrolling, iOS Safari included.
 *
 * overflow: hidden alone does not stop touch scrolling on iOS, so the body is pinned with
 * position: fixed at the current offset and put back (scroll position included) on unlock.
 * Nested locks are counted; only the last unlock releases the page.
 */
let locks = 0
let saved = null

export function lockScroll() {
    locks++

    if (locks > 1) {
        return
    }

    const body = document.body
    const scrollY = window.scrollY

    saved = {
        scrollY,
        position: body.style.position,
        top: body.style.top,
        left: body.style.left,
        right: body.style.right,
        width: body.style.width,
        overflow: body.style.overflow,
        overscroll: document.documentElement.style.overscrollBehavior,
    }

    body.style.position = 'fixed'
    body.style.top = `-${scrollY}px`
    body.style.left = '0'
    body.style.right = '0'
    body.style.width = '100%'
    body.style.overflow = 'hidden'
    document.documentElement.style.overscrollBehavior = 'none'
}

export function unlockScroll() {
    if (locks === 0) {
        return
    }

    locks--

    if (locks > 0 || saved === null) {
        return
    }

    const body = document.body

    body.style.position = saved.position
    body.style.top = saved.top
    body.style.left = saved.left
    body.style.right = saved.right
    body.style.width = saved.width
    body.style.overflow = saved.overflow
    document.documentElement.style.overscrollBehavior = saved.overscroll
    window.scrollTo({ top: saved.scrollY, behavior: 'instant' })
    saved = null
}
