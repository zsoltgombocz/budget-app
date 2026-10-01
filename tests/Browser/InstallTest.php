<?php

it('offers "Open in Safari" in Chrome on iPhone', function (): void {
    $page = visit(route('install'))->withUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/126.0.6478.54 Mobile/15E148 Safari/604.1');

    $page->assertSee('Edit Actions')
        ->assertAttributeContains('[data-test="open-in-safari"]', 'href', 'x-safari-http');
});

it('shows the Safari steps on iPhone Safari', function (): void {
    visit(route('install'))->withUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1')
        ->assertSee('at the bottom of Safari');
});

it('offers "Open in Chrome" in Samsung Internet', function (): void {
    visit(route('install'))->withUserAgent('Mozilla/5.0 (Linux; Android 14; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36')
        ->assertAttributeContains('[data-test="open-in-chrome"]', 'href', 'package=com.android.chrome');
});
