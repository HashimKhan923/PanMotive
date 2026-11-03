<div class="container-x py-10" data-reveal>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold sm:text-3xl">Compare parts</h1>
            <p class="mt-1 text-sm text-muted">{{ $products->count() }} of {{ \App\Support\Compare::MAX }} slots used</p>
        </div>
        @if ($products->isNotEmpty())
            <button wire:click="clear" class="btn-ghost btn-sm">Clear all</button>
        @endif
    </div>

    @if ($products->isEmpty())
        <div class="mt-8 card grid place-items-center p-16 text-center">
            <x-ui.icon name="grid" class="w-12 h-12 text-muted" />
            <p class="mt-4 text-lg font-semibold">Nothing to compare yet</p>
            <p class="mt-1 text-sm text-muted">Add up to {{ \App\Support\Compare::MAX }} parts from any listing to see them side by side.</p>
            <a href="{{ route('products.index') }}" class="btn-accent mt-6">Browse parts</a>
        </div>
    @else
        <div class="mt-8 overflow-x-auto">
            <table class="w-full min-w-[640px] border-separate border-spacing-0 text-sm">
                <thead>
                    <tr>
                        <th class="w-40 bg-surface"></th>
                        @foreach ($products as $product)
                            <th wire:key="ch-{{ $product->id }}" class="p-3 align-top">
                                <div class="card overflow-hidden text-left">
                                    <a href="{{ route('products.show', $product->slug) }}" class="block aspect-video overflow-hidden bg-surface-2">
                                        <img src="{{ $product->getFirstMediaUrl('images', 'thumb') ?: $product->getFirstMediaUrl('images') }}" alt="{{ $product->title }}" class="h-full w-full object-cover">
                                    </a>
                                    <div class="p-3">
                                        <a href="{{ route('products.show', $product->slug) }}" class="line-clamp-2 font-semibold hover:text-accent">{{ $product->title }}</a>
                                        <button wire:click="remove({{ $product->id }})" class="mt-2 text-xs text-muted hover:text-rose-500">Remove</button>
                                    </div>
                                </div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="[&_td]:border-t [&_td]:border-line [&_td]:px-4 [&_td]:py-3 [&_th]:border-t [&_th]:border-line [&_th]:px-4 [&_th]:py-3 [&_th]:text-left [&_th]:font-semibold [&_th]:text-muted">
                    <tr>
                        <th>Price</th>
                        @foreach ($products as $product)
                            <td wire:key="pr-{{ $product->id }}"><x-ui.price :price="$product->price" :sale="$product->liveSalePrice()" size="text-lg" /></td>
                        @endforeach
                    </tr>
                    <tr>
                        <th>Rating</th>
                        @foreach ($products as $product)
                            <td wire:key="rt-{{ $product->id }}">
                                @if ($product->reviews_count > 0)
                                    <x-ui.rating :value="$product->avg_rating" :count="$product->reviews_count" />
                                @else
                                    <span class="text-muted">No reviews</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                    <tr>
                        <th>Brand</th>
                        @foreach ($products as $product)
                            <td wire:key="br-{{ $product->id }}">{{ $product->brand?->name ?? '—' }}</td>
                        @endforeach
                    </tr>
                    <tr>
                        <th>Condition</th>
                        @foreach ($products as $product)
                            <td wire:key="cn-{{ $product->id }}" class="capitalize">{{ $product->condition }}</td>
                        @endforeach
                    </tr>
                    <tr>
                        <th>Part number</th>
                        @foreach ($products as $product)
                            <td wire:key="pn-{{ $product->id }}">{{ $product->part_number ?: '—' }}</td>
                        @endforeach
                    </tr>
                    <tr>
                        <th>Availability</th>
                        @foreach ($products as $product)
                            <td wire:key="av-{{ $product->id }}">
                                @if ($product->quantity > 0)
                                    <span class="text-emerald-600 dark:text-emerald-400">In stock</span>
                                @else
                                    <span class="text-rose-500">Out of stock</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                    <tr>
                        <th>Vehicles it fits</th>
                        @foreach ($products as $product)
                            <td wire:key="ft-{{ $product->id }}">{{ $product->fitments->count() ?: '—' }}</td>
                        @endforeach
                    </tr>
                    <tr>
                        <th>Sold by</th>
                        @foreach ($products as $product)
                            <td wire:key="sb-{{ $product->id }}">{{ $product->seller?->sellerProfile?->business_name ?? 'Verified seller' }}</td>
                        @endforeach
                    </tr>

                    @foreach ($attributeNames as $name)
                        <tr wire:key="attr-{{ $loop->index }}">
                            <th>{{ $name }}</th>
                            @foreach ($products as $product)
                                <td wire:key="attr-{{ $loop->parent->index }}-{{ $product->id }}">
                                    {{ optional($product->attributeValues->firstWhere(fn ($av) => $av->attribute?->name === $name))->value ?: '—' }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach

                    <tr>
                        <th></th>
                        @foreach ($products as $product)
                            <td wire:key="bt-{{ $product->id }}">
                                <button wire:click="addToCart({{ $product->id }})" @disabled($product->quantity < 1) class="btn-accent btn-sm w-full disabled:opacity-50">Add to cart</button>
                            </td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>
    @endif
</div>
