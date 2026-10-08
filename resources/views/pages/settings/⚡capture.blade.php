<?php

use App\Actions\Capture\CreateCaptureToken;
use App\Enums\LineType;
use App\Models\CaptureToken;
use App\Models\PaymentCapture;
use App\Models\User;
use App\Services\PeriodService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Automatic capture')] class extends Component {
    /** The key just created: shown once, never stored in plain text. */
    public ?string $newToken = null;

    public ?int $categoryId = null;

    public function mount(): void
    {
        $this->categoryId = $this->user()->settings()->capture_category_id;
    }

    public function createToken(string $platform): void
    {
        $this->newToken = app(CreateCaptureToken::class)->handle($this->user(), $platform === 'android' ? 'Android' : 'iPhone');
        unset($this->tokens);
    }

    public function deleteToken(int $tokenId): void
    {
        $this->user()->captureTokens()->whereKey($tokenId)->delete();
        $this->newToken = null;
        unset($this->tokens);

        $this->dispatch('app-toast', title: __('Key deleted. The phone using it can no longer send payments.'), icon: 'delete');
    }

    public function updatedCategoryId(): void
    {
        $this->validate([
            'categoryId' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $this->user()->id)->where('type', LineType::Variable->value)->whereNull('deleted_at')],
        ]);

        $this->user()->settings()->update(['capture_category_id' => $this->categoryId]);
    }

    /**
     * @return Collection<int, CaptureToken>
     */
    #[Computed]
    public function tokens(): Collection
    {
        return $this->user()->captureTokens()->latest('id')->get();
    }

    #[Computed]
    public function lastCapture(): ?PaymentCapture
    {
        return $this->user()->paymentCaptures()->with('transaction.category')->latest('id')->first();
    }

    /**
     * @return Collection<int, \App\Models\Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return $this->user()->categories()->where('type', LineType::Variable)->orderBy('sort')->orderBy('name')->get();
    }

    public function refreshStatus(): void
    {
        unset($this->lastCapture, $this->tokens);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $endpoint = route('api.captures.store');
    $shortcutUrl = config('services.capture.ios_shortcut_url');
    $last = $this->lastCapture;
    $settings = auth()->user()->settings();
    $today = app(PeriodService::class)->today($settings);
    $androidBody = '{"text":"[notification]","title":"[not_title]","app":"[not_app_name]","platform":"android"}';
    $step = fn (string $number, string $html) => '<li class="flex gap-3"><span class="num flex size-6 shrink-0 items-center justify-center rounded-full bg-accent/14 text-xs font-semibold text-accent">'.$number.'</span><span class="min-w-0 pt-0.5">'.$html.'</span></li>';
    $b = fn (string $text) => '<b class="font-semibold text-ink">'.e($text).'</b>';
@endphp

<x-pages::settings.layout :heading="__('Automatic capture')" :subheading="__('Card payments from your phone')">
    <div class="flex flex-col gap-3"
         x-data="{
            platform: /android/i.test(navigator.userAgent) ? 'android' : 'ios',
            copied: null,
            copy(value, key) {
                navigator.clipboard?.writeText(value)
                this.copied = key
                setTimeout(() => this.copied = null, 2000)
            },
         }">
        <p class="px-1.5 text-[13px] leading-snug text-muted">{{ __('When you pay with your phone or card, the phone sends the amount and the shop here, and it is recorded as a spending without typing. You can move it to another category or delete it with one tap.') }}</p>

        {{-- Status --}}
        <div @if ($this->tokens->isNotEmpty()) wire:poll.5s="refreshStatus" @endif>
        <x-ui.card class="p-[18px]" data-test="capture-status">
            <div class="flex items-start gap-3">
                <x-ui.icon-tile :icon="$last ? 'check_circle' : 'bolt'" :tone="$last ? 'accent' : 'muted'" />
                <div class="min-w-0 flex-1">
                    @if ($last)
                        <div class="text-[15px] font-semibold">{{ __('Last arrival') }}</div>
                        <div class="num mt-0.5 text-[13px] text-muted">{{ \App\Support\Dates::day($last->created_at->setTimezone($settings->timezone), $today) }} {{ $last->created_at->setTimezone($settings->timezone)->format('H:i') }}</div>
                        <div class="mt-1.5 text-sm text-ink-2">
                            @if ($last->status === \App\Enums\CaptureStatus::Test)
                                {{ __('Connection test: the phone reaches MoneySight.') }}
                            @else
                                {{ $last->merchant ?? __('Card payment') }} · <span class="num">{{ $last->formattedAmount() }}</span> · {{ $last->status->label() }}
                                @if ($last->reason && $last->status !== \App\Enums\CaptureStatus::Recorded)
                                    <span class="mt-0.5 block text-xs text-muted">{{ $last->reason->label() }}</span>
                                @endif
                            @endif
                        </div>
                    @else
                        <div class="text-[15px] font-semibold">{{ __('Nothing has arrived yet') }}</div>
                        <div class="mt-0.5 text-[13px] leading-snug text-muted">{{ __('Set up your phone below, then run the automation once by hand or pay with your card. This box shows it within a few seconds.') }}</div>
                    @endif
                </div>
            </div>
        </x-ui.card>
        </div>

        {{-- Keys --}}
        <x-ui.card class="p-[18px]">
            <div class="text-[15px] font-semibold">{{ __('Keys') }}</div>
            <div class="mt-0.5 text-xs leading-snug text-muted">{{ __('The phone signs its requests with a key. Delete a key and that phone can no longer send anything.') }}</div>

            @if ($newToken)
                <div class="mt-3 rounded-2xl border border-accent/30 bg-accent/10 p-3.5" data-test="new-token">
                    <div class="text-[13px] font-semibold text-accent">{{ __('Copy it now: it is shown only once.') }}</div>
                    <div class="num mt-2 break-all rounded-xl bg-bg px-3 py-2.5 text-[13px] text-ink">{{ $newToken }}</div>
                    <x-ui.button size="sm" variant="light" class="mt-2.5" x-on:click="copy(@js($newToken), 'token')">
                        <span x-show="copied !== 'token'">{{ __('Copy the key') }}</span><span x-show="copied === 'token'" x-cloak>{{ __('Copied') }}</span>
                    </x-ui.button>
                </div>
            @endif

            @foreach ($this->tokens as $token)
                <div class="flex items-center gap-3 border-b border-line py-3 last:border-b-0" wire:key="token-{{ $token->id }}" data-test="capture-token">
                    <x-ui.icon-tile icon="smartphone" :size="36" />
                    <div class="min-w-0 flex-1">
                        <div class="text-[15px]">{{ $token->name }}</div>
                        <div class="text-xs text-muted">
                            {{ $token->last_used_at ? __('Last used :date', ['date' => \App\Support\Dates::day($token->last_used_at->setTimezone($settings->timezone), $today).' '.$token->last_used_at->setTimezone($settings->timezone)->format('H:i')]) : __('Not used yet') }}
                        </div>
                    </div>
                    <button type="button" class="text-faint" aria-label="{{ __('Delete key') }}"
                            x-on:click="if (await window.appConfirm({ title: @js(__('Delete this key?')), body: @js(__('The phone using it can no longer send payments. Payments already recorded stay.')), confirm: @js(__('Delete')), danger: true })) $wire.deleteToken({{ $token->id }})"
                            data-test="delete-token">
                        <x-ui.icon name="delete" :size="20" />
                    </button>
                </div>
            @endforeach

            <div class="mt-3 grid grid-cols-2 gap-2">
                <x-ui.button size="md" variant="secondary" icon="add" wire:click="createToken('ios')" data-test="create-token-ios">{{ __('Key for iPhone') }}</x-ui.button>
                <x-ui.button size="md" variant="secondary" icon="add" wire:click="createToken('android')" data-test="create-token-android">{{ __('Key for Android') }}</x-ui.button>
            </div>
            @error('name')<p class="mt-2 text-xs text-danger">{{ $message }}</p>@enderror
        </x-ui.card>

        {{-- Default category --}}
        <div class="overflow-hidden rounded-card bg-surface">
            <x-ui.select-row :label="__('Category for new shops')" :hint="__('Once you move a shop’s payment to another category, its next payments go there by themselves.')" wire:model.live="categoryId" data-test="capture-category">
                <option value="">{{ __('First quick entry category') }}</option>
                @foreach ($this->categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </x-ui.select-row>
        </div>

        {{-- Setup guide --}}
        <x-ui.card class="p-[18px]" data-test="capture-guide">
            <div class="text-[15px] font-semibold">{{ __('Set up your phone') }}</div>
            <x-ui.segmented model="platform" :options="['ios' => 'iPhone', 'android' => 'Android']" class="mt-3" />

            <div class="mt-4 text-sm leading-snug text-ink-2">
                {{-- iPhone: Shortcuts personal automation with the Wallet "Transaction" trigger (iOS 17+). --}}
                <div x-show="platform === 'ios'" class="flex flex-col gap-3" data-test="guide-ios">
                    <p class="text-xs text-muted">{{ __('Needs iOS 17 or newer and Apple Pay. Works for payments made with the iPhone or Apple Watch.') }}</p>
                    <ol class="flex flex-col gap-2.5">
                        {!! $step('1', __('Tap :button above and copy the key.', ['button' => $b(__('Key for iPhone'))])) !!}
                        @if ($shortcutUrl)
                            {!! $step('2', __('Add the MoneySight shortcut and paste the key when it asks for it.')) !!}
                            {!! $step('3', __('In the Shortcuts app open :automation → :plus → :transaction. Choose your cards, set :immediately, then choose the :shortcut shortcut.', ['automation' => $b(__('Automation')), 'plus' => $b('+'), 'transaction' => $b(__('Transaction')), 'immediately' => $b(__('Run Immediately')), 'shortcut' => $b('MoneySight')])) !!}
                            {!! $step('4', __('Pay once, or run the shortcut by hand: the box at the top shows when it arrives.')) !!}
                        @else
                            {!! $step('2', __('In the Shortcuts app open :automation → :plus → :transaction. Choose your cards and set :immediately.', ['automation' => $b(__('Automation')), 'plus' => $b('+'), 'transaction' => $b(__('Transaction')), 'immediately' => $b(__('Run Immediately'))])) !!}
                            {!! $step('3', __('Create a new blank shortcut and add the :action action with the address below.', ['action' => $b(__('Get Contents of URL'))])) !!}
                            {!! $step('4', __('Tap the arrow next to it: method :post, add a header :header with the value :value, and a JSON request body with three text fields.', ['post' => $b('POST'), 'header' => $b('Authorization'), 'value' => $b(__('Bearer and your key'))])) !!}
                            {!! $step('5', __(':amount: Shortcut Input → Amount, :merchant: Shortcut Input → Merchant, :platform: ios.', ['amount' => $b('amount'), 'merchant' => $b('merchant'), 'platform' => $b('platform')])) !!}
                            {!! $step('6', __('Pay once, or run the automation by hand: the box at the top shows when it arrives.')) !!}
                        @endif
                    </ol>
                    @if ($shortcutUrl)
                        <x-ui.button size="md" icon="open_in_new" :href="$shortcutUrl" target="_blank" rel="noopener" class="w-full" data-test="shortcut-link">{{ __('Add the shortcut') }}</x-ui.button>
                    @endif
                </div>

                {{-- Android: MacroDroid forwards bank notifications until there is a native app. --}}
                <div x-show="platform === 'android'" x-cloak class="flex flex-col gap-3" data-test="guide-android">
                    <p class="text-xs text-muted">{{ __('A web app cannot read notifications, so the free MacroDroid app forwards the payment notifications of the apps you choose. Nothing else is sent.') }}</p>
                    <ol class="flex flex-col gap-2.5">
                        {!! $step('1', __('Tap :button above and copy the key.', ['button' => $b(__('Key for Android'))])) !!}
                        {!! $step('2', __('Install MacroDroid from Google Play and give it notification access when it asks.')) !!}
                        {!! $step('3', __('New macro → trigger :trigger → :received. Choose your bank apps and Google Wallet.', ['trigger' => $b(__('Notification')), 'received' => $b(__('Notification Received'))])) !!}
                        {!! $step('4', __('Action :action: method :post, the address below, header :header with the value :value, content type :type.', ['action' => $b(__('HTTP Request')), 'post' => $b('POST'), 'header' => $b('Authorization'), 'value' => $b(__('Bearer and your key')), 'type' => $b('application/json')])) !!}
                        {!! $step('5', __('Paste the body below. If the placeholders do not fill in, insert the notification text, title and app name with the :magic button.', ['magic' => $b('…')])) !!}
                        {!! $step('6', __('Turn off battery optimisation for MacroDroid, then pay once: the box at the top shows when it arrives.')) !!}
                    </ol>
                    <div class="rounded-xl bg-bg p-3">
                        <div class="text-xs text-muted">{{ __('Request body') }}</div>
                        <div class="num mt-1 break-all text-[12px] text-ink">{{ $androidBody }}</div>
                        <button type="button" class="mt-2 text-[13px] font-medium text-accent" x-on:click="copy(@js($androidBody), 'body')">
                            <span x-show="copied !== 'body'">{{ __('Copy') }}</span><span x-show="copied === 'body'" x-cloak>{{ __('Copied') }}</span>
                        </button>
                    </div>
                </div>

                <div class="mt-3 rounded-xl bg-bg p-3">
                    <div class="text-xs text-muted">{{ __('Address') }}</div>
                    <div class="num mt-1 break-all text-[13px] text-ink" data-test="capture-endpoint">{{ $endpoint }}</div>
                    <button type="button" class="mt-2 text-[13px] font-medium text-accent" x-on:click="copy(@js($endpoint), 'endpoint')">
                        <span x-show="copied !== 'endpoint'">{{ __('Copy') }}</span><span x-show="copied === 'endpoint'" x-cloak>{{ __('Copied') }}</span>
                    </button>
                </div>
            </div>
        </x-ui.card>

        {{-- What happens to a payment --}}
        <x-ui.card class="p-[18px] text-[13px] leading-snug text-muted">
            <div class="text-[15px] font-semibold text-ink">{{ __('What happens to a payment') }}</div>
            <ul class="mt-2 flex list-disc flex-col gap-1.5 pl-4">
                <li>{{ __('A card payment in your currency is recorded at once and counts in the expected leftover and in what you can still spend today.') }}</li>
                <li>{{ __('A payment in another currency, a refund, a possible duplicate of what you typed in, or one from a closed month waits on the Today screen until you decide. Until then it does not count.') }}</li>
                <li>{{ __('Incoming money, transfers, cash withdrawals and declined payments are skipped. Nothing is ever moved between pockets.') }}</li>
                <li>{{ __('We keep only the amount, currency, shop and time. The full notification text is kept for at most 7 days to fix reading errors, then deleted.') }}</li>
            </ul>
        </x-ui.card>
    </div>
</x-pages::settings.layout>
