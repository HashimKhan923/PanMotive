@props(['title' => null, 'subtitle' => null, 'flush' => false])
<section {{ $attributes->class('adm-card') }}>
    @if ($title || isset($actions))
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line/60 px-5 py-3.5">
            <div class="min-w-0">
                <h2 class="text-sm font-semibold">{{ $title }}</h2>
                @if ($subtitle)<p class="mt-0.5 text-xs text-muted">{{ $subtitle }}</p>@endif
            </div>
            @isset($actions)<div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>@endisset
        </div>
    @endif
    <div @class(['relative', 'p-5' => ! $flush, 'overflow-x-auto' => $flush])>{{ $slot }}</div>
</section>
