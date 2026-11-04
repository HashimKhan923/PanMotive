<div class="max-w-4xl">
    <h1 class="text-2xl font-bold">Product Q&amp;A</h1>
    <p class="mt-1 text-sm text-muted">Questions shoppers ask on your listings. Answers show publicly with a "Seller" badge — fast answers convert browsers into buyers.</p>

    <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="card p-4"><p class="text-xs text-muted">Needs an answer</p><p class="mt-1 text-2xl font-bold {{ $counts['unanswered'] ? 'text-accent' : '' }}">{{ $counts['unanswered'] }}</p></div>
        <div class="card p-4"><p class="text-xs text-muted">Waiting &gt;{{ $targetHours }}h</p><p class="mt-1 text-2xl font-bold {{ $stats['overdue'] ? 'text-rose-500' : '' }}">{{ $stats['overdue'] }}</p></div>
        <div class="card p-4"><p class="text-xs text-muted">Avg answer time · 30d</p><p class="mt-1 text-2xl font-bold">{{ $stats['avg_hours'] === null ? '—' : ($stats['avg_hours'] < 1 ? max(1, (int) round($stats['avg_hours'] * 60)).'m' : $stats['avg_hours'].'h') }}</p></div>
        <div class="card p-4"><p class="text-xs text-muted">Answered within {{ $targetHours }}h</p><p class="mt-1 text-2xl font-bold">{{ $stats['within_target'] === null ? '—' : $stats['within_target'].'%' }}</p></div>
    </div>

    <div class="mt-6 flex flex-wrap items-end justify-between gap-3 border-b border-line">
        <div class="flex gap-1 text-sm">
            @foreach (['unanswered' => 'Needs an answer', 'answered' => 'Answered', 'hidden' => 'Hidden'] as $key => $label)
                <button wire:click="$set('filter', '{{ $key }}')"
                        class="-mb-px border-b-2 px-3 py-2 font-medium transition {{ $filter === $key ? 'border-accent text-fg' : 'border-transparent text-muted hover:text-fg' }}">
                    {{ $label }}
                    @if ($counts[$key] > 0)
                        <span class="ml-1 rounded-full px-1.5 text-xs {{ $key === 'unanswered' ? 'bg-accent text-accent-fg' : 'bg-surface-2' }}">{{ $counts[$key] }}</span>
                    @endif
                </button>
            @endforeach
        </div>
        <div class="mb-2 flex w-full flex-wrap gap-2 sm:w-auto">
            @if ($products->count() > 1)
                <select wire:model.live="productId" class="input h-9 w-full text-sm sm:w-56" aria-label="Filter by product">
                    <option value="">All products</option>
                    @foreach ($products as $p)<option value="{{ $p->id }}">{{ \Illuminate\Support\Str::limit($p->title, 45) }}</option>@endforeach
                </select>
            @endif
            <div class="relative w-full sm:w-56">
                <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 w-4 h-4 -translate-y-1/2 text-muted" />
                <input wire:model.live.debounce.300ms="search" placeholder="Search questions or products" class="input h-9 pl-9 text-sm" aria-label="Search questions">
            </div>
        </div>
    </div>

    <div class="mt-4 space-y-3">
        @forelse ($questions as $q)
            @php($sellerAnswered = $q->answers->where('is_seller', true)->isNotEmpty())
            <div class="card p-5" wire:key="q-{{ $q->id }}">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <a href="{{ route('products.show', $q->product->slug) }}#questions" target="_blank" class="text-xs font-medium text-muted hover:text-accent">{{ $q->product->title }}</a>
                        <p class="mt-1 font-semibold">“{{ $q->body }}”</p>
                        <p class="mt-0.5 text-xs text-muted">
                            {{ $q->user?->name ?? $q->author_name ?? 'Guest' }} · {{ $q->created_at->diffForHumans() }}
                            @if (! $sellerAnswered && $q->status === 'published' && $q->created_at->lt(now()->subHours($targetHours)))
                                · <span class="font-semibold text-rose-500">waiting {{ $q->created_at->diffForHumans(null, true) }}</span>
                            @endif
                        </p>
                    </div>
                    <button wire:click="toggleHidden({{ $q->id }})" class="btn-ghost btn-sm text-muted">
                        {{ $q->status === 'rejected' ? 'Restore' : 'Hide' }}
                    </button>
                </div>

                @if ($q->answers->isNotEmpty())
                    <ul class="mt-3 space-y-2 border-l-2 border-line pl-3 text-sm">
                        @foreach ($q->answers as $a)
                            <li wire:key="a-{{ $a->id }}">
                                @if ($editingAnswerId === $a->id)
                                    <form wire:submit="updateAnswer" class="space-y-2">
                                        <textarea wire:model="editBody" rows="2" maxlength="1000" class="input text-sm" aria-label="Edit answer"></textarea>
                                        @error('editBody') <p class="text-xs text-rose-500">{{ $message }}</p> @enderror
                                        <div class="flex gap-2">
                                            <button class="btn-accent btn-sm">Save</button>
                                            <button type="button" wire:click="$set('editingAnswerId', null)" class="btn-ghost btn-sm">Cancel</button>
                                        </div>
                                    </form>
                                @else
                                    <div class="flex items-start justify-between gap-2">
                                        <div>
                                            @if ($a->is_seller)<span class="mr-1 rounded bg-accent px-1.5 text-[10px] font-semibold uppercase text-accent-fg">Seller</span>@endif
                                            {{ $a->body }}
                                            <span class="text-xs text-muted">— {{ $a->is_seller ? 'you' : ($a->user?->name ?? $a->author_name ?? 'Shopper') }}, {{ $a->created_at->diffForHumans() }}</span>
                                        </div>
                                        @if ($a->is_seller)
                                            <div class="flex shrink-0 gap-2 text-xs">
                                                <button wire:click="editAnswer({{ $a->id }})" class="text-muted hover:text-accent">Edit</button>
                                                <button wire:click="deleteAnswer({{ $a->id }})" wire:confirm="Remove this answer from the listing?" class="text-muted hover:text-rose-500">Remove</button>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($q->status === 'published')
                    <div class="mt-3">
                        <div class="mb-1.5 flex flex-wrap gap-1.5">
                            @if ($savedReplies->isNotEmpty())
                                <div x-data="{ open: false }" class="relative">
                                    <button type="button" @click="open = !open" class="btn-ghost btn-sm text-xs"><x-ui.icon name="list" class="w-4 h-4" /> Saved reply</button>
                                    <div x-show="open" x-cloak @click.outside="open = false" class="absolute left-0 top-full z-20 mt-1 w-72 rounded-xl border border-line bg-surface p-1 shadow-lg">
                                        @foreach ($savedReplies as $r)
                                            <button type="button" wire:click="useReply({{ $q->id }}, {{ $r->id }})" @click="open = false" class="block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-surface-2">
                                                <span class="font-medium">{{ $r->title }}</span>
                                                <span class="block truncate text-xs text-muted">{{ $r->body }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            @if ($aiEnabled)
                                <button type="button" wire:click="suggestAnswer({{ $q->id }})" wire:loading.attr="disabled" wire:target="suggestAnswer({{ $q->id }})" class="btn-ghost btn-sm text-xs">
                                    <x-ui.icon name="sparkles" class="w-4 h-4" />
                                    <span wire:loading.remove wire:target="suggestAnswer({{ $q->id }})">Suggest from listing</span>
                                    <span wire:loading wire:target="suggestAnswer({{ $q->id }})">Thinking…</span>
                                </button>
                            @endif
                        </div>
                        <div class="flex items-start gap-2">
                            <textarea wire:model="reply.{{ $q->id }}" rows="2" maxlength="1000" class="input text-sm"
                                      placeholder="{{ $sellerAnswered ? 'Add another answer…' : 'Write your answer…' }}" aria-label="Answer"></textarea>
                            <button wire:click="answer({{ $q->id }})" class="btn-accent btn-sm shrink-0">Answer</button>
                        </div>
                    </div>
                    @error("reply.{$q->id}") <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                @endif
            </div>
        @empty
            <div class="card grid place-items-center p-16 text-center">
                <x-ui.icon name="help" class="w-12 h-12 text-muted" />
                <p class="mt-3 font-semibold">{{ $filter === 'unanswered' ? "You're all caught up" : 'Nothing here' }}</p>
                <p class="mt-1 text-sm text-muted">{{ $filter === 'unanswered' ? 'New shopper questions will appear here, and you\'ll get a notification.' : 'Try another tab.' }}</p>
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $questions->links() }}</div>

    @if ($savedReplies->isEmpty() && \App\Support\SellerContext::can('messages'))
        <p class="mt-6 text-xs text-muted">Tip: create saved replies in <a href="{{ route('seller.messages.index') }}" class="text-fg underline underline-offset-2">Messages → Saved replies</a> and reuse them here.</p>
    @endif
</div>
