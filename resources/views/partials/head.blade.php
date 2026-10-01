<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

<title>
    {{ filled($title ?? null) ? __($title).' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0C0E0D" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#F3F4F2" media="(prefers-color-scheme: light)">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
@auth
    <meta name="vapid-public-key" content="{{ config('webpush.vapid.public_key') }}">
    <meta name="push-subscription-url" content="{{ route('push-subscriptions.store') }}">
@endauth

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@vite(['resources/css/app.css', 'resources/js/app.js'])
{{-- Dark is the primary theme; light or system can be chosen under Settings → Appearance. --}}
<script>try { if (! localStorage.getItem('flux.appearance')) localStorage.setItem('flux.appearance', 'dark') } catch (e) {}</script>
@fluxAppearance
