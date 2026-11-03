{{-- Marketplace health rows: the number, a word for how it's doing, and what it means. --}}
@props(['rows'])
<ul {{ $attributes->class('space-y-3.5') }}>
    @foreach ($rows as $row)
        <li>
            <div class="flex items-center justify-between gap-3 text-[13px]">
                <span class="font-medium">{{ $row['label'] }}</span>
                <span class="flex items-center gap-2">
                    <span class="font-semibold tabular-nums">{{ $row['value'] === null ? '—' : number_format($row['value'], 1).$row['suffix'] }}</span>
                    <x-admin.status :value="['good' => 'on', 'watch' => 'warning', 'poor' => 'failed', 'none' => 'none'][$row['rating']]" :label="['good' => 'Good', 'watch' => 'Watch', 'poor' => 'High risk', 'none' => 'No data'][$row['rating']]" />
                </span>
            </div>
            <p class="mt-0.5 text-xs text-muted">{{ $row['hint'] }}</p>
        </li>
    @endforeach
</ul>
