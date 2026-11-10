@props(['code' => null])
{{-- A small country flag. The US flag is drawn inline because Windows doesn't show flag emoji
     (it prints the two letters instead); other countries use the emoji with the code as a title. --}}
@php($code = $code ? strtoupper($code) : null)
@if ($code === 'US')
    <svg {{ $attributes->class('h-3.5 w-auto shrink-0 rounded-[2px] ring-1 ring-black/10') }} viewBox="0 0 19 10" role="img" aria-label="United States">
        <rect width="19" height="10" fill="#fff"/>
        @foreach ([0, 2, 4, 6, 8, 10, 12] as $stripe)
            <rect y="{{ $stripe * 10 / 13 }}" width="19" height="{{ 10 / 13 }}" fill="#B22234"/>
        @endforeach
        <rect width="7.6" height="{{ 70 / 13 }}" fill="#3C3B6E"/>
        @foreach ([0.75, 2.25, 3.75, 5.25, 6.75] as $x)
            @foreach ([0.75, 2.05, 3.35, 4.65] as $y)
                <circle cx="{{ $x }}" cy="{{ $y }}" r="0.32" fill="#fff"/>
            @endforeach
        @endforeach
    </svg>
@elseif ($code && preg_match('/^[A-Z]{2}$/', $code))
    <span {{ $attributes->class('text-base leading-none') }} role="img" aria-label="{{ $code }}" title="{{ $code }}">{{ mb_chr(0x1F1E6 + ord($code[0]) - 65).mb_chr(0x1F1E6 + ord($code[1]) - 65) }}</span>
@endif
