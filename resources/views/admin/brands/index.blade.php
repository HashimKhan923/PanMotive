@extends('layouts.admin')

@section('title', 'Brands')

@section('content')
<x-admin.header title="Brands" subtitle="Rename brands, or merge duplicates like “ACDelco” and “AC Delco”.">
    <x-admin.action :url="route('admin.brands.store')" label="Add brand" tone="primary" icon="plus" confirm="Add">
        <div><label class="label">Name</label><input name="name" class="input text-sm" maxlength="120" required></div>
    </x-admin.action>
</x-admin.header>

<x-admin.filters :search="request('q', '')" placeholder="Brand name" />

<x-admin.card flush>
    @if ($brands->isEmpty())
        <x-admin.empty icon="tag" title="No brands match" />
    @else
        <table class="adm-table">
            <thead><tr><th>Brand</th><th class="num">Listings</th><th></th></tr></thead>
            <tbody>
                @foreach ($brands as $b)
                    <tr>
                        <td class="font-medium">{{ $b->name }}</td>
                        <td class="num">{{ number_format($b->products_count) }}</td>
                        <td class="whitespace-nowrap text-right">
                            <x-admin.action :url="route('admin.brands.update', $b)" method="PATCH" label="Rename" size="xs" confirm="Save">
                                <div><label class="label">Name</label><input name="name" value="{{ $b->name }}" class="input text-sm" maxlength="120" required></div>
                            </x-admin.action>
                            <x-admin.action :url="route('admin.brands.merge', $b)" label="Merge" size="xs" :title="'Merge '.$b->name" body="Its listings move to the brand you choose, then this brand is deleted. This can't be undone.">
                                <div><label class="label">Merge into</label>
                                    <select name="into" class="input text-sm" required>
                                        @foreach ($allBrands as $target)
                                            @if ($target->id !== $b->id)<option value="{{ $target->id }}">{{ $target->name }}</option>@endif
                                        @endforeach
                                    </select></div>
                            </x-admin.action>
                            @if ($b->products_count === 0)
                                <x-admin.action :url="route('admin.brands.destroy', $b)" method="DELETE" label="Delete" tone="danger" size="xs" />
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
<div class="mt-4">{{ $brands->links() }}</div>
@endsection
