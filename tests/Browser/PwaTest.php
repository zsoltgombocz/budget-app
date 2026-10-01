<?php

it('installs the service worker with the offline fallback precached', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('dashboard'))->on()->mobile();

    $page->assertNoJavaScriptErrors();

    // Start from a clean install: the browser context may be reused between tests.
    $result = $page->script(<<<'JS'
        async () => {
            for (const registration of await navigator.serviceWorker.getRegistrations()) {
                await registration.unregister()
            }
            for (const key of await caches.keys()) {
                await caches.delete(key)
            }

            // A unique script URL forces a real install even if a previous worker is still active.
            const registration = await navigator.serviceWorker.register('/sw.js?install=' + Date.now())
            await new Promise(resolve => setTimeout(resolve, 50))
            for (let i = 0; i < 50 && ! (await caches.match('/offline.html')); i++) {
                await new Promise(resolve => setTimeout(resolve, 100))
            }

            return {
                scope: registration.scope === location.origin + '/',
                offline: Boolean(await caches.match('/offline.html')),
                icon: Boolean(await caches.match('/icons/icon-192.png')),
            }
        }
    JS);

    expect($result)->toBe(['scope' => true, 'offline' => true, 'icon' => true]);
});
