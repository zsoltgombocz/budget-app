{{-- Fixed bottom bar of a wizard: stays put between steps; content scrolling behind it is blurred. --}}
<div data-action-bar {{ $attributes->class(['fixed inset-x-0 bottom-0 z-20 mx-auto flex max-w-lg gap-2.5 border-t border-line bg-bg/70 px-4 pb-[calc(1.25rem+env(safe-area-inset-bottom))] pt-3.5 backdrop-blur-xl backdrop-saturate-150']) }}>
    {{ $slot }}
</div>
