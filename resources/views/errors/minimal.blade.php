<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#0E100F" media="(prefers-color-scheme: dark)">
        <meta name="theme-color" content="#F7F6F1" media="(prefers-color-scheme: light)">
        <title>@yield('title') · {{ config('app.name') }}</title>
        {{-- Self-contained on purpose: an error page must not depend on the build, the database or the session. --}}
        <style>
            :root { --bg: #F7F6F1; --surface: #FFFFFF; --ink: #121513; --muted: #5C635F; --accent: #22C26E; --accent-ink: #06210F; color-scheme: light dark; }
            @media (prefers-color-scheme: dark) { :root { --bg: #0E100F; --surface: #171A18; --ink: #F3F5F4; --muted: #9AA19D; --accent: #4BD88A; } }
            * { box-sizing: border-box; }
            body { margin: 0; min-height: 100dvh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--ink); font: 16px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; -webkit-font-smoothing: antialiased; }
            main { width: 100%; max-width: 360px; text-align: center; }
            .code { font-size: 13px; font-weight: 600; letter-spacing: .06em; color: var(--muted); }
            h1 { margin: 6px 0 0; font-size: 26px; line-height: 1.2; letter-spacing: -.02em; }
            p { margin: 10px 0 0; color: var(--muted); font-size: 15px; }
            a.button { display: inline-flex; align-items: center; justify-content: center; margin-top: 24px; min-height: 52px; padding: 0 24px; border-radius: 18px; background: var(--accent); color: var(--accent-ink); font-weight: 600; text-decoration: none; }
        </style>
    </head>
    <body>
        <main>
            <svg width="96" height="96" viewBox="0 0 96 96" aria-hidden="true" style="margin: 0 auto 12px; display: block">
                <ellipse cx="48" cy="88" rx="26" ry="4" fill="currentColor" opacity=".12"/>
                <circle cx="27" cy="26" r="10" fill="#E8A462"/><circle cx="69" cy="26" r="10" fill="#E8A462"/>
                <circle cx="27" cy="26" r="5" fill="#F5C9B3"/><circle cx="69" cy="26" r="5" fill="#F5C9B3"/>
                <ellipse cx="48" cy="56" rx="32" ry="30" fill="#E8A462"/>
                <ellipse cx="48" cy="64" rx="20" ry="18" fill="#FBEBD9"/>
                <circle cx="37" cy="46" r="3.5" fill="#2A1C12"/><circle cx="59" cy="46" r="3.5" fill="#2A1C12"/>
                <ellipse cx="48" cy="53" rx="3" ry="2.2" fill="#B5655A"/>
            </svg>
            <div class="code">@yield('code')</div>
            <h1>@yield('message')</h1>
            @hasSection('detail')<p>@yield('detail')</p>@endif
            <a class="button" href="{{ url('/') }}">{{ __('Back to the app') }}</a>
        </main>
    </body>
</html>
