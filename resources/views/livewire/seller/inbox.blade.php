<div wire:poll.15s="refreshThread">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Messages</h1>
            <p class="mt-1 text-sm text-muted">Answer buyer questions about fitment, condition, shipping and orders. Aim to reply within {{ $targetHours }} hours.</p>
        </div>
        <button wire:click="editReply" class="btn-ghost btn-sm"><x-ui.icon name="list" class="w-4 h-4" /> Saved replies</button>
    </div>

    {{-- response health --}}
    <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <button wire:click="$set('filter', 'needs_reply')" class="card p-4 text-left transition hover:border-accent">
            <p class="text-xs text-muted">Needs a reply</p>
            <p class="mt-1 text-2xl font-bold {{ $stats['needs_reply'] ? 'text-accent' : '' }}">{{ $stats['needs_reply'] }}</p>
        </button>
        <div class="card p-4">
            <p class="text-xs text-muted">Overdue (&gt;{{ $targetHours }}h)</p>
            <p class="mt-1 text-2xl font-bold {{ $stats['overdue'] ? 'text-rose-500' : '' }}">{{ $stats['overdue'] }}</p>
        </div>
        <div class="card p-4">
            <p class="text-xs text-muted">Avg first response · 30d</p>
            <p class="mt-1 text-2xl font-bold">
                @if ($stats['avg_first_response_hours'] === null) — @elseif ($stats['avg_first_response_hours'] < 1) {{ max(1, (int) round($stats['avg_first_response_hours'] * 60)) }}m @else {{ $stats['avg_first_response_hours'] }}h @endif
            </p>
        </div>
        <div class="card p-4">
            <p class="text-xs text-muted">Replied within {{ $targetHours }}h · 30d</p>
            <p class="mt-1 text-2xl font-bold {{ $stats['within_target_rate'] !== null && $stats['within_target_rate'] < 85 ? 'text-amber-500' : '' }}">{{ $stats['within_target_rate'] === null ? '—' : $stats['within_target_rate'].'%' }}</p>
        </div>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-[300px_1fr] xl:grid-cols-[300px_1fr_280px]">
        {{-- list --}}
        <div class="card overflow-hidden">
            <div class="border-b border-line p-2">
                <div class="relative">
                    <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 w-4 h-4 -translate-y-1/2 text-muted" />
                    <input wire:model.live.debounce.300ms="search" placeholder="Search buyer, order #, text" class="input h-9 pl-9 text-sm" aria-label="Search conversations">
                </div>
                <div class="mt-2 flex flex-wrap gap-1 text-xs">
                    @foreach (['needs_reply' => 'Needs reply', 'all' => 'Open', 'unread' => 'Unread', 'closed' => 'Archived'] as $key => $label)
                        <button wire:click="$set('filter', '{{ $key }}')"
                                class="rounded-lg px-2.5 py-1.5 font-medium {{ $filter === $key ? 'bg-accent/10 text-accent' : 'text-muted hover:text-fg' }}">
                            {{ $label }}
                            @if (($counts[$key] ?? 0) > 0)<span class="ml-0.5 rounded-full bg-surface-2 px-1.5">{{ $counts[$key] }}</span>@endif
                        </button>
                    @endforeach
                </div>
            </div>

            <ul class="max-h-[70vh] divide-y divide-line overflow-y-auto">
                @forelse ($conversations as $c)
                    <li wire:key="c-{{ $c->id }}">
                        <button wire:click="open({{ $c->id }})"
                                class="w-full px-4 py-3 text-left transition hover:bg-surface-2 {{ $active?->id === $c->id ? 'bg-surface-2' : '' }}">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate text-sm {{ $c->seller_unread > 0 ? 'font-bold' : 'font-semibold' }}">{{ $c->buyer->name }}</span>
                                <span class="flex shrink-0 items-center gap-1">
                                    @if ($c->isOverdue())
                                        <span class="rounded-full bg-rose-500/10 px-1.5 text-[10px] font-semibold text-rose-500">Overdue</span>
                                    @elseif ($c->awaiting_reply_since && ! $c->closed)
                                        <span class="rounded-full bg-amber-500/10 px-1.5 text-[10px] font-semibold text-amber-600">Reply</span>
                                    @endif
                                    @if ($c->seller_unread > 0)
                                        <span class="rounded-full bg-accent px-1.5 text-[10px] font-bold text-accent-fg">{{ $c->seller_unread }}</span>
                                    @endif
                                </span>
                            </div>
                            <p class="truncate text-xs text-muted">{{ $c->product?->title ?? $c->subject }}</p>
                            <p class="mt-0.5 truncate text-xs text-muted">{{ $c->latestMessage->first()?->body }}</p>
                            <p class="mt-0.5 text-[10px] text-muted">
                                {{ optional($c->last_message_at ?? $c->created_at)->diffForHumans() }}
                                @if ($c->awaiting_reply_since && ! $c->closed) · waiting {{ $c->awaiting_reply_since->diffForHumans(null, true) }} @endif
                            </p>
                        </button>
                    </li>
                @empty
                    <li class="px-4 py-16 text-center text-sm text-muted">
                        {{ $filter === 'needs_reply' ? "You're all caught up — no buyers waiting." : 'No conversations here.' }}
                    </li>
                @endforelse
            </ul>
        </div>

        {{-- thread --}}
        <div class="card flex flex-col overflow-hidden" style="min-height: 60vh;">
            @if (! $active)
                <div class="grid flex-1 place-items-center p-10 text-center text-sm text-muted">
                    <div>
                        <x-ui.icon name="mail" class="mx-auto w-10 h-10" />
                        <p class="mt-3">Select a conversation to read and reply.</p>
                    </div>
                </div>
            @else
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3">
                    <div class="min-w-0">
                        <p class="truncate font-semibold">{{ $active->buyer->name }}</p>
                        <p class="truncate text-xs text-muted">
                            @if ($active->product)
                                <a href="{{ route('products.show', $active->product->slug) }}" target="_blank" class="hover:text-accent">{{ $active->product->title }}</a>
                            @else
                                {{ $active->subject }}
                            @endif
                            @if ($active->order) · Order {{ $active->order->order_number }} @endif
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-1">
                        @if ($active->awaiting_reply_since && ! $active->closed)
                            <button wire:click="markHandled" class="btn-ghost btn-sm" title="Answered another way — clear the reply timer">Mark handled</button>
                        @endif
                        <button wire:click="markUnread" class="btn-ghost btn-sm">Mark unread</button>
                        <button wire:click="toggleClosed" class="btn-ghost btn-sm">{{ $active->closed ? 'Reopen' : 'Archive' }}</button>
                    </div>
                </div>

                <div class="flex-1 space-y-3 overflow-y-auto p-5" x-data x-init="$el.scrollTop = $el.scrollHeight">
                    @foreach ($active->messages as $m)
                        @php($mine = $m->sender_id === auth()->id())
                        <div class="flex {{ $mine ? 'justify-end' : 'justify-start' }}" wire:key="m-{{ $m->id }}">
                            <div class="max-w-[75%] rounded-2xl px-4 py-2 text-sm {{ $mine ? 'bg-accent text-accent-fg' : 'bg-surface-2' }}">
                                @if ($m->attachments)
                                    <div class="mb-1.5 grid grid-cols-2 gap-1.5">
                                        @foreach ($m->attachmentLinks() as $a)
                                            <a href="{{ $a['url'] }}" target="_blank" rel="noopener"><img src="{{ $a['url'] }}" alt="{{ $a['name'] }}" class="h-28 w-full rounded-lg object-cover" loading="lazy"></a>
                                        @endforeach
                                    </div>
                                @endif
                                <p class="whitespace-pre-wrap">{{ $m->body }}</p>
                                <p class="mt-1 text-[10px] {{ $mine ? 'text-accent-fg/70' : 'text-muted' }}">
                                    {{ $m->created_at->format('M j, g:i A') }}
                                    @if ($mine && $context['lastMine']?->id === $m->id) · {{ $m->read_at ? 'Seen' : 'Delivered' }} @endif
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($context['blocked'])
                    <p class="border-t border-line p-4 text-center text-xs text-muted">You've blocked this buyer. Unblock them from the panel to reply.</p>
                @else
                    <form wire:submit="reply" class="border-t border-line p-3">
                        <div class="mb-2 flex flex-wrap items-center gap-1.5">
                            <div x-data="{ open: false }" class="relative">
                                <button type="button" @click="open = !open" class="btn-ghost btn-sm"><x-ui.icon name="list" class="w-4 h-4" /> Saved reply</button>
                                <div x-show="open" x-cloak @click.outside="open = false" class="absolute bottom-full left-0 z-20 mb-1 w-72 rounded-xl border border-line bg-surface p-1 shadow-lg">
                                    @forelse ($savedReplies as $r)
                                        <button type="button" wire:click="useReply({{ $r->id }})" @click="open = false" class="block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-surface-2">
                                            <span class="font-medium">{{ $r->title }}</span>
                                            <span class="block truncate text-xs text-muted">{{ $r->body }}</span>
                                        </button>
                                    @empty
                                        <div class="p-3 text-xs text-muted">
                                            No saved replies yet.
                                            <button type="button" wire:click="addStarterReplies" @click="open = false" class="mt-2 block font-medium text-accent hover:underline">Add 5 starter replies</button>
                                        </div>
                                    @endforelse
                                    <button type="button" wire:click="editReply" @click="open = false" class="mt-1 block w-full border-t border-line px-3 py-2 text-left text-xs font-medium text-accent">Manage saved replies…</button>
                                </div>
                            </div>
                            @if ($aiEnabled)
                                <button type="button" wire:click="draftWithAi" wire:loading.attr="disabled" wire:target="draftWithAi" class="btn-ghost btn-sm">
                                    <x-ui.icon name="sparkles" class="w-4 h-4" />
                                    <span wire:loading.remove wire:target="draftWithAi">Draft with AI</span>
                                    <span wire:loading wire:target="draftWithAi">Drafting…</span>
                                </button>
                            @endif
                            <label class="btn-ghost btn-sm cursor-pointer">
                                <x-ui.icon name="image" class="w-4 h-4" /> Photo
                                <input type="file" wire:model="photos" multiple accept="image/*" class="hidden">
                            </label>
                            <span wire:loading wire:target="photos" class="text-xs text-muted">Uploading…</span>
                        </div>

                        @if ($photos)
                            <div class="mb-2 flex flex-wrap gap-2">
                                @foreach ($photos as $i => $photo)
                                    <div class="relative" wire:key="ph-{{ $i }}">
                                        @if (method_exists($photo, 'isPreviewable') && $photo->isPreviewable())
                                            <img src="{{ $photo->temporaryUrl() }}" class="h-16 w-16 rounded-lg object-cover" alt="">
                                        @else
                                            <div class="grid h-16 w-16 place-items-center rounded-lg bg-surface-2 text-[10px] text-muted">file</div>
                                        @endif
                                        <button type="button" wire:click="removePhoto({{ $i }})" class="absolute -right-1.5 -top-1.5 grid h-5 w-5 place-items-center rounded-full bg-fg text-bg" aria-label="Remove photo"><x-ui.icon name="x" class="w-3 h-3" /></button>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <div class="flex gap-2">
                            <textarea wire:model="body" rows="3" maxlength="4000" class="input flex-1 text-sm" placeholder="Write a reply…"
                                      @keydown.ctrl.enter="$wire.reply()" @keydown.meta.enter="$wire.reply()"></textarea>
                            <button class="btn-accent btn-sm self-end" wire:loading.attr="disabled" wire:target="reply,photos">Send</button>
                        </div>
                        <p class="mt-1 text-[10px] text-muted">Ctrl+Enter to send. Keep payments and contact details on PanMotive — it protects you in disputes.</p>
                        @error('body') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                        @error('photos') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                        @error('photos.*') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    </form>
                @endif
            @endif
        </div>

        {{-- buyer context --}}
        @if ($active)
            <aside class="card h-fit space-y-4 p-4 text-sm lg:col-span-2 xl:col-span-1">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted">Buyer</p>
                    <p class="mt-1 font-semibold">{{ $active->buyer->name }}</p>
                    <p class="text-xs text-muted">PanMotive member since {{ $active->buyer->created_at?->format('M Y') }}</p>
                    @if ($context['badges'])
                        <div class="mt-1.5 flex flex-wrap gap-1">
                            @foreach ($context['badges'] as $b)<span class="pill text-[10px]">{{ $b }}</span>@endforeach
                        </div>
                    @endif
                </div>

                @if ($context['stats'])
                    <div class="grid grid-cols-2 gap-2">
                        <div class="rounded-lg bg-surface-2 p-2"><p class="text-[10px] text-muted">Orders</p><p class="font-semibold">{{ $context['stats']->orders_count }}</p></div>
                        <div class="rounded-lg bg-surface-2 p-2"><p class="text-[10px] text-muted">Spent with you</p><p class="font-semibold">${{ number_format((float) $context['stats']->spent, 2) }}</p></div>
                    </div>
                    @if (\App\Support\SellerContext::can('orders.view'))
                        <a href="{{ route('seller.customers.show', $active->buyer_id) }}" class="block text-xs font-medium text-accent hover:underline">Open customer profile →</a>
                    @endif
                @else
                    <p class="text-xs text-muted">Hasn't bought from you yet — a quick, helpful answer is your best chance to win the sale.</p>
                @endif

                @if ($context['items']->isNotEmpty())
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted">Their orders with you</p>
                        <ul class="mt-2 space-y-2">
                            @foreach ($context['items'] as $item)
                                <li class="rounded-lg border border-line p-2">
                                    <p class="truncate text-xs font-medium">{{ $item->product_title }}</p>
                                    <p class="text-[11px] text-muted">
                                        <a href="{{ route('seller.orders.show', $item) }}" class="hover:text-accent">{{ $item->order?->order_number }}</a>
                                        · {{ $item->status->label() }}
                                        @if ($item->isLate()) · <span class="text-rose-500">late</span> @endif
                                    </p>
                                    @if ($item->trackingUrl())
                                        <a href="{{ $item->trackingUrl() }}" target="_blank" rel="noopener" class="text-[11px] text-accent hover:underline">Track {{ $item->tracking_number }} ↗</a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($context['note']?->notes || $context['note']?->tags)
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted">Your notes</p>
                        @if ($context['note']->tags)
                            <div class="mt-1 flex flex-wrap gap-1">@foreach ($context['note']->tags as $t)<span class="rounded bg-surface-2 px-1.5 text-[10px]">#{{ $t }}</span>@endforeach</div>
                        @endif
                        <p class="mt-1 whitespace-pre-wrap text-xs">{{ \Illuminate\Support\Str::limit($context['note']->notes, 300) }}</p>
                    </div>
                @endif

                <div class="border-t border-line pt-3" x-data="{ confirm: false }">
                    @if ($context['blocked'])
                        <p class="text-xs text-rose-500">Blocked {{ $context['blocked']->created_at->diffForHumans() }}{{ $context['blocked']->reason ? ' — '.$context['blocked']->reason : '' }}</p>
                        <button wire:click="toggleBlock" class="mt-2 text-xs font-medium text-accent hover:underline">Unblock buyer</button>
                    @else
                        <button @click="confirm = !confirm" class="text-xs text-muted hover:text-rose-500">Block this buyer…</button>
                        <div x-show="confirm" x-cloak class="mt-2 space-y-2">
                            <p class="text-[11px] text-muted">They won't be able to message you, make offers, ask on your listings or buy your items. Open orders are not affected.</p>
                            <input wire:model="blockReason" class="input h-8 text-xs" placeholder="Reason (private)" maxlength="255">
                            <button wire:click="toggleBlock" class="btn-sm rounded-lg bg-rose-500 px-3 py-1.5 text-xs font-semibold text-white">Block buyer</button>
                        </div>
                    @endif
                </div>
            </aside>
        @endif
    </div>

    {{-- saved replies manager --}}
    @if ($managingReplies)
        <div class="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4" wire:click.self="$set('managingReplies', false)">
            <div class="card max-h-[90vh] w-full max-w-3xl overflow-y-auto p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-bold">Saved replies</h2>
                    <button wire:click="$set('managingReplies', false)" class="btn-ghost btn-sm" aria-label="Close"><x-ui.icon name="x" class="w-4 h-4" /></button>
                </div>
                <p class="mt-1 text-sm text-muted">Reusable answers for messages and Product Q&amp;A. Placeholders fill in automatically.</p>

                <div class="mt-4 grid gap-5 md:grid-cols-2">
                    <div>
                        <ul class="space-y-2">
                            @forelse ($savedReplies as $r)
                                <li class="rounded-xl border p-3 {{ $editingReplyId === $r->id ? 'border-accent' : 'border-line' }}" wire:key="sr-{{ $r->id }}">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="text-sm font-semibold">{{ $r->title }}</p>
                                        <span class="shrink-0 text-[10px] text-muted">used {{ $r->use_count }}×</span>
                                    </div>
                                    <p class="mt-1 line-clamp-2 text-xs text-muted">{{ $r->body }}</p>
                                    <div class="mt-2 flex gap-3 text-xs">
                                        <button wire:click="editReply({{ $r->id }})" class="font-medium text-accent hover:underline">Edit</button>
                                        <button wire:click="deleteReply({{ $r->id }})" wire:confirm="Delete this saved reply?" class="text-muted hover:text-rose-500">Delete</button>
                                    </div>
                                </li>
                            @empty
                                <li class="rounded-xl border border-dashed border-line p-6 text-center text-sm text-muted">
                                    Nothing saved yet.
                                    <button wire:click="addStarterReplies" class="mt-2 block w-full font-medium text-accent hover:underline">Add 5 starter replies</button>
                                </li>
                            @endforelse
                        </ul>
                    </div>

                    <form wire:submit="saveReply" class="space-y-3">
                        <p class="text-sm font-semibold">{{ $editingReplyId ? 'Edit reply' : 'New reply' }}</p>
                        <div>
                            <input wire:model="replyTitle" class="input text-sm" placeholder="Title, e.g. Fitment check" maxlength="80">
                            @error('replyTitle') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <textarea wire:model="replyBody" rows="7" class="input text-sm" maxlength="2000" placeholder="Hi {buyer_first_name}, …"></textarea>
                            @error('replyBody') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                        </div>
                        <div class="rounded-lg bg-surface-2 p-2 text-[11px] text-muted">
                            @foreach (\App\Models\SellerSavedReply::PLACEHOLDERS as $token => $label)
                                <button type="button" class="mr-1 mb-1 rounded bg-surface px-1.5 font-mono hover:text-accent" title="{{ $label }}"
                                        @click="$wire.replyBody = ($wire.replyBody || '') + '{{ $token }}'">{{ $token }}</button>
                            @endforeach
                        </div>
                        <div class="flex gap-2">
                            <button class="btn-accent btn-sm">{{ $editingReplyId ? 'Update' : 'Save reply' }}</button>
                            @if ($editingReplyId)
                                <button type="button" wire:click="editReply" class="btn-ghost btn-sm">New instead</button>
                            @endif
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
