@props(['value'])

{{-- A number that counts up from zero once, on first paint (the hero numbers). Reduced motion shows it right away. --}}
@php
    $value = (int) $value;
    $major = (int) round($value / user_currency()->minorPerMajor());
@endphp

<span {{ $attributes }}
      x-data="{
          text: @js(money_number($value)) ,
          init() {
              const target = @js($major);
              if (target === 0 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
              const format = new Intl.NumberFormat(document.documentElement.lang || 'hu', { maximumFractionDigits: 0 })
              const sign = target < 0 ? '−' : ''
              const start = performance.now()
              const step = (now) => {
                  const t = Math.min(1, (now - start) / 800)
                  this.text = sign + format.format(Math.round(Math.abs(target) * (1 - Math.pow(1 - t, 4)))).replace(/ /g, ' ')
                  if (t < 1) requestAnimationFrame(step)
              }
              requestAnimationFrame(step)
          },
      }"
      x-text="text">{{ money_number($value) }}</span>
