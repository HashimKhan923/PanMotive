@extends('layouts.admin')

@section('title', 'Categories')

@section('content')
<x-admin.header title="Categories" subtitle="The tree shoppers browse. Empty categories stay hidden from the site by themselves.">
    <x-admin.action :url="route('admin.categories.store')" label="Add category" tone="primary" icon="plus" confirm="Add">
        <div><label class="label">Name</label><input name="name" class="input text-sm" maxlength="120" required></div>
        <div><label class="label">Inside</label>
            <select name="parent_id" class="input text-sm">
                <option value="">Nothing (main category)</option>
                @foreach ($roots as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach
            </select></div>
    </x-admin.action>
</x-admin.header>

<div class="space-y-3">
    @foreach ($roots as $root)
        <x-admin.card flush>
            <table class="adm-table">
                <tbody>
                    @foreach ([$root, ...$root->kids] as $c)
                        <tr>
                            <td class="{{ $c->parent_id ? 'pl-10' : 'font-semibold' }}">
                                {{ $c->name }}
                                <p class="text-xs font-normal text-muted">/{{ $c->slug }}</p>
                            </td>
                            <td class="whitespace-nowrap">
                                @if (! $c->is_active)
                                    <x-admin.status value="off" label="Hidden" />
                                @elseif ($c->live === 0)
                                    <x-admin.status value="none" label="Empty, not shown" />
                                @else
                                    <x-admin.status value="active" label="Showing" />
                                @endif
                            </td>
                            <td class="num whitespace-nowrap text-muted">{{ number_format($c->live) }} live</td>
                            <td class="whitespace-nowrap text-right">
                                <x-admin.action :url="route('admin.categories.update', $c)" method="PATCH" label="Edit" size="xs" confirm="Save" :title="'Edit '.$c->name">
                                    <div><label class="label">Name</label><input name="name" value="{{ $c->name }}" class="input text-sm" maxlength="120" required></div>
                                    <div><label class="label">Inside</label>
                                        <select name="parent_id" class="input text-sm" @disabled($c->kids?->isNotEmpty())>
                                            <option value="">Nothing (main category)</option>
                                            @foreach ($roots as $r)
                                                @if ($r->id !== $c->id)<option value="{{ $r->id }}" @selected($c->parent_id === $r->id)>{{ $r->name }}</option>@endif
                                            @endforeach
                                        </select></div>
                                    <div><label class="label">Sort order <span class="font-normal text-muted">(lower shows first)</span></label><input type="number" name="sort_order" min="0" max="9999" value="{{ $c->sort_order }}" class="input text-sm"></div>
                                    <div><label class="label">Description</label><textarea name="description" rows="2" maxlength="2000" class="input text-sm">{{ $c->description }}</textarea></div>
                                    <label class="flex items-center gap-2 text-[13px]"><input type="checkbox" name="is_active" value="1" @checked($c->is_active) class="rounded text-accent focus:ring-accent"> Show on the site</label>
                                </x-admin.action>
                                <x-admin.action :url="route('admin.categories.destroy', $c)" method="DELETE" label="Delete" tone="danger" size="xs" :title="'Delete '.$c->name" :body="$c->kids?->isNotEmpty() ? 'Move or delete its subcategories first.' : 'Its listings move to the category you choose.'">
                                    <div><label class="label">Move its listings to</label>
                                        <select name="move_to" class="input text-sm">
                                            <option value="">It has no listings</option>
                                            @foreach ($all as $target)
                                                @if ($target->id !== $c->id)<option value="{{ $target->id }}">{{ $target->name }}</option>@endif
                                            @endforeach
                                        </select></div>
                                </x-admin.action>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-admin.card>
    @endforeach
</div>
@endsection
