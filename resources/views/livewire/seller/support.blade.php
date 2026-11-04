<div class="max-w-5xl">
    @if ($active)
        {{-- ticket thread --}}
        <div class="flex flex-wrap items-center gap-3">
            <button wire:click="back" class="btn-ghost !p-2" aria-label="Back to help & support"><x-ui.icon name="arrow-left" class="w-6 h-6" /></button>
            <div class="min-w-0">
                <h1 class="truncate text-xl font-bold">{{ $active->subject }}</h1>
                <p class="text-sm text-muted">
                    {{ $active->ticket_number }} · {{ $categories[$active->category] ?? $active->category }}
                    @if ($active->orderItem) · Order {{ $active->orderItem->order->order_number }} @endif
                </p>
            </div>
            <span class="pill ml-auto {{ $active->status === 'answered' ? '!bg-sky-500/10 !text-sky-600' : ($active->status === 'resolved' ? '!bg-emerald-500/10 !text-emerald-600' : '') }}">{{ $active->statusLabel() }}</span>
        </div>

        <div class="mt-6 space-y-3">
            @foreach ($active->messages as $m)
                <div class="flex {{ $m->author_role === 'seller' ? 'justify-end' : '' }}">
                    <div class="max-w-[85%] rounded-2xl px-4 py-3 text-sm {{ $m->author_role === 'seller' ? 'bg-accent/10' : 'border border-line bg-surface' }}">
                        <p class="text-xs font-semibold {{ $m->author_role === 'support' ? 'text-accent' : 'text-muted' }}">
                            {{ $m->author_role === 'support' ? 'Panmotive Support' : ($m->user?->name ?? 'You') }} · {{ $m->created_at->format('M j, g:i A') }}
                        </p>
                        <p class="mt-1 whitespace-pre-line">{{ $m->body }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card mt-6 p-4">
            <textarea wire:model="reply" rows="3" maxlength="5000" class="input text-sm" placeholder="{{ $active->status === 'resolved' ? 'Reply to reopen this ticket…' : 'Write a reply…' }}" aria-label="Reply"></textarea>
            @error('reply') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            <div class="mt-3 flex flex-wrap justify-between gap-2">
                @if ($active->status !== 'resolved')
                    <button wire:click="resolve" class="btn-ghost btn-sm text-muted">Mark as resolved</button>
                @else
                    <span></span>
                @endif
                <button wire:click="sendReply" class="btn-accent btn-sm">Send reply</button>
            </div>
        </div>
    @else
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">Help &amp; support</h1>
                <p class="mt-1 text-sm text-muted">Quick answers below — or message the Panmotive seller support team directly.</p>
            </div>
            <button wire:click="$toggle('composing')" class="btn-accent btn-sm"><x-ui.icon name="message" class="w-4 h-4" /> Contact support</button>
        </div>

        @if ($composing)
            <section class="card mt-6 space-y-4 p-6">
                <h2 class="font-bold">New support request</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="st-cat">Topic</label>
                        <select id="st-cat" wire:model="category" class="input">
                            @foreach ($categories as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="st-order">Order number <span class="text-muted">(if it's about an order)</span></label>
                        <input id="st-order" wire:model="orderNumber" class="input font-mono uppercase" placeholder="PM-XXXXXXXXXX">
                        @error('orderNumber') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label class="label" for="st-subj">Subject</label>
                    <input id="st-subj" wire:model="subject" maxlength="160" class="input" placeholder="e.g. Payout for last week hasn't arrived">
                    @error('subject') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="st-body">How can we help?</label>
                    <textarea id="st-body" wire:model="body" rows="5" maxlength="5000" class="input" placeholder="Include as much detail as you can — what you expected, what happened, and anything you've already tried."></textarea>
                    @error('body') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-2">
                    <button wire:click="$set('composing', false)" class="btn-ghost btn-sm">Cancel</button>
                    <button wire:click="submit" class="btn-accent btn-sm">Send to support</button>
                </div>
            </section>
        @endif

        <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_340px]">
            <section>
                <h2 class="font-bold">Common questions</h2>
                <div class="mt-3 space-y-2">
                    @foreach ($faqs as $i => $f)
                        <div x-data="{ open: {{ $i === 0 ? 'true' : 'false' }} }" class="card">
                            <button type="button" @click="open = !open" class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left text-sm font-semibold">
                                {{ $f['q'] }}
                                <x-ui.icon name="chevron-down" class="w-4 h-4 shrink-0 transition" ::class="open && 'rotate-180'" />
                            </button>
                            <p x-show="open" x-cloak x-transition class="px-5 pb-4 text-sm text-muted">{{ $f['a'] }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            <section>
                <h2 class="font-bold">Your requests</h2>
                <div class="mt-3 space-y-2">
                    @forelse ($tickets as $t)
                        <button wire:click="open({{ $t->id }})" wire:key="t-{{ $t->id }}" class="card block w-full p-4 text-left transition hover:border-accent">
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-sm font-semibold">{{ $t->subject }}</p>
                                @if ($t->seller_unread)<span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-accent" title="New reply"></span>@endif
                            </div>
                            <p class="mt-1 text-xs text-muted">{{ $t->ticket_number }} · {{ $t->statusLabel() }} · {{ $t->last_reply_at?->diffForHumans() }}</p>
                        </button>
                    @empty
                        <div class="card p-6 text-center text-sm text-muted">No support requests yet.</div>
                    @endforelse
                </div>
            </section>
        </div>
    @endif
</div>
