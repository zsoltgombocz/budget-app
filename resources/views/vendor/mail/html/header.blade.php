@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" class="brand" style="display: inline-block;">
<img src="{{ rtrim(config('app.url'), '/') }}/icons/icon-192.png" width="40" height="40" class="logo" alt="">
<span class="brand-name">{{ trim($slot) }}</span>
</a>
</td>
</tr>
