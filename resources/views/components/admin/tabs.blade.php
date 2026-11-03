{{-- $tabs: value => label, or value => [label, count]. Links keep the other filters. --}}
@props(['tabs', 'param' => 'status', 'current' => null])
<div {{ $attributes->class('adm-scroll mb-4 flex items-center gap-1 overflow-x-auto pb-1') }} role="tablist">
    @foreach ($tabs as $value => $tab)
        @php([$label, $count] = is_array($tab) ? $tab : [$tab, null])
        <a href="{{ request()->fullUrlWithQuery([$param => $value === '' ? null : $value, 'page' => null]) }}" role="tab" aria-selected="{{ (string) $current === (string) $value ? 'true' : 'false' }}"
           @class(['adm-tab', 'is-active' => (string) $current === (string) $value])>
            {{ $label }}
            @if ($count !== null)<span class="text-[11px] tabular-nums opacity-70">{{ number_format($count) }}</span>@endif
        </a>
    @endforeach
</div>
