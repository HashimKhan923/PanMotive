{{-- Ranked magnitude in one hue, with the value written out beside each bar. --}}
@props(['rows' => [], 'prefix' => '', 'decimals' => 0, 'empty' => 'No data for this period yet.'])
@php
    $rows = collect($rows);
    $max = max(1, (float) $rows->max('value'));
@endphp
@if ($rows->isEmpty())
    <p class="py-6 text-center text-xs text-muted">{{ $empty }}</p>
@else
    <ul {{ $attributes->class('space-y-3') }}>
        @foreach ($rows as $i => $row)
            <li>
                <div class="flex items-baseline justify-between gap-3 text-[13px]">
                    @if (! empty($row['href']))
                        <a href="{{ $row['href'] }}" class="min-w-0 truncate font-medium hover:text-accent">{{ $row['label'] }}</a>
                    @else
                        <span class="min-w-0 truncate font-medium">{{ $row['label'] }}</span>
                    @endif
                    <span class="shrink-0 tabular-nums text-muted">{{ $prefix }}{{ number_format((float) $row['value'], $decimals) }}</span>
                </div>
                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-surface-2" title="{{ $row['label'] }}: {{ $prefix }}{{ number_format((float) $row['value'], $decimals) }}">
                    <div class="adm-bar h-full rounded-full bg-accent" style="width: {{ round((float) $row['value'] / $max * 100, 1) }}%; animation-delay: {{ $i * 70 }}ms"></div>
                </div>
            </li>
        @endforeach
    </ul>
@endif
