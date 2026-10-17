@props(['label', 'error' => null])

{{--
    Form row with an inline text input; extra attributes go to the input. error: the key of the
    message in the Alpine `errors` object; the input is then marked invalid and described by it.
--}}
@php $errorId = 'error-'.\Illuminate\Support\Str::random(8); @endphp
<label class="block px-4 py-1.5 focus-within:bg-accent/10">
    <span class="flex min-h-10 items-center gap-3">
        <span class="shrink-0 text-[15px]">{{ $label }}</span>
        <input type="text" @if ($error) :aria-invalid="errors[@js($error)] ? 'true' : 'false'" :aria-describedby="errors[@js($error)] ? @js($errorId) : null" @endif {{ $attributes->class(['min-w-0 flex-1 bg-transparent text-right text-[15px] text-ink-2 outline-none placeholder:text-muted']) }}>
    </span>
    @if ($error)<span id="{{ $errorId }}" class="block pb-1 text-right text-xs text-danger" x-show="errors[@js($error)]" x-text="errors[@js($error)]"></span>@endif
</label>
