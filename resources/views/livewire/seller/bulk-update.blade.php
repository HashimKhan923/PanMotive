@php
    $fmt = fn ($f, $v) => match ($f) {
        'price', 'sale_price' => $v === null ? 'none' : '$'.number_format((float) $v, 2),
        default => (string) $v,
    };
    $labels = ['price' => 'Price', 'sale_price' => 'Sale price', 'quantity' => 'Stock', 'status' => 'Status'];
@endphp
<div class="max-w-4xl">
    <div class="flex items-center gap-3">
        <a href="{{ route('seller.products.index') }}" class="btn-ghost !p-2" aria-label="Back to products"><x-ui.icon name="arrow-left" class="w-6 h-6" /></a>
        <div>
            <h1 class="text-2xl font-bold">Update by spreadsheet</h1>
            <p class="mt-1 text-sm text-muted">Change prices, stock and status for many listings at once in Excel or Google Sheets.</p>
        </div>
    </div>

    @if (! $result)
        <div class="mt-6 grid gap-4 md:grid-cols-3">
            <div class="card p-5">
                <span class="grid h-8 w-8 place-items-center rounded-full bg-surface-2 font-bold text-fg">1</span>
                <p class="mt-3 font-semibold">Download your catalog</p>
                <p class="mt-1 text-sm text-muted">One row per listing — and one per option for listings with options.</p>
                <button wire:click="download" class="btn-outline btn-sm mt-4 w-full"><x-ui.icon name="download" class="w-4 h-4" /> Download CSV</button>
            </div>
            <div class="card p-5">
                <span class="grid h-8 w-8 place-items-center rounded-full bg-surface-2 font-bold text-fg">2</span>
                <p class="mt-3 font-semibold">Edit what you want to change</p>
                <p class="mt-1 text-sm text-muted">Edit <strong>price</strong>, <strong>sale_price</strong>, <strong>quantity</strong> or <strong>status</strong> (active / draft / archived). Leave a cell blank to keep it. Put <code>0</code> in sale_price to end a sale.</p>
                <p class="mt-2 text-xs text-muted">Don't change listing_id, option_id or sku — they tell us which row is which.</p>
            </div>
            <div class="card p-5">
                <span class="grid h-8 w-8 place-items-center rounded-full bg-surface-2 font-bold text-fg">3</span>
                <p class="mt-3 font-semibold">Upload and review</p>
                <p class="mt-1 text-sm text-muted">We show every change before anything is saved.</p>
                <div class="mt-4 space-y-2">
                    <input type="file" wire:model="sheet" accept=".csv,text/csv" class="block w-full text-sm" aria-label="Edited CSV file">
                    @error('sheet') <p class="text-xs text-rose-500">{{ $message }}</p> @enderror
                    <button wire:click="check" wire:loading.attr="disabled" wire:target="sheet,check" class="btn-accent btn-sm w-full">
                        <span wire:loading.remove wire:target="check">Check changes</span>
                        <span wire:loading wire:target="check">Reading file…</span>
                    </button>
                </div>
            </div>
        </div>
    @else
        <div class="mt-6 grid grid-cols-3 gap-3">
            <div class="card p-4"><p class="text-xs uppercase tracking-wide text-muted">Will change</p><p class="mt-1 text-2xl font-bold text-accent">{{ count($result['changes']) }}</p></div>
            <div class="card p-4"><p class="text-xs uppercase tracking-wide text-muted">Unchanged</p><p class="mt-1 text-2xl font-bold">{{ $result['unchanged'] }}</p></div>
            <div class="card p-4"><p class="text-xs uppercase tracking-wide text-muted">Problems</p><p class="mt-1 text-2xl font-bold {{ $result['errors'] ? 'text-rose-600' : '' }}">{{ count($result['errors']) }}</p></div>
        </div>

        @if ($result['errors'])
            <div class="mt-4 rounded-xl border border-rose-500/30 bg-rose-500/5 p-4">
                <p class="text-sm font-semibold text-rose-700 dark:text-rose-400">These rows will be skipped:</p>
                <ul class="mt-2 max-h-48 list-disc space-y-1 overflow-y-auto pl-5 text-sm text-rose-700 dark:text-rose-400">
                    @foreach ($result['errors'] as $e)<li>{{ $e }}</li>@endforeach
                </ul>
            </div>
        @endif

        @if ($result['changes'])
            <div class="mt-4 overflow-x-auto rounded-xl border border-line">
                <table class="w-full min-w-[560px] text-sm">
                    <thead class="bg-surface-2 text-left text-xs uppercase text-muted">
                        <tr><th class="px-4 py-3">Listing</th><th class="px-4 py-3">Changes</th></tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($result['changes'] as $c)
                            <tr class="bg-surface">
                                <td class="px-4 py-3 font-medium">{{ $c['label'] }}</td>
                                <td class="px-4 py-3">
                                    @foreach ($c['fields'] as $f)
                                        <span class="mr-3 inline-block whitespace-nowrap">
                                            <span class="text-muted">{{ $labels[$f] }}</span>
                                            <span class="line-through text-muted">{{ $fmt($f, $c['from'][$f]) }}</span>
                                            → <span class="font-semibold">{{ $fmt($f, $c['to'][$f]) }}</span>
                                        </span>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="mt-5 flex flex-wrap gap-2">
            @if ($result['changes'])
                <button wire:click="apply" wire:confirm="Apply {{ count($result['changes']) }} {{ Str::plural('change', count($result['changes'])) }}?" class="btn-accent">Apply {{ count($result['changes']) }} {{ Str::plural('change', count($result['changes'])) }}</button>
            @else
                <p class="text-sm text-muted">Nothing to update — every row matches what's already listed.</p>
            @endif
            <button wire:click="startOver" class="btn-ghost">Upload a different file</button>
        </div>
    @endif
</div>
