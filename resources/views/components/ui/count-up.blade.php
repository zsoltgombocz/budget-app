@props(['value', 'currency' => null])

{{--
    A number that counts up from zero once, on first paint (the hero numbers). Reduced motion shows it
    right away. A count-up-replay window event runs it again (a tab that was hidden on first paint).
--}}
@php
    $value = (int) $value;
    $currency ??= user_currency();
    $major = (int) round($value / $currency->minorPerMajor());
@endphp

<span {{ $attributes }}
      x-data="{
          final: @js(money_number($value, $currency)),
          text: @js(money_number($value, $currency)),
          init() { this.run() },
          run() {
              const target = @js($major);
              if (target === 0 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
              const format = new Intl.NumberFormat(document.documentElement.lang || 'hu', { maximumFractionDigits: 0 })
              const sign = target < 0 ? '−' : ''
              const start = performance.now()
              const step = (now) => {
                  const t = Math.min(1, (now - start) / 800)
                  this.text = t < 1 ? sign + format.format(Math.round(Math.abs(target) * (1 - Math.pow(1 - t, 4)))).replace(/ /g, ' ') : this.final
                  if (t < 1) requestAnimationFrame(step)
              }
              requestAnimationFrame(step)
          },
      }"
      x-on:count-up-replay.window="run()"
      x-text="text">{{ money_number($value, $currency) }}</span>
