<x-mail::message :preheader="$preheader ?? null" :stop-url="$stopUrl ?? null" :stop-label="$stopLabel ?? null" :reason="$reason ?? null" :audience="$audience ?? 'buyer'">
{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@elseif ($level === 'error')
# Something needs your attention
@else
# Hello,
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Extra blocks a notification adds (order lines, totals, facts) --}}
@isset($blocks)
@foreach ($blocks as $block)
<x-dynamic-component :component="'mail::'.$block['type']" :data="$block['data']" />

@endforeach
@endisset

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
Thanks,<br>
The Panmotive team
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
Button not working? Copy this link into your browser: <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
