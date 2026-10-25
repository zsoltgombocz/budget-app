{{-- The waitlist form of the landing page (hero and closing section). After sending, the status replaces it. --}}
@if ($sent)
    <p class="waitlist-status" role="status">Elküldtük a megerősítő levelet. Kattints a benne lévő linkre, és felkerülsz a listára.</p>
@else
    <form method="POST" action="{{ route('waitlist.store') }}" class="waitlist" novalidate>
        @csrf
        <label class="sr-only" for="{{ $id }}">E-mail címed</label>
        <input id="{{ $id }}" type="email" name="email" value="{{ old('email') }}" placeholder="pelda@email.hu" autocomplete="email" required>
        <input class="honeypot" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
        <button type="submit" class="button">Feliratkozom</button>
    </form>
    @if ($id === 'waitlist-email')
        @error('email')
            <p class="waitlist-error" role="alert">{{ $message }}</p>
        @enderror
    @endif
@endif
