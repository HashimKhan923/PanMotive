@extends('layouts.admin')

@section('title', 'Vehicles')

@section('content')
<x-admin.header title="Vehicles" subtitle="The make, model and year list shoppers pick from, and sellers fit parts to." />

<div class="grid gap-4 lg:grid-cols-3">
    <x-admin.card title="Makes" flush>
        <x-slot:actions>
            <x-admin.action :url="route('admin.vehicles.makes.store')" label="Add" size="xs" icon="plus" title="Add a make" confirm="Add">
                <div><label class="label">Name</label><input name="name" class="input text-sm" maxlength="80" required></div>
            </x-admin.action>
        </x-slot:actions>
        <ul class="adm-scroll max-h-[60vh] overflow-y-auto p-2">
            @forelse ($makes as $m)
                <li>
                    <a href="{{ route('admin.vehicles.index', ['make' => $m->id]) }}" @class(['adm-nav-link', 'is-active' => $make?->id === $m->id])>
                        <span class="flex-1 truncate">{{ $m->name }}</span>
                        <span class="text-xs tabular-nums opacity-70">{{ $m->models_count }}</span>
                    </a>
                </li>
            @empty
                <li class="p-4 text-center text-xs text-muted">No makes yet.</li>
            @endforelse
        </ul>
    </x-admin.card>

    <x-admin.card :title="$make ? $make->name.' models' : 'Models'" flush>
        @if ($make)
            <x-slot:actions>
                <x-admin.action :url="route('admin.vehicles.models.store')" label="Add" size="xs" icon="plus" :title="'Add a '.$make->name.' model'" confirm="Add">
                    <input type="hidden" name="vehicle_make_id" value="{{ $make->id }}">
                    <div><label class="label">Name</label><input name="name" class="input text-sm" maxlength="80" required></div>
                </x-admin.action>
                <x-admin.action :url="route('admin.vehicles.makes.destroy', $make)" method="DELETE" label="Delete make" size="xs" tone="danger" :body="'Deletes '.$make->name.' with its models and years. Refused if any listing is fitted to it.'" />
            </x-slot:actions>
        @endif
        <ul class="adm-scroll max-h-[60vh] overflow-y-auto p-2">
            @forelse ($models as $m)
                <li>
                    <a href="{{ route('admin.vehicles.index', ['make' => $make->id, 'model' => $m->id]) }}" @class(['adm-nav-link', 'is-active' => $model?->id === $m->id])>{{ $m->name }}</a>
                </li>
            @empty
                <li class="p-4 text-center text-xs text-muted">No models yet.</li>
            @endforelse
        </ul>
    </x-admin.card>

    <x-admin.card :title="$model ? $model->name.' years' : 'Years'" flush>
        @if ($model)
            <x-slot:actions>
                <x-admin.action :url="route('admin.vehicles.years.store')" label="Add years" size="xs" icon="plus" :title="'Add '.$model->name.' years'" confirm="Add">
                    <input type="hidden" name="vehicle_model_id" value="{{ $model->id }}">
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="label">From</label><input type="number" name="from" min="1900" max="{{ now()->year + 2 }}" class="input text-sm" required></div>
                        <div><label class="label">To</label><input type="number" name="to" min="1900" max="{{ now()->year + 2 }}" value="{{ now()->year + 1 }}" class="input text-sm" required></div>
                    </div>
                </x-admin.action>
                <x-admin.action :url="route('admin.vehicles.models.destroy', $model)" method="DELETE" label="Delete model" size="xs" tone="danger" :body="'Deletes '.$model->name.' and its years. Refused if any listing is fitted to it.'" />
            </x-slot:actions>
        @endif
        @if ($vehicles->isEmpty())
            <x-admin.empty icon="truck" title="No years yet" />
        @else
            <table class="adm-table">
                <thead><tr><th>Year</th><th>Engine</th><th class="num">Fitted parts</th><th></th></tr></thead>
                <tbody>
                    @foreach ($vehicles as $v)
                        <tr>
                            <td class="font-medium">{{ $v->year }}</td>
                            <td class="text-muted">{{ $v->engine?->name ?? 'Any' }}</td>
                            <td class="num">{{ number_format($fitted[$v->id] ?? 0) }}</td>
                            <td class="text-right">
                                @if (($fitted[$v->id] ?? 0) === 0)
                                    <x-admin.action :url="route('admin.vehicles.destroy', $v)" method="DELETE" label="Remove" tone="danger" size="xs" :title="'Remove '.$v->year" />
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-admin.card>
</div>
@endsection
