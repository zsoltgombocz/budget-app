<div id="app-splash" aria-hidden="true">
    <span class="splash-icon flex size-20 items-center justify-center rounded-[26px] bg-accent text-accent-ink shadow-[0_18px_40px_color-mix(in_srgb,var(--app-accent)_35%,transparent)]">
        <x-app-logo-icon class="size-11" />
    </span>
    <span class="text-lg font-semibold tracking-[-0.02em]">{{ config('app.name') }}</span>
</div>
<script>
    (() => {
        const root = document.documentElement
        if (! root.classList.contains('splash')) return
        const hide = () => { root.classList.add('splash-out'); setTimeout(() => root.classList.remove('splash', 'splash-out'), 400) }
        const ready = () => setTimeout(hide, 350)
        document.readyState === 'complete' ? ready() : window.addEventListener('load', ready, { once: true })
        setTimeout(hide, 3000)
    })()
</script>
