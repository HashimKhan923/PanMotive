<div>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Products</h1>
            <p class="mt-1 text-sm text-muted">{{ $total }} {{ Str::plural('listing', $total) }} total</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button wire:click="exportCsv" class="btn-ghost btn-sm"><x-ui.icon name="external" class="w-4 h-4" /> Export CSV</button>
            @if ($canManage)
                <a href="{{ route('seller.products.bulk-update') }}" class="btn-ghost btn-sm"><x-ui.icon name="refresh" class="w-4 h-4" /> Update by spreadsheet</a>
                <a href="{{ route('seller.products.bulk') }}" class="btn-outline btn-sm"><x-ui.icon name="list" class="w-4 h-4" /> Bulk upload</a>
                <a href="{{ route('seller.products.create') }}" class="btn-accent btn-sm"><x-ui.icon name="plus" class="w-4 h-4" /> New product</a>
            @endif
        </div>
    </div>

    @if ($canManage && $templates->isNotEmpty())
        <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
            <span class="font-semibold uppercase text-muted">New from template:</span>
            @foreach ($templates as $t)
                <span class="inline-flex items-center gap-1 rounded-full border border-line px-2.5 py-1">
                    <a href="{{ route('seller.products.create', ['template' => $t->id]) }}" class="font-medium hover:text-accent">{{ $t->name }}</a>
                    <button wire:click="deleteTemplate({{ $t->id }})" class="text-muted hover:text-rose-500">&times;</button>
                </span>
            @endforeach
        </div>
    @endif

    {{-- status tabs --}}
    <div class="mt-6 flex flex-wrap gap-1 border-b border-line text-sm">
        @php($tabs = ['' => 'All', 'active' => 'Active', 'pending_review' => 'In review', 'draft' => 'Drafts', 'rejected' => 'Rejected', 'archived' => 'Archived'])
        @foreach ($tabs as $key => $label)
            <button wire:click="$set('status', '{{ $key }}')"
                    class="-mb-px border-b-2 px-3 py-2 font-medium transition
                           {{ $status === $key ? 'border-accent text-fg' : 'border-transparent text-muted hover:text-fg' }}">
                {{ $label }}
                @if ($key !== '' && ($counts[$key] ?? 0) > 0)
                    <span class="ml-1 rounded-full bg-surface-2 px-1.5 text-xs">{{ $counts[$key] }}</span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- filters --}}
    <div class="mt-4 flex flex-wrap items-center gap-3">
        <div class="relative">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 w-4 h-4 -translate-y-1/2 text-muted" />
            <input wire:model.live.debounce.300ms="search" placeholder="Search title, part #, SKU" class="input h-10 w-64 pl-9 text-sm">
        </div>
        <select wire:model.live="stock" class="input h-10 w-auto text-sm">
            <option value="">Any stock</option>
            <option value="low">Low stock (1–5)</option>
            <option value="out">Out of stock</option>
        </select>
        <select wire:model.live="sort" class="input h-10 w-auto text-sm">
            <option value="newest">Newest</option>
            <option value="best_selling">Best selling</option>
            <option value="price_high">Price: high to low</option>
            <option value="price_low">Price: low to high</option>
            <option value="stock_low">Stock: low to high</option>
        </select>
        <div wire:loading.delay class="text-xs text-muted">Updating…</div>
    </div>

    {{-- bulk bar --}}
    @if ($canManage && count($selected))
        <div class="mt-4 rounded-xl border border-accent/30 bg-accent/10 px-4 py-2.5 text-sm">
            <div class="flex items-center gap-3">
                <span class="font-medium">{{ count($selected) }} selected</span>
                <button wire:click="bulk('activate')" class="font-medium text-fg">Activate</button>
                <button wire:click="bulk('archive')" class="font-medium text-fg">Archive</button>
                <button wire:click="$toggle('showBulkPrice')" class="font-medium text-fg">Adjust price</button>
                <button wire:click="bulk('delete')" wire:confirm="Permanently delete the selected listing(s)? Listings with order history are skipped automatically. This can't be undone." class="font-medium text-rose-500 hover:underline">Delete</button>
                <button wire:click="$set('selected', [])" class="ml-auto text-muted hover:text-fg">Clear</button>
            </div>
            @if ($showBulkPrice)
                <div class="mt-3 flex flex-wrap items-end gap-2 border-t border-accent/20 pt-3">
                    <select wire:model="priceMode" class="input h-9 w-auto text-sm">
                        <option value="pct_down">Decrease by %</option>
                        <option value="pct_up">Increase by %</option>
                        <option value="amt_down">Decrease by $</option>
                        <option value="amt_up">Increase by $</option>
                        <option value="set">Set to $</option>
                    </select>
                    <input type="number" step="0.01" wire:model="priceValue" class="input h-9 w-28 text-sm" placeholder="Value">
                    <button wire:click="applyBulkPrice" class="btn-accent btn-sm">Apply to {{ count($selected) }}</button>
                    @error('priceValue') <p class="w-full text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>
    @endif

    {{-- table --}}
    <div class="mt-4 overflow-x-auto rounded-xl border border-line">
        <table class="w-full min-w-[720px] text-sm">
            <thead class="bg-surface-2 text-left text-xs uppercase text-muted">
                <tr>
                    <th class="w-10 px-4 py-3">@if ($canManage)<input type="checkbox" wire:model.live="selectPage" class="rounded border-line text-accent focus:ring-accent">@endif</th>
                    <th class="px-4 py-3">Product</th>
                    <th class="px-4 py-3">Price</th>
                    <th class="px-4 py-3">Stock</th>
                    <th class="px-4 py-3">Sold</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($products as $product)
                    <tr class="bg-surface" wire:key="p-{{ $product->id }}">
                        <td class="px-4 py-3">
                            @if ($canManage)<input type="checkbox" value="{{ $product->id }}" wire:model.live="selected" class="rounded border-line text-accent focus:ring-accent">@endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-lg bg-surface-2">
                                    @php($thumb = $product->getFirstMediaUrl('images', 'thumb') ?: $product->getFirstMediaUrl('images'))
                                    @if ($product->hasMedia('images') && $thumb)
                                        <img src="{{ $thumb }}" alt="" class="h-full w-full object-cover" loading="lazy">
                                    @else
                                        <x-ui.icon name="image" class="w-6 h-6 text-muted" />
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    @if ($canManage)<a href="{{ route('seller.products.edit', $product) }}" class="line-clamp-1 font-medium hover:text-accent">{{ $product->title }}</a>@else<span class="line-clamp-1 font-medium">{{ $product->title }}</span>@endif
                                    <p class="text-xs text-muted">{{ $product->part_number ? 'Part #'.$product->part_number : ($product->sku ?: '—') }}</p>
                                </div>
                            </div>
                        </td>
                        @if ($editId === $product->id)
                            <td class="px-4 py-3">
                                <input type="number" step="0.01" wire:model="editPrice" class="input h-8 w-24 text-sm" wire:keydown.enter="saveEdit" aria-label="Price">
                                @error('editPrice') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                            </td>
                            <td class="px-4 py-3">
                                @if ($product->hasVariants())
                                    <a href="{{ route('seller.inventory.index', ['q' => $product->title]) }}" class="text-xs text-accent hover:underline" title="This listing is stocked per option">{{ $product->quantity }} · per option</a>
                                @else
                                    <input type="number" wire:model="editQty" class="input h-8 w-20 text-sm" wire:keydown.enter="saveEdit" aria-label="Stock">
                                @endif
                            </td>
                        @else
                            <td @class(['px-4 py-3', 'cursor-pointer' => $canManage]) @if ($canManage) wire:click="startEdit({{ $product->id }})" title="Click to edit" @endif>
                                @if ($product->sale_price)
                                    <span class="font-semibold">${{ number_format($product->sale_price, 2) }}</span>
                                    <span class="ml-1 text-xs text-muted line-through">${{ number_format($product->price, 2) }}</span>
                                @else
                                    <span class="font-semibold">${{ number_format($product->price, 2) }}</span>
                                @endif
                            </td>
                            <td @class(['px-4 py-3', 'cursor-pointer' => $canManage]) @if ($canManage) wire:click="startEdit({{ $product->id }})" title="Click to edit" @endif>
                                <span class="{{ $product->quantity <= 0 ? 'text-rose-500' : ($product->quantity <= 5 ? 'text-amber-600' : '') }}">{{ $product->quantity }}</span>
                            </td>
                        @endif
                        <td class="px-4 py-3 text-muted">{{ $product->order_items_count }}</td>
                        <td class="px-4 py-3">
                            @php($map = ['active' => 'bg-emerald-500/10 text-emerald-600', 'pending_review' => 'bg-amber-500/10 text-amber-600', 'draft' => 'bg-surface-2 text-muted', 'rejected' => 'bg-rose-500/10 text-rose-500', 'archived' => 'bg-surface-2 text-muted'])
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $map[$product->status] ?? 'bg-surface-2 text-muted' }}">{{ str_replace('_', ' ', $product->status) }}</span>
                            @if ($product->moderation_note)<p class="mt-1 max-w-[180px] text-xs text-rose-600 dark:text-rose-400">Taken down: {{ $product->moderation_note }}</p>@endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if ($editId === $product->id)
                                <button wire:click="saveEdit" class="btn-accent btn-sm">Save</button>
                                <button wire:click="$set('editId', null)" class="btn-ghost btn-sm">Cancel</button>
                            @elseif (! $canManage)
                                @if ($product->status === 'active')
                                    <a href="{{ route('products.show', $product->slug) }}" target="_blank" class="whitespace-nowrap text-xs font-medium text-accent hover:underline">View on site</a>
                                @endif
                            @else
                            <div class="relative inline-block" x-data="{ open: false }" @click.outside="open = false">
                                <button @click="open = !open" class="btn-ghost !p-1.5" aria-label="Actions"><x-ui.icon name="list" class="w-4 h-4" /></button>
                                <div x-show="open" x-cloak x-transition.origin.top.right class="absolute right-0 z-20 mt-1 w-44 overflow-hidden rounded-xl border border-line bg-surface py-1 text-left shadow-card-hover">
                                    <a href="{{ route('seller.products.edit', $product) }}" class="block px-3 py-2 hover:bg-surface-2">Edit</a>
                                    @if ($product->status === 'active')
                                        <button wire:click="archive({{ $product->id }})" class="block w-full px-3 py-2 text-left hover:bg-surface-2">Archive</button>
                                    @else
                                        <button wire:click="activate({{ $product->id }})" class="block w-full px-3 py-2 text-left hover:bg-surface-2">Activate</button>
                                    @endif
                                    <button wire:click="duplicate({{ $product->id }})" class="block w-full px-3 py-2 text-left hover:bg-surface-2">Duplicate</button>
                                    <button wire:click="saveAsTemplate({{ $product->id }})" class="block w-full px-3 py-2 text-left hover:bg-surface-2">Save as template</button>
                                    @if ($product->status === 'active')
                                        <a href="{{ route('products.show', $product->slug) }}" target="_blank" class="block px-3 py-2 hover:bg-surface-2">View on site</a>
                                    @endif
                                    <div class="my-1 border-t border-line"></div>
                                    @if (($product->order_items_count ?? 0) > 0)
                                        <span class="block cursor-not-allowed px-3 py-2 text-muted" title="Has order history — archive instead of deleting">Delete</span>
                                    @else
                                        <button wire:click="delete({{ $product->id }})"
                                                wire:confirm="Permanently delete &quot;{{ addslashes($product->title) }}&quot;? This can't be undone."
                                                class="block w-full px-3 py-2 text-left text-rose-500 hover:bg-rose-500/10">Delete</button>
                                    @endif
                                </div>
                            </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-16 text-center">
                        <x-ui.icon name="box" class="mx-auto w-12 h-12 text-muted" />
                        <p class="mt-3 font-semibold">No products here yet</p>
                        <p class="mt-1 text-sm text-muted">{{ $search || $status || $stock ? 'Try clearing your filters.' : 'Create your first listing to start selling.' }}</p>
                        @if ($canManage)<a href="{{ route('seller.products.create') }}" class="btn-accent btn-sm mt-4">New product</a>@endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $products->links() }}</div>
</div>
