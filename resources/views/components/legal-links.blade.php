{{-- Links to the public legal pages, for the sign-in pages and footers. --}}
<nav {{ $attributes->class(['flex flex-wrap justify-center gap-x-4 gap-y-1 text-[13px] text-muted']) }} aria-label="{{ __('Legal documents') }}">
    <a href="{{ route('legal', 'aszf') }}" class="underline-offset-2 hover:underline">{{ __('Terms of service') }}</a>
    <a href="{{ route('legal', 'adatvedelem') }}" class="underline-offset-2 hover:underline">{{ __('Privacy policy') }}</a>
    <a href="{{ route('legal', 'impresszum') }}" class="underline-offset-2 hover:underline">{{ __('Imprint') }}</a>
</nav>
