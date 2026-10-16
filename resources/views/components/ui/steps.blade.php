@props(['current', 'total' => 3])

{{-- Wizard progress: one bar per step. Screen readers hear it as a progress bar: "Step 2 of 3". --}}
<div {{ $attributes->class(['grid gap-1.5 px-6 pt-2']) }} style="grid-template-columns: repeat({{ $total }}, minmax(0, 1fr))"
     role="progressbar" aria-valuemin="1" aria-valuemax="{{ $total }}" aria-valuenow="{{ $current }}"
     aria-valuetext="{{ __('Step :current of :total', ['current' => $current, 'total' => $total]) }}" aria-label="{{ __('Progress') }}" data-test="steps">
    @for ($i = 1; $i <= $total; $i++)
        <div @class(['h-1 rounded-sm', 'bg-accent' => $i <= $current, 'bg-ink/10' => $i > $current])></div>
    @endfor
</div>
