<div>
    <div class="mb-4 flex items-center justify-between gap-4">
        <h1 class="text-xl font-bold">Products</h1>
        <a href="{{ route('seller.products.create') }}"
           class="rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-amber-400">
            New product
        </a>
    </div>

    @if (session('success'))
        <p class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</p>
    @endif

    <div class="mb-4 flex gap-3">
        <input wire:model.live.debounce.300ms="search" placeholder="Search title…"
               class="w-64 rounded-lg border-slate-300 text-sm">
        <select wire:model.live="status" class="rounded-lg border-slate-300 text-sm">
            <option value="">All statuses</option>
            <option value="draft">Draft</option>
            <option value="pending_review">Pending review</option>
            <option value="active">Active</option>
            <option value="rejected">Rejected</option>
            <option value="archived">Archived</option>
        </select>
    </div>

    <table class="w-full overflow-hidden rounded-xl border text-sm">
        <thead class="bg-slate-100 text-left">
            <tr>
                <th class="p-3">Title</th>
                <th class="p-3">Price</th>
                <th class="p-3">Qty</th>
                <th class="p-3">Status</th>
                <th class="p-3"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($products as $product)
                <tr class="border-t">
                    <td class="p-3">{{ $product->title }}</td>
                    <td class="p-3">${{ number_format($product->sale_price ?? $product->price, 2) }}</td>
                    <td class="p-3">{{ $product->quantity }}</td>
                    <td class="p-3">
                        <span class="rounded-full bg-slate-100 px-2 py-1 text-xs">{{ str_replace('_', ' ', $product->status) }}</span>
                    </td>
                    <td class="p-3 text-right">
                        <a href="{{ route('seller.products.edit', $product) }}" class="font-medium text-amber-600">Edit</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-6 text-center text-slate-400">No products yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="mt-4">{{ $products->links() }}</div>
</div>
