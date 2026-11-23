<div class="max-w-2xl">
    <div class="flex items-center gap-3">
        <a href="{{ route('seller.products.index') }}" class="btn-ghost !p-2" aria-label="Back"><x-ui.icon name="arrow-left" class="w-6 h-6" /></a>
        <h1 class="text-2xl font-bold">Bulk upload</h1>
    </div>

    {{-- ============ RESULT ============ --}}
    @if ($result)
        <div class="card mt-6 p-6">
            <div class="flex items-start gap-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-emerald-500/10 text-emerald-600">
                    <x-ui.icon name="check" class="w-6 h-6" />
                </span>
                <div>
                    <p class="font-bold">Import complete</p>
                    <p class="mt-0.5 text-sm text-muted">
                        Detected <span class="font-medium text-fg">{{ $formats[$result['format']] ?? ucfirst($result['format']) }}</span> file ·
                        <span class="font-semibold text-fg">{{ $result['created'] }}</span> imported as drafts
                        @if (($result['variants'] ?? 0) > 0) · {{ $result['variants'] }} variants @endif
                        @if (($result['fitted'] ?? 0) > 0) · {{ $result['fitted'] }} with vehicle fitment @endif
                        @if (($result['uncategorised'] ?? 0) > 0) · {{ $result['uncategorised'] }} put in the fallback category @endif
                        @if (($result['existing'] ?? 0) > 0) · {{ $result['existing'] }} already in your store (not duplicated; missing photos added) @endif
                        @if (($result['archived'] ?? 0) > 0) · {{ $result['archived'] }} archived in your old store, so kept hidden (find them under Archived) @endif
                        @if (($result['assumed_stock'] ?? 0) > 0) · {{ $result['assumed_stock'] }} don't track stock in your old store, so we set them to {{ \App\Services\Seller\ProductCsvImporter::UNTRACKED_STOCK }} in stock (please check) @endif
                        @if ($result['skipped']) · {{ $result['skipped'] }} skipped (no title) @endif
                        @if (count($result['errors'])) · <span class="text-rose-500">{{ count($result['errors']) }} errors</span> @endif
                    </p>
                </div>
            </div>

            @include('livewire.seller.partials.bulk-upload-photos')

            @if ($result['created'] === 0 && !empty($result['headers']))
                <div class="mt-4 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-400">
                    <p class="font-semibold">Nothing imported — we couldn't find a product-name column.</p>
                    <p class="mt-1">Columns we read: <span class="font-mono">{{ implode(', ', array_slice($result['headers'], 0, 40)) }}</span></p>
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
                <button wire:click="startOver" class="btn-ghost btn-sm">Import another file</button>
            </div>
        </div>

    {{-- ============ CANCELLED ============ --}}
    @elseif ($importId && ($progress['status'] ?? '') === 'cancelled')
        <div class="card mt-6 p-6">
            <p class="font-bold">Import stopped</p>
            <p class="mt-1 text-sm text-muted">
                {{ (int) ($progress['done'] ?? 0) }} {{ Str::plural('product', (int) ($progress['done'] ?? 0)) }} had been created — they're in your drafts.
                Uploading the same file again carries on without making duplicates.
            </p>
            <div class="mt-5 flex gap-2">
                <a href="{{ route('seller.products.index', ['status' => 'draft']) }}" class="btn-accent btn-sm">View drafts</a>
                <button wire:click="startOver" class="btn-ghost btn-sm">Import another file</button>
            </div>
        </div>

    {{-- ============ IMPORTING (live progress) ============ --}}
    @elseif ($importId && in_array($progress['status'] ?? '', ['queued', 'running', 'cancelling'], true))
        @php
            $stage = $progress['stage'] ?? 'queued';
            $done = (int) ($progress['done'] ?? 0);
            $total = (int) ($progress['total'] ?? 0);
            // reading 0-5%, categories 5-15%, products 15-100%
            $pct = match (true) {
                ($progress['status'] ?? '') === 'queued' => 2,
                $stage === 'reading' => 5,
                $stage === 'categories' => 10,
                default => $total > 0 ? 15 + (int) floor(85 * $done / $total) : 15,
            };
            $label = match (true) {
                ($progress['status'] ?? '') === 'queued' => 'Waiting to start…',
                $stage === 'reading' => 'Reading your file…',
                $stage === 'categories' => 'Matching categories…',
                default => "Creating products — {$done} of {$total}",
            };
        @endphp
        <div class="card mt-6 p-6" wire:poll.1s="refreshProgress">
            <div class="flex items-center justify-between gap-3">
                <p class="font-bold">Importing your products</p>
                <span class="text-sm font-semibold tabular-nums">{{ $pct }}%</span>
            </div>
            <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-surface-2" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $pct }}" aria-label="Import progress">
                <div class="h-full rounded-full bg-accent transition-[width] duration-700 ease-out" style="width: {{ $pct }}%"></div>
            </div>
            <p class="mt-2 text-sm text-muted">{{ ($progress['status'] ?? '') === 'cancelling' ? 'Stopping after the current product…' : $label }}</p>
            @isset($progress['photos'])
                @if ($progress['photos'] && $photos['total'] > 0)
                    @include('livewire.seller.partials.bulk-upload-photos')
                @else
                    <p class="mt-1 text-xs {{ $progress['photos'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted' }}">
                        Photos: {{ $progress['photos'] ? 'On — they download in the background as products are created' : 'Off — products are created without photos' }}
                    </p>
                @endif
            @endisset
            <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-muted">You can leave this page — the import keeps going and you'll get a notification when it's done.</p>
                @if (($progress['status'] ?? '') !== 'cancelling')
                    <button type="button" wire:click="cancelImport"
                            wire:confirm="Stop this import? Products created so far stay in your drafts."
                            class="btn-outline btn-sm">
                        <x-ui.icon name="x" class="w-4 h-4" /> Cancel import
                    </button>
                @endif
            </div>
        </div>

    @else
        {{-- ============ FORM ============ --}}
        <div class="card mt-6 p-6">
            <p class="text-sm text-muted">
                Upload a product export from <strong class="text-fg">any platform</strong> — we recognise the columns automatically:
            </p>
            <div class="mt-3 flex flex-wrap gap-1.5">
                @foreach (['Shopify', 'WooCommerce (WordPress)', 'Amazon', 'eBay', 'Etsy', 'Wix', 'BigCommerce', 'Magento', 'Walmart', 'Panmotive template', 'Any spreadsheet'] as $platform)
                    <span class="rounded-full bg-surface-2 px-2.5 py-1 text-xs font-medium">{{ $platform }}</span>
                @endforeach
            </div>
            <ul class="mt-4 space-y-1.5 text-sm">
                <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 w-4 h-4 shrink-0 text-fg" /> <span><strong>Categories are detected automatically</strong> from the file's category, type or the product title.</span></li>
                <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 w-4 h-4 shrink-0 text-fg" /> <span><strong>Variants</strong> (color, size, side…) are grouped under one product.</span></li>
                <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 w-4 h-4 shrink-0 text-fg" /> <span><strong>Vehicle fitment</strong> from Year / Make / Model columns or titles like "2018-2022 Honda Accord".</span></li>
                <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 w-4 h-4 shrink-0 text-fg" /> <span><strong>Photos</strong> from image links are downloaded and saved as WebP.</span></li>
            </ul>
            <p class="mt-3 text-sm text-muted">Every product is created as a <strong class="text-fg">draft</strong> — review and publish from the Products page.</p>

            <form wire:submit="runImport" class="mt-5 space-y-4"
                  x-data="{ uploading: false, pct: 0 }"
                  x-on:livewire-upload-start="uploading = true; pct = 0"
                  x-on:livewire-upload-progress="pct = $event.detail.progress"
                  x-on:livewire-upload-finish="uploading = false; pct = 100"
                  x-on:livewire-upload-error="uploading = false; pct = 0"
                  x-on:livewire-upload-cancel="uploading = false; pct = 0">
                <div>
                    <label class="label" for="bulk-file">Product file (.csv, .tsv or Excel .xlsx, up to 12 MB)</label>
                    <input id="bulk-file" type="file" wire:model="csvFile" accept=".csv,.tsv,.txt,.xlsx,text/csv,text/tab-separated-values,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="text-sm">

                    <div x-show="uploading || pct === 100" x-cloak class="mt-2">
                        <div class="flex items-center justify-between text-xs text-muted">
                            <span x-text="uploading ? 'Uploading file…' : 'File uploaded'"></span>
                            <span class="tabular-nums" x-text="pct + '%'"></span>
                        </div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-surface-2" role="progressbar" aria-label="Upload progress" :aria-valuenow="pct" aria-valuemin="0" aria-valuemax="100">
                            <div class="h-full rounded-full bg-accent transition-[width] duration-200" :style="`width: ${pct}%`"></div>
                        </div>
                    </div>
                    @error('csvFile') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="label" for="bulk-cat">Fallback category <span class="text-muted">(optional — only for products we can't match)</span></label>
                    <select id="bulk-cat" wire:model="defaultCategoryId" class="input">
                        <option value="">Put them in Other Parts</option>
                        @foreach ($categories->whereNull('parent_id') as $dept)
                            <optgroup label="{{ $dept->name }}">
                                <option value="{{ $dept->id }}">{{ $dept->name }} (general)</option>
                                @foreach ($categories->where('parent_id', $dept->id) as $sub)
                                    <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('defaultCategoryId') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>

                {{-- A real switch: the old checkbox was hard to read on the dark theme. --}}
                <div x-data="{ on: $wire.entangle('withImages') }" class="flex items-center justify-between gap-4 rounded-xl border border-line bg-surface-2 p-4">
                    <div>
                        <p class="text-sm font-semibold" id="photos-label">Download photos</p>
                        <p class="mt-0.5 text-xs text-muted" x-text="on ? 'On — photos from the image links in your file are added (saved as WebP).' : 'Off — products are created without photos.'">On — photos from the image links in your file are added (saved as WebP).</p>
                    </div>
                    <button type="button" role="switch" :aria-checked="on.toString()" aria-labelledby="photos-label" @click="on = ! on"
                            class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors duration-200"
                            :class="on ? 'bg-accent' : 'bg-line'">
                        <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform duration-200" :class="on ? 'translate-x-6' : 'translate-x-1'"></span>
                        <span class="sr-only" x-text="on ? 'On' : 'Off'"></span>
                    </button>
                </div>

                <button class="btn-accent" wire:loading.attr="disabled" wire:target="runImport,csvFile" x-bind:disabled="uploading">
                    <span wire:loading.remove wire:target="runImport"><x-ui.icon name="list" class="w-4 h-4" /> Import products</span>
                    <span wire:loading wire:target="runImport">Starting…</span>
                </button>
            </form>
        </div>
    @endif
</div>
