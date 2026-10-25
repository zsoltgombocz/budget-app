<x-layouts::auth :title="__('MoneySight 2.0')">
    <div class="flex flex-col gap-4 text-center" data-test="waitlist-{{ $state }}">
        @if ($state === 'confirmed')
            <h1 class="text-2xl font-semibold tracking-[-0.02em]">{{ __('You are on the list') }}</h1>
            <p class="text-[15px] text-muted">{{ __('We write to you when MoneySight 2.0 launches. Nothing else.') }}</p>
            <a href="{{ route('waitlist.unsubscribe', $token) }}" class="text-[13px] text-muted underline underline-offset-2">{{ __('Unsubscribe and delete my address') }}</a>
        @elseif ($state === 'removed')
            <h1 class="text-2xl font-semibold tracking-[-0.02em]">{{ __('Your address is deleted') }}</h1>
            <p class="text-[15px] text-muted">{{ __('We will not write to you.') }}</p>
        @else
            <h1 class="text-2xl font-semibold tracking-[-0.02em]">{{ __('This link no longer works') }}</h1>
            <p class="text-[15px] text-muted">{{ __('Sign up again on the main page if you want to hear about the launch.') }}</p>
        @endif
        <a href="{{ route('home') }}" class="font-medium text-accent">{{ __('Back to the main page') }}</a>
    </div>
</x-layouts::auth>
