@extends('layouts.admin')

@section('title', $page->exists ? $page->title : 'New page')

@section('content')
<x-admin.header :title="$page->exists ? 'Edit page' : 'New page'" :back="route('admin.pages.index')" />

<form method="POST" action="{{ $page->exists ? route('admin.pages.update', $page) : route('admin.pages.store') }}" class="grid gap-4 xl:grid-cols-3">
    @csrf
    @if ($page->exists) @method('PATCH') @endif

    <div class="xl:col-span-2">
        <x-admin.card>
            <div class="space-y-4">
                <div><label class="label" for="pg-title">Title</label><input id="pg-title" name="title" value="{{ old('title', $page->title) }}" maxlength="160" class="input text-sm" required></div>
                <div>
                    <label class="label" for="pg-body">Content</label>
                    <input id="pg-body" type="hidden" name="body" value="{{ old('body', $page->body) }}">
                    <trix-editor input="pg-body" class="prose-pm min-h-[360px] rounded-xl border border-line bg-surface p-3 text-sm"></trix-editor>
                </div>
            </div>
        </x-admin.card>
    </div>

    <div class="space-y-4">
        <x-admin.card title="Publish">
            <div class="space-y-3">
                <label class="flex items-center gap-2 text-[13px]"><input type="checkbox" name="is_published" value="1" @checked(old('is_published', $page->is_published)) class="rounded text-accent focus:ring-accent"> Published on the site</label>
                <label class="flex items-center gap-2 text-[13px]"><input type="checkbox" name="show_in_footer" value="1" @checked(old('show_in_footer', $page->show_in_footer)) class="rounded text-accent focus:ring-accent"> Link it in the site footer</label>
                <button class="btn-accent btn-sm w-full">Save page</button>
            </div>
        </x-admin.card>
        <x-admin.card title="Search engines">
            <div class="space-y-3">
                <div><label class="label" for="pg-slug">Web address</label>
                    <div class="flex items-center gap-1 text-sm"><span class="text-muted">/p/</span><input id="pg-slug" name="slug" value="{{ old('slug', $page->slug) }}" maxlength="160" class="input text-sm" placeholder="from the title"></div></div>
                <div><label class="label" for="pg-meta">Short description</label><textarea id="pg-meta" name="meta_description" rows="3" maxlength="255" class="input text-sm">{{ old('meta_description', $page->meta_description) }}</textarea></div>
            </div>
        </x-admin.card>
    </div>
</form>
@endsection
