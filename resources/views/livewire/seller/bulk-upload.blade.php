<div class="max-w-2xl">
    <div class="flex items-center gap-3">
        <a href="{{ route('seller.products.index') }}" class="btn-ghost !p-2" aria-label="Back"><x-ui.icon name="arrow-left" class="w-5 h-5" /></a>
        <h1 class="text-2xl font-bold">Bulk upload</h1>
    </div>

    {{-- ============ RESULT ============ --}}
    @if ($result)
        <div class="card mt-6 p-6">
            <div class="flex items-start gap-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-emerald-500/10 text-emerald-600">
                    <x-ui.icon name="check" class="w-5 h-5" />
                </span>
                <div>
                    <p class="font-bold">Import complete</p>
                    <p class="mt-0.5 text-sm text-muted">
                        Detected <span class="font-medium capitalize text-fg">{{ $result['format'] }}</span> format ·
                        <span class="font-semibold text-fg">{{ $result['created'] }}</span> imported as drafts
                        @if (($result['uncategorised'] ?? 0) > 0) · {{ $result['uncategorised'] }} used the fallback category @endif
                        @if ($result['skipped']) · {{ $result['skipped'] }} skipped (no title) @endif
                        @if (count($result['errors'])) · <span class="text-rose-500">{{ count($result['errors']) }} errors</span> @endif
                    </p>
                </div>
            </div>

            @if ($result['created'] === 0 && !empty($result['headers']))
                <div class="mt-4 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-400">
                    <p class="font-semibold">Nothing imported — we couldn't find a product-name column.</p>
                    <p class="mt-1">Columns we read: <span class="font-mono">{{ implode(', ', $result['headers']) }}</span></p>
                    <p class="mt-1">Rename your name column to <span class="font-mono">title</span> (or <span class="font-mono">name</span>) and re-upload.</p>
                </div>
            @endif

            @if (count($result['errors']))
                <details class="mt-4">
                    <summary class="cursor-pointer text-sm font-medium text-muted hover:text-fg">Show {{ count($result['errors']) }} row errors</summary>
                    <ul class="mt-2 max-h-56 space-y-1 overflow-y-auto rounded-lg bg-surface-2 p-3 text-xs text-muted">
                        @foreach ($result['errors'] as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif

            <div class="mt-5 flex gap-2">
                @if ($result['created'] > 0)
                    <a href="{{ route('seller.products.index', ['status' => 'draft']) }}" class="btn-accent btn-sm">Review {{ $result['created'] }} drafts</a>
                @endif
                <button wire:click="$set('result', null)" class="btn-ghost btn-sm">Import another file</button>
            </div>
        </div>
    @elseif ($queued)
        <div class="card mt-6 p-6">
            <p class="font-bold">Large file — importing in the background</p>
            <p class="mt-1 text-sm text-muted">You'll get a notification when it finishes. Imported products appear as drafts.</p>
            <a href="{{ route('seller.products.index', ['status' => 'draft']) }}" class="btn-outline btn-sm mt-4">View drafts</a>
        </div>
    @else
        {{-- ============ FORM ============ --}}
        <div class="card mt-6 p-6">
            <p class="text-sm text-muted">
                Upload a CSV and we'll auto-detect the format. Supported:
            </p>
            <ul class="mt-2 space-y-1 text-sm">
                <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 w-4 h-4 shrink-0 text-accent" /> <span><strong>Shopify</strong> product export (Handle, Title, Variant Price, Variant Inventory Qty, Image Src, …) — variants and image URLs included</span></li>
                <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 w-4 h-4 shrink-0 text-accent" /> <span><strong>Pan Motive template</strong> — <code class="rounded bg-surface-2 px-1 text-xs">title, category_id, sku, part_number, oem_number, condition, price, quantity, description</code></span></li>
                <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 w-4 h-4 shrink-0 text-accent" /> <span>Any CSV with a <code class="rounded bg-surface-2 px-1 text-xs">title</code>/<code class="rounded bg-surface-2 px-1 text-xs">name</code> and <code class="rounded bg-surface-2 px-1 text-xs">price</code> column</span></li>
            </ul>
            <p class="mt-3 text-sm text-muted">
                <strong class="text-fg">Categories are auto-detected</strong> from each row's category / type / title.
                Every row is created as a <strong class="text-fg">draft</strong> — review and publish from the Products page.
            </p>

            <form wire:submit="runImport" class="mt-5 space-y-4">
                <div>
                    <label class="label" for="bulk-cat">Fallback category <span class="text-muted">(optional — only used for rows we can't match)</span></label>
                    <select id="bulk-cat" wire:model="defaultCategoryId" class="input">
                        <option value="">Put them in Other Parts</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}">{{ $c->parent_id ? '— ' : '' }}{{ $c->name }}</option>
                        @endforeach
                    </select>
                    @error('defaultCategoryId') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="withImages" class="rounded border-line text-accent focus:ring-accent">
                    Download images from URLs in the file (Shopify <code class="rounded bg-surface-2 px-1 text-xs">Image Src</code>)
                </label>

                <div>
                    <label class="label" for="bulk-file">CSV file</label>
                    <input id="bulk-file" type="file" wire:model="csvFile" accept=".csv,text/csv" class="text-sm">
                    <div wire:loading wire:target="csvFile" class="mt-1 text-xs text-muted">Uploading…</div>
                    @error('csvFile') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>

                <button class="btn-accent" wire:loading.attr="disabled" wire:target="runImport,csvFile">
                    <span wire:loading.remove wire:target="runImport"><x-ui.icon name="list" class="w-4 h-4" /> Upload &amp; import</span>
                    <span wire:loading wire:target="runImport">Importing…</span>
                </button>
            </form>
        </div>
    @endif
</div>
