@props(['preheader' => null, 'stopUrl' => null, 'stopLabel' => null, 'reason' => null, 'audience' => 'buyer'])
<x-mail::layout :preheader="$preheader">
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer :stop-url="$stopUrl" :stop-label="$stopLabel" :reason="$reason" :audience="$audience" />
</x-slot:footer>
</x-mail::layout>
