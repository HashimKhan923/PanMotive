@extends('layouts.admin')

@section('title', 'Seller approvals')

@section('content')
<div>
    <h1 class="text-xl font-bold mb-4">Pending seller approvals</h1>

    @if (session('success'))
        <p class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</p>
    @endif

    <table class="w-full text-sm border rounded-xl overflow-hidden bg-white">
        <thead class="bg-slate-100 text-left">
            <tr>
                <th class="p-3">Business</th>
                <th class="p-3">Owner</th>
                <th class="p-3">Type</th>
                <th class="p-3">Applied</th>
                <th class="p-3"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($pending as $profile)
                <tr class="border-t">
                    <td class="p-3">{{ $profile->business_name }}</td>
                    <td class="p-3">{{ $profile->user->name }}<br><span class="text-xs text-slate-400">{{ $profile->user->email }}</span></td>
                    <td class="p-3">{{ $profile->business_type }}</td>
                    <td class="p-3">{{ $profile->created_at->diffForHumans() }}</td>
                    <td class="p-3 text-right">
                        <a href="{{ route('admin.sellers.show', $profile) }}" class="font-medium text-amber-600">Review</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-6 text-center text-slate-400">Nothing pending.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="mt-4">{{ $pending->links() }}</div>
</div>
@endsection
