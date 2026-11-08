@extends('layouts.admin')

@section('title', 'Homepage banners')

@section('content')
<x-admin.header title="Homepage banners" subtitle="Animated banners on the homepage. Put several in the same spot and they play as a slider, in the order you set.">
    <a href="{{ route('admin.banners.create') }}" class="btn-accent btn-sm"><x-ui.icon name="plus" class="w-4 h-4" /> New banner</a>
</x-admin.header>

<x-admin.card flush>
    @if ($banners->isEmpty())
        <x-admin.empty icon="image" title="No banners yet" text="Add one to promote a sale, a category or selling on Panmotive." />
    @else
        <table class="adm-table">
            <thead><tr><th>Banner</th><th>Where</th><th>Order</th><th>Status</th><th>Dates</th><th></th></tr></thead>
            <tbody>
                @foreach ($banners as $b)
                    @php($state = $b->state())
                    <tr>
                        <td>
                            <a href="{{ route('admin.banners.edit', $b) }}" class="flex items-center gap-3">
                                <span class="relative grid h-11 w-20 shrink-0 place-items-center overflow-hidden rounded-lg ring-1 ring-line
                                             {{ ['charcoal' => 'bg-brand', 'red' => 'bg-accent', 'light' => 'bg-surface-2'][$b->style] ?? 'bg-brand' }}">
                                    @if ($b->imageUrl())
                                        <img src="{{ $b->imageUrl() }}" alt="" class="absolute inset-0 h-full w-full object-cover">
                                    @else
                                        <x-ui.logo variant="symbol" :on-red="$b->style === 'red'" class="h-5 w-auto" />
                                    @endif
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate font-medium hover:text-accent">{{ $b->title }}</span>
                                    <span class="block truncate text-xs text-muted">{{ \App\Models\Banner::STYLES[$b->style] ?? $b->style }}@if ($b->button_url) · {{ $b->button_label }} → {{ \Illuminate\Support\Str::limit($b->button_url, 40) }}@endif</span>
                                </span>
                            </a>
                        </td>
                        <td class="text-muted">{{ $b->placementLabel() }}</td>
                        <td class="tabular-nums text-muted">{{ $b->sort_order }}</td>
                        <td><x-admin.status :value="['live' => 'active', 'scheduled' => 'scheduled', 'ended' => 'expired', 'off' => 'off'][$state]" :label="ucfirst($state)" /></td>
                        <td class="whitespace-nowrap text-xs text-muted">
                            {{ $b->starts_at?->format('M j, g:ia') ?? 'Now' }} → {{ $b->ends_at?->format('M j, g:ia') ?? 'No end' }}
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <a href="{{ route('admin.banners.edit', $b) }}" class="mr-1 text-xs font-medium text-muted hover:text-accent">Edit</a>
                            <x-admin.action :url="route('admin.banners.destroy', $b)" method="DELETE" label="Delete" tone="danger" size="xs" :title="'Delete '.$b->title" body="The banner and its photo are removed. This can't be undone." />
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
@endsection
