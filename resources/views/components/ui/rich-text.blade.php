@props(['content'])
@php
    $content = (string) ($content ?? '');
    $isHtml = (bool) preg_match('/<[a-z][^>]*>/i', $content);
@endphp
@if ($content !== '')
    <div {{ $attributes->class(['prose-pm']) }}>
        @if ($isHtml)
            {{-- Sanitized at write time by App\Support\Html\DescriptionSanitizer — safe to render raw. --}}
            {!! $content !!}
        @else
            {!! nl2br(e($content)) !!}
        @endif
    </div>
@endif
