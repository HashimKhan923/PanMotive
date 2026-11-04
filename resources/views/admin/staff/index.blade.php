@extends('layouts.admin')

@section('title', 'Staff & roles')

@section('content')
<x-admin.header title="Staff & roles" subtitle="Who can use this console, and what each role can do.">
    <x-admin.action :url="route('admin.staff.store')" label="Add staff member" tone="primary" icon="plus" confirm="Send invite" body="New people get an email to set their own password.">
        <div><label class="label">Name</label><input name="name" class="input text-sm" required maxlength="255"></div>
        <div><label class="label">Email</label><input type="email" name="email" class="input text-sm" required maxlength="255"></div>
        <div><label class="label">Role</label><select name="role" class="input text-sm">
            @foreach ($roles as $key => $label)<option value="{{ $key }}" @selected($key === 'support_agent')>{{ $label }}</option>@endforeach
        </select></div>
    </x-admin.action>
</x-admin.header>

<x-admin.card title="Team" flush>
    <table class="adm-table">
        <thead><tr><th>Person</th><th>Role</th><th></th></tr></thead>
        <tbody>
            @foreach ($staff as $member)
                <tr>
                    <td><a href="{{ route('admin.users.show', $member) }}" class="font-medium hover:text-accent">{{ $member->name }}</a><p class="text-xs text-muted">{{ $member->email }}</p></td>
                    <td>
                        <form method="POST" action="{{ route('admin.staff.role', $member) }}" class="flex items-center gap-2">
                            @csrf
                            <select name="role" onchange="this.form.submit()" class="input h-8 !w-auto !py-0 text-xs" aria-label="Role for {{ $member->name }}">
                                @foreach ($roles as $key => $label)<option value="{{ $key }}" @selected($member->hasRole($key))>{{ $label }}</option>@endforeach
                            </select>
                        </form>
                    </td>
                    <td class="text-right">
                        @unless ($member->is(auth()->user()))
                            <x-admin.action :url="route('admin.staff.remove', $member)" method="DELETE" label="Remove" tone="danger" size="xs" :body="$member->name.' loses all staff access. Their account stays.'" />
                        @endunless
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-admin.card>

<form method="POST" action="{{ route('admin.staff.matrix') }}" class="mt-4">
    @csrf
    <x-admin.card title="What each role can do" subtitle="Super admin always has everything, so the team can't be locked out." flush>
        <x-slot:actions><button class="btn-accent btn-sm">Save permissions</button></x-slot:actions>
        <table class="adm-table">
            <thead><tr><th>Permission</th>@foreach ($roles as $label)<th class="text-center">{{ $label }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach ($groups as $group => $permissions)
                    <tr><td colspan="{{ count($roles) + 1 }}" class="bg-surface-2/50 !py-1.5 text-[11px] font-bold uppercase tracking-wider text-muted">{{ $group }}</td></tr>
                    @foreach ($permissions as $key => $label)
                        <tr>
                            <td>{{ $label }}</td>
                            @foreach ($roles as $role => $roleLabel)
                                <td class="text-center">
                                    @if ($role === 'super_admin')
                                        <x-ui.icon name="check" class="mx-auto w-4 h-4 text-fg" />
                                    @else
                                        <input type="checkbox" name="permissions[{{ $role }}][]" value="{{ $key }}" @checked(in_array($key, $matrix[$role] ?? [], true))
                                               class="rounded text-accent focus:ring-accent" aria-label="{{ $roleLabel }}: {{ $label }}">
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </x-admin.card>
</form>
@endsection
