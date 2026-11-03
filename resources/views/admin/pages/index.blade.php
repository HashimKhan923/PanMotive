@extends('layouts.admin')

@section('title', 'Pages')

@section('content')
<x-admin.header title="Pages" subtitle="About, Terms, Privacy, Returns policy and anything else shoppers should be able to read.">
    <a href="{{ route('admin.pages.create') }}" class="btn-accent btn-sm"><x-ui.icon name="plus" class="w-3.5 h-3.5" /> New page</a>
</x-admin.header>

<x-admin.card flush>
    @if ($pages->isEmpty())
        <x-admin.empty icon="list" title="No pages yet" text="Before launch you will need at least Terms, Privacy and a Returns policy." />
    @else
        <table class="adm-table">
            <thead><tr><th>Page</th><th>Status</th><th>In footer</th><th>Updated</th><th></th></tr></thead>
            <tbody>
                @foreach ($pages as $p)
                    <tr>
                        <td><a href="{{ route('admin.pages.edit', $p) }}" class="font-medium hover:text-accent">{{ $p->title }}</a><p class="text-xs text-muted">/p/{{ $p->slug }}</p></td>
                        <td><x-admin.status :value="$p->is_published ? 'published' : 'draft'" /></td>
                        <td class="text-muted">{{ $p->show_in_footer ? 'Yes' : 'No' }}</td>
                        <td class="whitespace-nowrap text-muted">{{ $p->updated_at->format('M j, Y') }}</td>
                        <td class="whitespace-nowrap text-right">
                            @if ($p->is_published)<a href="{{ \App\Support\AdminNav::site('pages.show', $p) }}" target="_blank" rel="noopener" class="mr-1 text-xs font-medium text-muted hover:text-accent">View</a>@endif
                            <x-admin.action :url="route('admin.pages.destroy', $p)" method="DELETE" label="Delete" tone="danger" size="xs" :title="'Delete '.$p->title" body="This can't be undone." />
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
<div class="mt-4">{{ $pages->links() }}</div>
@endsection
