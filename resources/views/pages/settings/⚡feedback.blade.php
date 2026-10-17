<?php

use App\Models\User;
use App\Services\BugReporter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Contact')] class extends Component {
    use WithFileUploads;

    public string $message = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $screenshot = null;

    /** The page the user came from, sent along with the report. */
    #[Locked]
    public ?string $fromUrl = null;

    public bool $sent = false;

    /** 'bug' or 'idea', from the route. */
    #[Locked]
    public string $kind = 'bug';

    public function mount(string $kind = 'bug'): void
    {
        $this->kind = $kind === 'idea' ? 'idea' : 'bug';

        $this->fromUrl = $this->kind === 'bug' ? $this->sameSiteUrl(request()->query('honnan')) : null;
    }

    public function send(BugReporter $reporter): void
    {
        try {
            $this->validate([
                'message' => ['required', 'string', 'min:5', 'max:4000'],
                'screenshot' => ['nullable', 'image', 'max:5120'],
            ]);
        } catch (ValidationException $exception) {
            // Focus goes to the message field, which is described by the error under it.
            $this->js("document.getElementById('feedback-message')?.focus()");

            throw $exception;
        }

        $key = 'bug-report:'.$this->user()->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('message', __('You have sent several reports in a short time. Try again in an hour.'));

            return;
        }

        try {
            $context = $this->kind === 'bug' ? ['url' => $this->fromUrl, 'user_agent' => request()->userAgent()] : [];
            $reporter->send($this->user(), trim($this->message), $this->screenshot, $context, $this->kind);
        } catch (RuntimeException $e) {
            report($e);
            $this->addError('message', __('The report could not be sent. Try again later.'));

            return;
        }

        RateLimiter::hit($key, 3600);
        $this->reset('message', 'screenshot');
        $this->sent = true;
    }

    /**
     * The page title follows the kind: "Report a bug" or "Share an idea".
     */
    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__($this->kind === 'idea' ? 'Share an idea' : 'Report a bug'));
    }

    public function removeScreenshot(): void
    {
        $this->screenshot = null;
    }

    /**
     * The page the user came from, only if it is a page of this app.
     */
    private function sameSiteUrl(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, url('/').'/') ? $url : null;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $idea = $kind === 'idea';
@endphp

<x-pages::settings.layout back="contact" :heading="$idea ? __('Share an idea') : __('Report a bug')" :subheading="$idea ? __('What would make the app better for you?') : __('Something not working? Tell us.')">
    @if ($sent)
        <x-ui.card class="flex flex-col items-center gap-2.5 px-6 py-8 text-center" data-test="bug-report-sent">
            <x-ui.icon-tile icon="check" tone="accent" :size="56" />
            <div class="mt-1 text-lg font-semibold">{{ $idea ? __('Thanks, idea sent') : __('Thanks, report sent') }}</div>
            <div class="text-sm text-pretty text-muted">{{ $idea ? __('We read every idea and write back if we have a question.') : __('We look into it and let you know when the fix is out.') }}</div>
            <x-ui.button variant="secondary" class="mt-3" wire:click="$set('sent', false)">{{ $idea ? __('Send another idea') : __('Report another') }}</x-ui.button>
        </x-ui.card>
    @else
        <form wire:submit="send" class="flex flex-col gap-3" data-test="bug-report-form">
            <x-ui.card class="p-[18px]">
                <label class="block">
                    <span class="mb-1.5 block text-[13px] text-muted">{{ $idea ? __('Your idea') : __('What happened, and what did you expect?') }}</span>
                    <textarea wire:model="message" rows="6" maxlength="4000" id="feedback-message" @error('message') aria-invalid="true" aria-describedby="feedback-message-error" @enderror
                              class="block w-full resize-none rounded-[14px] bg-surface-2 px-4 py-3 text-[15px] text-ink outline-none placeholder:text-muted focus:ring-2 focus:ring-accent"
                              placeholder="{{ $idea ? __('E.g. I would like to see last month next to this one.') : __('E.g. on the closing screen the Next button does nothing.') }}" data-test="bug-report-message"></textarea>
                </label>
                @error('message')<span id="feedback-message-error" class="mt-1.5 block text-xs text-danger" role="alert">{{ $message }}</span>@enderror

                <div class="mt-3">
                    @if ($screenshot)
                        <div class="flex min-h-12 items-center gap-3 rounded-[14px] bg-surface-2 pl-4 pr-1 text-sm">
                            <x-ui.icon name="add_photo_alternate" :size="20" class="text-accent" />
                            <span class="min-w-0 flex-1 truncate">{{ $screenshot->getClientOriginalName() }}</span>
                            <button type="button" wire:click="removeScreenshot" class="focus-ring flex size-11 shrink-0 items-center justify-center rounded-xl text-muted" aria-label="{{ __('Remove') }}" data-test="remove-screenshot"><x-ui.icon name="close" :size="18" /></button>
                        </div>
                    @else
                        <label class="flex cursor-pointer items-center gap-3 rounded-[14px] bg-surface-2 px-4 py-3 text-sm text-ink-2">
                            <x-ui.icon name="add_photo_alternate" :size="20" />
                            <span wire:loading.remove wire:target="screenshot">{{ __('Add a screenshot (optional)') }}</span>
                            <span wire:loading wire:target="screenshot">{{ __('Uploading…') }}</span>
                            <input type="file" accept="image/*" wire:model="screenshot" class="hidden" data-test="bug-report-screenshot">
                        </label>
                    @endif
                    @error('screenshot')<span class="mt-1.5 block text-xs text-danger">{{ $message }}</span>@enderror
                </div>
            </x-ui.card>

            <div class="px-1.5 text-xs text-pretty text-muted">{{ $idea ? __('Sent with it: your e-mail address, so we can write back. Nothing from your budget.') : __('Sent with it: your e-mail address, the page you came from, the app version and your browser. Nothing from your budget.') }}</div>

            <x-ui.button type="submit" :icon="$idea ? 'lightbulb' : 'bug_report'" class="w-full" wire:loading.attr="disabled" wire:target="send,screenshot" data-test="bug-report-send">{{ $idea ? __('Send idea') : __('Send report') }}</x-ui.button>
        </form>
    @endif
</x-pages::settings.layout>
