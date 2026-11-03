<div class="max-w-4xl">
    <h1 class="text-2xl font-bold">Reviews</h1>
    <p class="mt-1 text-sm text-muted">Reply publicly to build trust — shoppers read seller replies, especially on critical reviews. Replies show on the product page and the reviewer is notified.</p>

    <div class="mt-6 grid gap-4 sm:grid-cols-[1fr_1fr_1.4fr]">
        <div class="card p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">Average rating</p>
            <p class="mt-2 text-3xl font-bold">{{ $summary['avg'] ? number_format($summary['avg'], 2) : '—' }}</p>
            <p class="mt-1 text-xs text-muted">{{ $summary['total'] }} {{ Str::plural('review', $summary['total']) }}
                @if ($summary['avg_90'] !== null && $summary['avg_prior'] !== null)
                    · last 90d {{ number_format($summary['avg_90'], 1) }}
                    <span class="{{ $summary['avg_90'] >= $summary['avg_prior'] ? 'text-emerald-600' : 'text-rose-500' }}">{{ $summary['avg_90'] >= $summary['avg_prior'] ? '▲' : '▼' }}</span>
                @endif
            </p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">Reply rate</p>
            <p class="mt-2 text-3xl font-bold">{{ $summary['response_rate'] === null ? '—' : $summary['response_rate'].'%' }}</p>
            <p class="mt-1 text-xs {{ $summary['critical_unanswered'] ? 'text-rose-500' : 'text-muted' }}">{{ $summary['critical_unanswered'] }} critical without a reply</p>
        </div>
        <div class="card space-y-1 p-5">
            @foreach ($summary['distribution'] as $s => $n)
                <button wire:click="$set('stars', '{{ $stars === (string) $s ? '' : $s }}')" class="flex w-full items-center gap-2 text-xs {{ $stars === (string) $s ? 'text-accent' : '' }}">
                    <span class="w-6 shrink-0 text-left">{{ $s }}★</span>
                    <span class="h-2 flex-1 overflow-hidden rounded-full bg-surface-2"><span class="block h-full rounded-full {{ $s >= 4 ? 'bg-emerald-500' : ($s === 3 ? 'bg-amber-400' : 'bg-rose-500') }}" style="width: {{ $summary['total'] ? round($n / $summary['total'] * 100) : 0 }}%"></span></span>
                    <span class="w-6 shrink-0 text-right text-muted">{{ $n }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <div class="mt-6 flex flex-wrap items-end justify-between gap-3 border-b border-line">
        <div class="flex gap-1 text-sm">
            @foreach (['all' => 'All', 'unanswered' => 'Needs a reply', 'critical' => 'Critical (≤3★)', 'reported' => 'Reported'] as $key => $label)
                <button wire:click="$set('filter', '{{ $key }}')"
                        class="-mb-px border-b-2 px-3 py-2 font-medium {{ $filter === $key ? 'border-accent text-accent' : 'border-transparent text-muted hover:text-fg' }}">
                    {{ $label }}
                    @if ($key === 'unanswered' && $summary['unanswered'])<span class="ml-1 rounded-full bg-accent px-1.5 text-xs text-accent-fg">{{ $summary['unanswered'] }}</span>@endif
                </button>
            @endforeach
        </div>
        <div class="mb-2 flex gap-2">
            @if ($stars !== '')<button wire:click="$set('stars', '')" class="btn-ghost btn-sm">{{ $stars }}★ only ✕</button>@endif
            @if ($products->count() > 1)
                <select wire:model.live="productId" class="input h-9 w-56 text-sm" aria-label="Filter by product">
                    <option value="">All products</option>
                    @foreach ($products as $p)<option value="{{ $p->id }}">{{ Str::limit($p->title, 45) }}</option>@endforeach
                </select>
            @endif
        </div>
    </div>

    <div class="mt-4 space-y-4">
        @forelse ($reviews as $review)
            <div class="card p-5" wire:key="rv-{{ $review->id }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <x-ui.rating :value="$review->rating" size="w-4 h-4" />
                        <span class="text-sm font-medium">{{ $review->title ?: 'Review' }}</span>
                        @if ($review->is_verified) <span class="pill !bg-emerald-500/10 !text-emerald-600">Verified</span> @endif
                        @if ($review->reported_at) <span class="pill !bg-amber-500/10 !text-amber-600">Reported</span> @endif
                    </div>
                    <a href="{{ route('products.show', $review->product->slug) }}#reviews" target="_blank" class="text-xs text-muted hover:text-accent">{{ $review->product->title }}</a>
                </div>
                <p class="mt-2 text-sm text-muted">{{ $review->body }}</p>
                <p class="mt-1 text-xs text-muted">{{ $review->user?->name ?? 'Buyer' }} · {{ $review->created_at->format('M j, Y') }}</p>

                @if ($review->seller_response && ! array_key_exists($review->id, $reply))
                    <div class="mt-3 rounded-xl bg-surface-2 p-3">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs font-semibold text-accent">Your reply · {{ $review->seller_responded_at?->format('M j, Y') }}</p>
                            <div class="flex gap-3 text-xs">
                                <button wire:click="edit({{ $review->id }})" class="text-muted hover:text-accent">Edit</button>
                                <button wire:click="removeResponse({{ $review->id }})" wire:confirm="Remove your reply from the listing?" class="text-muted hover:text-rose-500">Remove</button>
                            </div>
                        </div>
                        <p class="mt-1 text-sm">{{ $review->seller_response }}</p>
                    </div>
                @else
                    <div class="mt-3 border-t border-line pt-3">
                        <div class="mb-1.5 flex flex-wrap gap-1.5">
                            @if ($savedReplies->isNotEmpty())
                                <div x-data="{ open: false }" class="relative">
                                    <button type="button" @click="open = !open" class="btn-ghost btn-sm text-xs"><x-ui.icon name="list" class="w-3.5 h-3.5" /> Saved reply</button>
                                    <div x-show="open" x-cloak @click.outside="open = false" class="absolute left-0 top-full z-20 mt-1 w-72 rounded-xl border border-line bg-surface p-1 shadow-lg">
                                        @foreach ($savedReplies as $r)
                                            <button type="button" wire:click="useReply({{ $review->id }}, {{ $r->id }})" @click="open = false" class="block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-surface-2">
                                                <span class="font-medium">{{ $r->title }}</span>
                                                <span class="block truncate text-xs text-muted">{{ $r->body }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            @if ($aiEnabled)
                                <button type="button" wire:click="suggest({{ $review->id }})" wire:loading.attr="disabled" wire:target="suggest({{ $review->id }})" class="btn-ghost btn-sm text-xs">
                                    <x-ui.icon name="sparkles" class="w-3.5 h-3.5" />
                                    <span wire:loading.remove wire:target="suggest({{ $review->id }})">Draft a reply</span>
                                    <span wire:loading wire:target="suggest({{ $review->id }})">Writing…</span>
                                </button>
                            @endif
                            @unless ($review->reported_at)
                                <button type="button" wire:click="startReport({{ $review->id }})" class="btn-ghost btn-sm text-xs text-muted">Report review</button>
                            @endunless
                        </div>
                        <div class="flex items-start gap-2">
                            <textarea wire:model="reply.{{ $review->id }}" rows="2" maxlength="1500" class="input text-sm"
                                      placeholder="{{ $review->rating <= 3 ? 'Acknowledge the problem and offer to help through Messages…' : 'Thank them…' }}" aria-label="Public reply"></textarea>
                            <button wire:click="respond({{ $review->id }})" class="btn-accent btn-sm shrink-0">{{ $review->seller_response ? 'Update' : 'Reply' }}</button>
                        </div>
                        @error("reply.{$review->id}") <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    </div>
                @endif

                @if ($reportingId === $review->id)
                    <form wire:submit="submitReport" class="mt-3 space-y-2 rounded-xl border border-line p-3">
                        <p class="text-sm font-semibold">Ask Pan Motive to review this review</p>
                        <p class="text-xs text-muted">Honest negative reviews stay up. We remove reviews that break the rules.</p>
                        <select wire:model="reportReason" class="input h-9 text-sm" aria-label="Reason">
                            <option value="">Choose a reason…</option>
                            @foreach ($reasons as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                        @error('reportReason') <p class="text-xs text-rose-500">{{ $message }}</p> @enderror
                        <textarea wire:model="reportNote" rows="2" maxlength="1000" class="input text-sm" placeholder="Anything that helps us decide (optional)"></textarea>
                        <div class="flex gap-2">
                            <button class="btn-accent btn-sm">Send report</button>
                            <button type="button" wire:click="$set('reportingId', null)" class="btn-ghost btn-sm">Cancel</button>
                        </div>
                    </form>
                @endif
            </div>
        @empty
            <div class="card grid place-items-center p-16 text-center">
                <x-ui.icon name="star" class="w-10 h-10 text-muted" />
                <p class="mt-3 font-semibold">{{ $filter === 'unanswered' ? 'Every review has a reply' : 'No reviews here' }}</p>
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $reviews->links() }}</div>
</div>
