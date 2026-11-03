@if (session('impersonator_id'))
    <div class="sticky top-0 z-[80] flex flex-wrap items-center justify-center gap-3 bg-rose-600 px-4 py-2 text-sm font-medium text-white">
        <x-ui.icon name="shield" class="w-4 h-4" />
        <span>You are viewing as {{ auth()->user()?->name }}. Spending and account changes are blocked.</span>
        <form method="POST" action="{{ route('impersonate.stop') }}">
            @csrf
            <button class="rounded-full bg-white/20 px-3 py-0.5 text-xs font-semibold hover:bg-white/30">Back to admin</button>
        </form>
    </div>
@endif
