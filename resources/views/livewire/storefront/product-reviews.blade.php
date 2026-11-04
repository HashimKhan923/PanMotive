<section data-reveal>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h2 class="text-lg font-bold">Customer reviews</h2>
        @auth
            @if ($this->canReview && ! $showForm)
                <button wire:click="$set('showForm', true)" class="btn-outline btn-sm">Write a review</button>
            @elseif ($this->hasReviewed)
                <span class="text-xs text-muted">You reviewed this part</span>
            @endif
        @endauth
    </div>

    @if ($total > 0)
        <div class="mt-4 grid gap-6 sm:grid-cols-[200px_1fr] sm:items-center">
            <div class="text-center">
                <div class="text-4xl font-bold">{{ number_format($product->avg_rating, 1) }}</div>
                <x-ui.rating :value="$product->avg_rating" class="mt-1 justify-center" />
                <p class="mt-1 text-xs text-muted">{{ $total }} {{ Str::plural('review', $total) }}</p>
            </div>
            <div class="space-y-1.5">
                @foreach ($breakdown as $star => $count)
                    <div class="flex items-center gap-3 text-xs">
                        <span class="w-10 shrink-0 text-muted">{{ $star }} star</span>
                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-surface-2">
                            <div class="h-full rounded-full bg-chart" style="width: {{ $total ? round($count / $total * 100) : 0 }}%"></div>
                        </div>
                        <span class="w-8 shrink-0 text-right text-muted">{{ $count }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($showForm)
        <form wire:submit="submit" class="mt-6 card space-y-4 p-5">
            <div>
                <span class="label">Your rating</span>
                <div class="mt-1 flex gap-1">
                    @for ($i = 1; $i <= 5; $i++)
                        <button type="button" wire:click="$set('rating', {{ $i }})" class="transition hover:scale-110" aria-label="{{ $i }} stars">
                            <x-ui.icon name="star" :class="'w-8 h-8 ' . ($i <= $rating ? 'fill-current text-fg' : 'text-line')" />
                        </button>
                    @endfor
                </div>
                @error('rating') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="rv-title">Headline <span class="text-muted">(optional)</span></label>
                <input id="rv-title" type="text" wire:model="title" class="input mt-1" maxlength="120" placeholder="Sum it up in a line">
                @error('title') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="rv-body">Your review</label>
                <textarea id="rv-body" wire:model="body" rows="4" class="input mt-1" placeholder="How did it fit? How does it perform?"></textarea>
                @error('body') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-accent btn-sm">Publish review</button>
                <button type="button" wire:click="$set('showForm', false)" class="btn-ghost btn-sm">Cancel</button>
            </div>
        </form>
    @endif

    <div class="mt-6 space-y-5">
        @forelse ($reviews as $review)
            <article wire:key="rev-{{ $review->id }}" class="border-b border-line pb-5 last:border-0">
                <div class="flex items-center gap-2">
                    <x-ui.rating :value="$review->rating" size="w-4 h-4" />
                    @if ($review->is_verified)
                        <span class="pill !bg-emerald-500/10 !text-emerald-600 dark:!text-emerald-400">
                            <x-ui.icon name="check" class="w-4 h-4" /> Verified purchase
                        </span>
                    @endif
                </div>
                @if ($review->title)
                    <h3 class="mt-2 font-semibold">{{ $review->title }}</h3>
                @endif
                <p class="mt-1 text-sm text-muted">{{ $review->body }}</p>
                <div class="mt-2 flex items-center gap-3 text-xs text-muted">
                    <span>{{ $review->user?->name ?? 'Panmotive buyer' }}</span>
                    <span>·</span>
                    <span>{{ $review->created_at->format('M j, Y') }}</span>
                    <button wire:click="markHelpful({{ $review->id }})" class="ml-2 inline-flex items-center gap-1 hover:text-fg">
                        <x-ui.icon name="check" class="w-4 h-4" /> Helpful ({{ $review->helpful_count }})
                    </button>
                </div>

                @if ($review->seller_response)
                    <div class="mt-3 rounded-xl border-l-2 border-accent bg-surface-2 p-3">
                        <p class="text-xs font-semibold text-accent">Seller response</p>
                        <p class="mt-1 text-sm">{{ $review->seller_response }}</p>
                    </div>
                @endif
            </article>
        @empty
            <div class="card grid place-items-center p-10 text-center text-sm text-muted">
                <x-ui.icon name="star" class="w-8 h-8" />
                <p class="mt-2 font-medium text-fg">No reviews yet</p>
                <p>Verified buyers can review this part after delivery.</p>
            </div>
        @endforelse
    </div>

    @if ($reviews->hasPages())
        <div class="mt-6">{{ $reviews->links() }}</div>
    @endif
</section>
