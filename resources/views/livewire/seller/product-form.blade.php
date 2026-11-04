<div class="max-w-4xl">
    <div class="flex items-center gap-3">
        <a href="{{ route('seller.products.index') }}" class="btn-ghost !p-2" aria-label="Back"><x-ui.icon name="arrow-left" class="w-6 h-6" /></a>
        <h1 class="text-2xl font-bold">{{ $product ? 'Edit product' : 'New product' }}</h1>
        @if ($product)
            <span class="pill">{{ str_replace('_', ' ', $product->status) }}</span>
        @endif
    </div>

    @if ($product?->moderation_note)
        <div class="mt-4 flex items-start gap-2 rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-600 dark:text-rose-300" role="alert">
            <x-ui.icon name="alert" class="mt-0.5 w-4 h-4 shrink-0" />
            <span>Panmotive took this listing down: {{ $product->moderation_note }}. Fix the problem and publish it, and our team will review it before it goes live.</span>
        </div>
    @endif

    @if ($quality)
        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            <div class="card p-5" data-reveal>
                <div class="flex items-center gap-4">
                    <x-ui.ring :value="$quality['score']" :size="72" :stroke="8"
                               tone="{{ $quality['score'] >= 80 ? 'emerald' : ($quality['score'] >= 55 ? 'amber' : 'rose') }}" />
                    <div>
                        <p class="font-bold">Listing quality</p>
                        <p class="text-xs text-muted">Complete listings convert better and rank higher.</p>
                    </div>
                </div>
                <ul class="mt-3 space-y-1 text-xs">
                    @foreach ($quality['checks'] as $c)
                        <li class="flex items-center gap-2 {{ $c['ok'] ? 'text-muted' : 'text-fg' }}">
                            <x-ui.icon name="{{ $c['ok'] ? 'check' : 'x' }}" class="w-4 h-4 {{ $c['ok'] ? 'text-emerald-500' : 'text-rose-500' }}" />
                            {{ $c['label'] }}
                        </li>
                    @endforeach
                </ul>
            </div>

            @if ($priceIntel)
                <div class="card p-5" data-reveal>
                    <p class="font-bold">Price check</p>
                    <p class="mt-1 text-sm">
                        You're
                        <span class="font-semibold {{ $priceIntel['position'] === 'lowest' ? 'text-emerald-600' : ($priceIntel['position'] === 'competitive' ? 'text-accent' : 'text-rose-500') }}">
                            {{ ['lowest' => 'the lowest', 'competitive' => 'competitive', 'above' => number_format($priceIntel['delta_pct'], 0).'% above the lowest'][$priceIntel['position']] }}
                        </span>
                        of {{ $priceIntel['count'] + 1 }} sellers of this part.
                    </p>
                    <div class="mt-3 grid grid-cols-3 gap-2 text-center text-sm">
                        <div class="rounded-lg bg-surface-2 py-2"><p class="text-xs text-muted">Lowest</p><p class="font-bold">${{ number_format($priceIntel['min'], 2) }}</p></div>
                        <div class="rounded-lg bg-surface-2 py-2"><p class="text-xs text-muted">Median</p><p class="font-bold">${{ number_format($priceIntel['median'], 2) }}</p></div>
                        <div class="rounded-lg bg-accent/10 py-2"><p class="text-xs text-muted">You</p><p class="font-bold text-accent">${{ number_format($priceIntel['your_price'], 2) }}</p></div>
                    </div>
                </div>
            @endif
        </div>
    @endif

    <form wire:submit="submit" class="mt-6 space-y-6">
        {{-- Basics --}}
        <section class="card p-6">
            <h2 class="font-bold">Basics</h2>

            <div class="mt-4 flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[220px]">
                    <label class="label" for="pf-part">Part number</label>
                    <input id="pf-part" wire:model="part_number" class="input" placeholder="e.g. BP-1094">
                    @error('part_number') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <button type="button" wire:click="generateWithAi" wire:loading.attr="disabled" wire:target="generateWithAi" class="btn-brand btn-sm">
                    <x-ui.icon name="sparkles" class="w-4 h-4" />
                    <span wire:loading.remove wire:target="generateWithAi">Generate with AI</span>
                    <span wire:loading wire:target="generateWithAi">Generating…</span>
                </button>
            </div>

            <div class="mt-4">
                <label class="label" for="pf-title">Title</label>
                <input id="pf-title" wire:model="title" class="input" placeholder="Front Ceramic Brake Pad Set — Honda Civic 2016–2021">
                @error('title') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="label" for="pf-cat">Category</label>
                    <select id="pf-cat" wire:model="category_id" class="input">
                        <option value="">Select…</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    @error('category_id') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="pf-brand">Brand</label>
                    <select id="pf-brand" wire:model="brand_id" class="input">
                        <option value="">Unbranded</option>
                        @foreach ($brands as $brand)
                            <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="pf-cond">Condition</label>
                    <select id="pf-cond" wire:model="condition" class="input">
                        <option value="new">New</option>
                        <option value="used">Used</option>
                        <option value="refurbished">Refurbished</option>
                    </select>
                </div>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="pf-oem">OEM number</label>
                    <input id="pf-oem" wire:model="oem_number" class="input">
                </div>
                <div>
                    <label class="label" for="pf-sku">Your SKU</label>
                    <input id="pf-sku" wire:model="sku" class="input">
                </div>
            </div>
        </section>

        {{-- Pricing & stock --}}
        <section class="card p-6">
            <h2 class="font-bold">Pricing &amp; stock</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-4">
                <div>
                    <label class="label" for="pf-price">Price (USD)</label>
                    <input id="pf-price" type="number" step="0.01" wire:model="price" class="input">
                    @error('price') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="pf-sale">Sale price</label>
                    <input id="pf-sale" type="number" step="0.01" wire:model="sale_price" class="input">
                    @error('sale_price') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="pf-qty">Quantity</label>
                    @if (count($variants))
                        <input id="pf-qty" type="number" value="{{ collect($variants)->sum(fn ($v) => (int) ($v['quantity'] ?? 0)) }}" class="input bg-surface-2" disabled>
                        <p class="mt-1 text-xs text-muted">Total of your options below — set stock per option.</p>
                    @else
                        <input id="pf-qty" type="number" wire:model="quantity" class="input">
                        @error('quantity') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-muted">Changes here are logged to the <a href="{{ route('seller.inventory.index') }}" class="text-fg underline underline-offset-2" target="_blank">movement history</a>.</p>
                    @endif
                </div>
                <div>
                    <label class="label" for="pf-reorder">Reorder point</label>
                    <input id="pf-reorder" type="number" min="0" wire:model="reorder_point" class="input" placeholder="Alert me at…">
                    @error('reorder_point') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="pf-reorderqty">Restock target</label>
                    <input id="pf-reorderqty" type="number" min="0" wire:model="reorder_qty" class="input" placeholder="Bring back up to…">
                    @error('reorder_qty') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="pf-wt">Weight (lb)</label>
                    <input id="pf-wt" type="number" step="0.01" wire:model="weight_lbs" class="input">
                    @error('weight_lbs') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="mt-2 text-xs text-muted">Shipping is estimated from weight. Leave blank to use a 2&nbsp;lb default.</p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="pf-shippol">Shipping policy</label>
                    <select id="pf-shippol" wire:model="shipping_policy_id" class="input">
                        <option value="">Store default @if($shippingPolicies->firstWhere('is_default'))— {{ $shippingPolicies->firstWhere('is_default')->name }}@endif</option>
                        @foreach ($shippingPolicies as $sp)
                            <option value="{{ $sp->id }}">{{ $sp->name }} ({{ $sp->handling_days }}d handling)</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-muted"><a href="{{ route('seller.shipping.index') }}" class="text-fg underline underline-offset-2" target="_blank">Manage policies →</a></p>
                </div>
                <div>
                    <label class="label" for="pf-handling">Handling time override (days)</label>
                    <input id="pf-handling" type="number" min="0" max="30" wire:model="handling_days" class="input" placeholder="From policy">
                    @error('handling_days') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
            </div>

            @if ($sale_price)
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="pf-sstart">Sale starts <span class="text-muted">(optional)</span></label>
                        <input id="pf-sstart" type="datetime-local" wire:model="sale_starts_at" class="input">
                        @error('sale_starts_at') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="pf-send">Sale ends <span class="text-muted">(optional)</span></label>
                        <input id="pf-send" type="datetime-local" wire:model="deal_ends_at" class="input">
                        @error('deal_ends_at') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="mt-1 text-xs text-muted">Schedule a flash sale — it activates and expires automatically and shows on the Deals page.</p>
            @endif

            <div class="mt-4 rounded-xl border border-line p-3">
                <label class="flex items-center gap-3 text-sm">
                    <input type="checkbox" wire:model.live="accepts_offers" class="rounded border-line text-accent focus:ring-accent">
                    <span class="font-medium">Accept offers</span>
                </label>
                <p class="mt-1 text-xs text-muted">Let buyers negotiate. You accept, counter or decline — accepted offers become a single-use code.</p>
                @if ($accepts_offers)
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="label" for="pf-minoffer">Auto-decline offers below ($/ea)</label>
                            <input id="pf-minoffer" type="number" step="0.01" wire:model="min_offer_price" class="input h-10 text-sm" placeholder="No minimum">
                            @error('min_offer_price') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label" for="pf-autoaccept">Auto-accept offers at or above ($/ea)</label>
                            <input id="pf-autoaccept" type="number" step="0.01" wire:model="auto_accept_offer_price" class="input h-10 text-sm" placeholder="Always ask me">
                            @error('auto_accept_offer_price') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-muted">Good offers become an instant sale — no waiting on you.</p>
                @endif
            </div>

            <div class="mt-3 rounded-xl border border-line p-3">
                <label class="flex items-center gap-3 text-sm">
                    <input type="checkbox" wire:model.live="subscription_enabled" class="rounded border-line text-accent focus:ring-accent">
                    <span class="font-medium">Offer Subscribe &amp; Save</span>
                </label>
                <p class="mt-1 text-xs text-muted">Buyers get automatic repeat deliveries at a discount — great for filters, fluids, pads and other wear parts. Turning this off stops new sign-ups; existing subscribers keep their schedule.</p>
                @if ($subscription_enabled)
                    <div class="mt-3 w-48">
                        <label class="label" for="pf-sns">Subscriber discount (%)</label>
                        <input id="pf-sns" type="number" step="0.5" min="5" max="15" wire:model="subscription_discount_pct" class="input h-10 text-sm">
                        @error('subscription_discount_pct') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>
        </section>

        {{-- Variants --}}
        <section class="card p-6">
            <div class="flex items-center justify-between">
                <h2 class="font-bold">Options / variants</h2>
                <button type="button" wire:click="addVariant" class="btn-outline btn-sm"><x-ui.icon name="plus" class="w-4 h-4" /> Add option</button>
            </div>
            <p class="mt-1 text-sm text-muted">Add options like "Front / Rear" or "Pack of 2 / 4". Buyers pick one at checkout. Leave empty for a single-SKU listing — the price and quantity above are used.</p>

            @if (count($variants))
                <div class="mt-4 space-y-2">
                    <div class="hidden gap-2 px-1 text-xs font-semibold uppercase text-muted sm:grid sm:grid-cols-[44px_1fr_120px_100px_90px_32px]">
                        <span></span><span>Option name</span><span>SKU</span><span>Price</span><span>Qty</span><span></span>
                    </div>
                    @foreach ($variants as $i => $variant)
                        <div wire:key="var-{{ $i }}" class="grid gap-2 rounded-xl border border-line p-2 sm:grid-cols-[44px_1fr_120px_100px_90px_32px] sm:items-center sm:border-0 sm:p-0">
                            @if ($product && $existingImages->isNotEmpty())
                                <div x-data="{ open: false }" class="relative">
                                    <button type="button" x-on:click="open = !open" x-on:click.outside="open = false"
                                            class="grid h-10 w-10 place-items-center overflow-hidden rounded-lg border border-line bg-surface-2"
                                            title="Assign a photo to this option">
                                        @if ($variant['media_id'] && ($thumb = $existingImages->firstWhere('id', $variant['media_id'])))
                                            <img src="{{ $thumb->getUrl('thumb') ?: $thumb->getUrl() }}" alt="" class="h-full w-full object-cover">
                                        @else
                                            <x-ui.icon name="image" class="w-4 h-4 text-muted" />
                                        @endif
                                    </button>
                                    <div x-show="open" x-cloak x-transition
                                         class="absolute left-0 top-11 z-20 flex w-48 flex-wrap gap-1.5 rounded-xl border border-line bg-surface p-2 shadow-card-hover">
                                        <button type="button" wire:click="assignVariantImage({{ $i }}, null)" x-on:click="open = false"
                                                class="grid h-9 w-9 place-items-center rounded-lg border border-dashed border-line text-muted hover:border-accent hover:text-accent"
                                                title="No photo">
                                            <x-ui.icon name="x" class="w-4 h-4" />
                                        </button>
                                        @foreach ($existingImages as $media)
                                            <button type="button" wire:click="assignVariantImage({{ $i }}, {{ $media->id }})" x-on:click="open = false"
                                                    class="h-9 w-9 overflow-hidden rounded-lg border-2 {{ $variant['media_id'] == $media->id ? 'border-accent' : 'border-transparent' }}">
                                                <img src="{{ $media->getUrl('thumb') ?: $media->getUrl() }}" alt="" class="h-full w-full object-cover">
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            <div>
                                <input wire:model="variants.{{ $i }}.label" class="input h-10 text-sm" placeholder="e.g. Front axle">
                                @error("variants.$i.label") <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                            </div>
                            <input wire:model="variants.{{ $i }}.sku" class="input h-10 text-sm" placeholder="SKU">
                            <input type="number" step="0.01" wire:model="variants.{{ $i }}.price" class="input h-10 text-sm" placeholder="{{ number_format($price ?: 0, 2) }}">
                            <div>
                                <input type="number" wire:model="variants.{{ $i }}.quantity" class="input h-10 text-sm">
                                @error("variants.$i.quantity") <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                            </div>
                            <button type="button" wire:click="removeVariant({{ $i }})" class="btn-ghost !p-1.5 text-muted hover:text-rose-500" aria-label="Remove option">
                                <x-ui.icon name="trash" class="w-4 h-4" />
                            </button>
                        </div>
                    @endforeach
                </div>
                @if (! $product)
                    <p class="mt-2 text-xs text-muted">Save the listing first to assign a specific photo to each option.</p>
                @endif
            @endif
        </section>

        {{-- Volume discounts --}}
        <section class="card p-6">
            <div class="flex items-center justify-between">
                <h2 class="font-bold">Volume discounts</h2>
                <button type="button" wire:click="addVolumeTier" class="btn-outline btn-sm"><x-ui.icon name="plus" class="w-4 h-4" /> Add tier</button>
            </div>
            <p class="mt-1 text-sm text-muted">Reward bigger orders — e.g. 10% off at 4+, 15% off at 10+.</p>
            @if (count($volumeTiers))
                <div class="mt-4 space-y-2">
                    @foreach ($volumeTiers as $i => $tier)
                        <div wire:key="vt-{{ $i }}" class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="text-muted">Buy</span>
                            <input type="number" min="2" wire:model="volumeTiers.{{ $i }}.min_quantity" class="input h-9 w-20">
                            <span class="text-muted">or more →</span>
                            <select wire:model="volumeTiers.{{ $i }}.discount_type" class="input h-9 w-auto">
                                <option value="percentage">% off each</option>
                                <option value="fixed_per_unit">$ off each</option>
                            </select>
                            <input type="number" step="0.01" wire:model="volumeTiers.{{ $i }}.discount_value" class="input h-9 w-24">
                            <button type="button" wire:click="removeVolumeTier({{ $i }})" class="btn-ghost !p-1.5 text-muted hover:text-rose-500"><x-ui.icon name="trash" class="w-4 h-4" /></button>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Auto-pricing --}}
        <section class="card p-6">
            <label class="flex items-center gap-3">
                <input type="checkbox" wire:model.live="reprice_enabled" class="rounded border-line text-accent focus:ring-accent">
                <span class="font-bold">Auto-price against competitors</span>
            </label>
            <p class="mt-1 text-sm text-muted">We check other sellers of this part number hourly and adjust your price within your limits.</p>
            @if ($reprice_enabled)
                <div class="mt-4 grid gap-4 sm:grid-cols-4">
                    <div class="sm:col-span-2">
                        <label class="label" for="rp-strat">Strategy</label>
                        <select id="rp-strat" wire:model="reprice_strategy" class="input">
                            <option value="match_lowest">Match the lowest price</option>
                            <option value="beat_lowest">Beat the lowest by…</option>
                            <option value="fixed_above_lowest">Stay above the lowest by…</option>
                        </select>
                    </div>
                    <div>
                        <label class="label" for="rp-amt">Amount ($)</label>
                        <input id="rp-amt" type="number" step="0.01" wire:model="reprice_amount" class="input">
                    </div>
                    <div></div>
                    <div>
                        <label class="label" for="rp-floor">Never below ($)</label>
                        <input id="rp-floor" type="number" step="0.01" wire:model="reprice_floor" class="input" placeholder="{{ number_format($price ?: 0, 2) }}">
                    </div>
                    <div>
                        <label class="label" for="rp-ceil">Never above ($)</label>
                        <input id="rp-ceil" type="number" step="0.01" wire:model="reprice_ceiling" class="input" placeholder="No cap">
                    </div>
                </div>
                @if ($product && $product->repricingRule)
                    <div class="mt-3 flex items-center gap-3 text-xs text-muted">
                        <button type="button" wire:click="runRepriceNow" class="btn-outline btn-sm">Run now</button>
                        @if ($product->repricingRule->last_result)
                            <span>Last run: {{ $product->repricingRule->last_result }} ({{ $product->repricingRule->last_run_at?->diffForHumans() }})</span>
                        @endif
                    </div>
                @endif
            @endif
        </section>

        {{-- Details --}}
        <section class="card p-6">
            <h2 class="font-bold">Details</h2>
            <div class="mt-4">
                <label class="label" for="pf-desc">Description</label>
                <x-ui.rich-editor
                    wire-model="description"
                    :value="$description"
                    placeholder="Fitment, condition, what's in the box…"
                />
                <p class="mt-1 text-xs text-muted">Formatting (bold, lists, headings) shows exactly like this on the product page.</p>
            </div>
            <div class="mt-4">
                <label class="label" for="pf-bul">Highlights <span class="text-muted">(one per line)</span></label>
                <textarea id="pf-bul" wire:model="bulletText" rows="4" class="input" placeholder="Direct OEM fit&#10;Low-dust ceramic compound&#10;Includes hardware kit"></textarea>
            </div>
        </section>

        {{-- Photos --}}
        <section class="card p-6"
            x-data="{
                dragMediaId: null,
                dropOnMedia(targetId) {
                    if (this.dragMediaId === null || this.dragMediaId === targetId) return;
                    const ids = Array.from($refs.photoGrid.querySelectorAll('[data-media-id]')).map(el => parseInt(el.dataset.mediaId, 10));
                    const from = ids.indexOf(this.dragMediaId);
                    const to = ids.indexOf(targetId);
                    ids.splice(to, 0, ids.splice(from, 1)[0]);
                    this.dragMediaId = null;
                    $wire.reorderImages(ids);
                },
                filesOver: false,
                dropFiles(e) {
                    this.filesOver = false;
                    if (e.dataTransfer.files.length) $wire.uploadMultiple('newImages', e.dataTransfer.files);
                },
            }"
        >
            <div class="flex items-center justify-between">
                <h2 class="font-bold">Photos</h2>
                @if ($existingImages->isNotEmpty())
                    <span class="text-xs text-muted">{{ $existingImages->count() }} photo{{ $existingImages->count() === 1 ? '' : 's' }} · drag to reorder</span>
                @endif
            </div>

            @if ($existingImages->isNotEmpty())
                <div x-ref="photoGrid" class="mt-4 flex flex-wrap gap-3">
                    @foreach ($existingImages as $i => $media)
                        <div
                            wire:key="media-{{ $media->id }}"
                            data-media-id="{{ $media->id }}"
                            draggable="true"
                            x-on:dragstart="dragMediaId = {{ $media->id }}"
                            x-on:dragend="dragMediaId = null"
                            x-on:dragover.prevent
                            x-on:drop.prevent="dropOnMedia({{ $media->id }})"
                            class="group relative h-24 w-24 cursor-grab overflow-hidden rounded-xl border-2 transition active:cursor-grabbing"
                            :class="dragMediaId === {{ $media->id }} ? 'border-accent opacity-40' : 'border-line'"
                        >
                            <img src="{{ $media->getUrl('thumb') ?: $media->getUrl() }}" alt="" class="pointer-events-none h-full w-full object-cover">

                            <div class="pointer-events-none absolute left-1 top-1 grid h-5 w-5 place-items-center rounded-md bg-black/50 text-white opacity-0 transition group-hover:opacity-100">
                                <x-ui.icon name="grip" class="w-4 h-4" />
                            </div>

                            @if ($i === 0)
                                <span class="absolute bottom-1 left-1 rounded-full bg-accent px-1.5 py-0.5 text-[10px] font-semibold text-accent-fg">Cover</span>
                            @else
                                <button type="button" wire:click="setCoverImage({{ $media->id }})"
                                        class="absolute bottom-1 left-1 rounded-full bg-black/60 px-1.5 py-0.5 text-[10px] font-semibold text-white opacity-0 transition hover:bg-black/80 group-hover:opacity-100">
                                    Make cover
                                </button>
                            @endif

                            <button type="button" wire:click="removeImage({{ $media->id }})"
                                    class="absolute right-1 top-1 grid h-6 w-6 place-items-center rounded-full bg-black/60 text-white opacity-0 transition hover:bg-rose-600 group-hover:opacity-100"
                                    aria-label="Remove photo">
                                <x-ui.icon name="x" class="w-4 h-4" />
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif

            <label
                for="pf-img"
                x-on:dragover.prevent="filesOver = true"
                x-on:dragleave.prevent="filesOver = false"
                x-on:drop.prevent="dropFiles($event)"
                :class="filesOver ? 'border-accent bg-accent/5' : 'border-line'"
                class="mt-4 flex cursor-pointer flex-col items-center justify-center gap-1.5 rounded-xl border-2 border-dashed p-6 text-center transition"
            >
                <x-ui.icon name="upload" class="w-6 h-6 text-muted" />
                <span class="text-sm font-medium">Drop photos here, or click to browse</span>
                <span class="text-xs text-muted">JPG, PNG, or WEBP — first photo becomes the cover</span>
                <input id="pf-img" type="file" wire:model="newImages" multiple accept="image/*" class="hidden">
            </label>
            <div wire:loading wire:target="newImages" class="mt-1 text-xs text-muted">Uploading…</div>
            @error('newImages.*') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
        </section>

        {{-- Fitment --}}
        <section class="card p-6">
            <h2 class="font-bold">Vehicle fitment</h2>
            <p class="mt-1 text-sm text-muted">Add every vehicle this part fits so buyers can filter by their car.</p>

            <div class="mt-3 flex flex-wrap items-end gap-2 rounded-xl bg-surface-2 p-3">
                <button type="button" wire:click="suggestFitmentsFromPart" class="btn-outline btn-sm">
                    <x-ui.icon name="sparkles" class="w-4 h-4" /> Suggest from part #
                </button>
                <span class="text-xs text-muted">or</span>
                <div class="flex items-end gap-2">
                    <div>
                        <label class="label" for="pf-vin">Decode a VIN</label>
                        <input id="pf-vin" wire:model="vinLookup" maxlength="17" class="input h-9 w-56 font-mono text-xs uppercase" placeholder="1HGCM82633A004352">
                    </div>
                    <button type="button" wire:click="suggestFitmentsFromVin" class="btn-outline btn-sm">Decode</button>
                </div>
                @if ($fitmentSuggestNote)
                    <p class="w-full text-xs text-accent">{{ $fitmentSuggestNote }}</p>
                @endif
            </div>

            <div class="mt-3">
                @livewire('seller.fitment-picker', ['selected' => $fitmentVehicleIds], key('fitment-picker'))
            </div>
        </section>

        {{-- SEO --}}
        <section class="card p-6">
            <h2 class="font-bold">Search listing (optional)</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="pf-mt">Meta title</label>
                    <input id="pf-mt" wire:model="meta_title" maxlength="60" class="input">
                    @error('meta_title') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="pf-md">Meta description</label>
                    <input id="pf-md" wire:model="meta_description" maxlength="160" class="input">
                    @error('meta_description') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="btn-accent">Save &amp; submit for review</button>
            <button type="button" wire:click="saveDraft" class="btn-outline">Save draft</button>
            <a href="{{ route('seller.products.index') }}" class="btn-ghost">Cancel</a>
            @if ($product)
                <button type="button" wire:click="delete"
                        wire:confirm="Permanently delete &quot;{{ addslashes($product->title) }}&quot;? This can't be undone."
                        class="btn-ghost ml-auto text-rose-500 hover:bg-rose-500/10">Delete listing</button>
            @endif
        </div>
    </form>
</div>
