<div class="container-x py-10" wire:poll.15s="refreshThread">
    <h1 class="text-2xl font-bold sm:text-3xl">Messages</h1>
    <p class="mt-1 text-sm text-muted">Your conversations with sellers.</p>

    <div class="mt-6 grid gap-4 lg:grid-cols-[320px_1fr]">
        <div class="card overflow-hidden">
            <ul class="max-h-[70vh] divide-y divide-line overflow-y-auto">
                @forelse ($conversations as $c)
                    <li wire:key="bc-{{ $c->id }}">
                        <button wire:click="open({{ $c->id }})"
                                class="w-full px-4 py-3 text-left transition hover:bg-surface-2 {{ $active?->id === $c->id ? 'bg-surface-2' : '' }}">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate text-sm font-semibold">{{ $c->seller->sellerProfile?->business_name ?? $c->seller->name }}</span>
                                @if ($c->buyer_unread > 0)
                                    <span class="shrink-0 rounded-full bg-accent px-1.5 text-[10px] font-bold text-accent-fg">{{ $c->buyer_unread }}</span>
                                @endif
                            </div>
                            <p class="truncate text-xs text-muted">{{ $c->product?->title ?? $c->subject }}</p>
                            <p class="mt-0.5 truncate text-xs text-muted">{{ $c->latestMessage->first()?->body }}</p>
                        </button>
                    </li>
                @empty
                    <li class="px-4 py-16 text-center text-sm text-muted">No messages yet.</li>
                @endforelse
            </ul>
        </div>

        <div class="card flex flex-col overflow-hidden" style="min-height: 60vh;">
            @if (! $active)
                <div class="grid flex-1 place-items-center p-10 text-center text-sm text-muted">
                    <div><x-ui.icon name="mail" class="mx-auto w-10 h-10" /><p class="mt-3">Select a conversation.</p></div>
                </div>
            @else
                <div class="border-b border-line px-5 py-3">
                    <p class="font-semibold">{{ $active->seller->sellerProfile?->business_name ?? $active->seller->name }}</p>
                    <p class="truncate text-xs text-muted">
                        @if ($active->product)
                            <a href="{{ route('products.show', $active->product->slug) }}" class="hover:text-accent">{{ $active->product->title }}</a>
                        @else {{ $active->subject }} @endif
                        @if ($active->order) · <a href="{{ route('orders.show', $active->order_id) }}" class="hover:text-accent">Order {{ $active->order->order_number }}</a> @endif
                    </p>
                </div>
                <div class="flex-1 space-y-3 overflow-y-auto p-5" x-data x-init="$el.scrollTop = $el.scrollHeight">
                    @forelse ($active->messages as $m)
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
                                    @if ($mine && $lastMine?->id === $m->id) · {{ $m->read_at ? 'Seen' : 'Delivered' }} @endif
                                </p>
                            </div>
                        </div>
                    @empty
                        <p class="py-10 text-center text-sm text-muted">Ask about your order — tracking, fitment, returns. Photos help sellers answer faster.</p>
                    @endforelse
                </div>
                @if ($blocked)
                    <p class="border-t border-line p-4 text-center text-xs text-muted">This seller isn't accepting messages from your account.</p>
                @else
                    <form wire:submit="reply" class="border-t border-line p-3">
                        @if ($photos)
                            <div class="mb-2 flex flex-wrap gap-2">
                                @foreach ($photos as $i => $photo)
                                    <div class="relative" wire:key="ph-{{ $i }}">
                                        @if (method_exists($photo, 'isPreviewable') && $photo->isPreviewable())
                                            <img src="{{ $photo->temporaryUrl() }}" class="h-16 w-16 rounded-lg object-cover" alt="">
                                        @endif
                                        <button type="button" wire:click="removePhoto({{ $i }})" class="absolute -right-1.5 -top-1.5 grid h-5 w-5 place-items-center rounded-full bg-fg text-bg" aria-label="Remove photo"><x-ui.icon name="x" class="w-3 h-3" /></button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        <div class="flex gap-2">
                            <label class="btn-ghost btn-sm cursor-pointer self-end" title="Attach photos">
                                <x-ui.icon name="image" class="w-4 h-4" /><span class="sr-only">Attach photos</span>
                                <input type="file" wire:model="photos" multiple accept="image/*" class="hidden">
                            </label>
                            <textarea wire:model="body" rows="2" maxlength="4000" class="input flex-1 text-sm" placeholder="Write a message…"></textarea>
                            <button class="btn-accent btn-sm self-end" wire:loading.attr="disabled" wire:target="reply,photos">Send</button>
                        </div>
                        <span wire:loading wire:target="photos" class="mt-1 block text-xs text-muted">Uploading…</span>
                        @error('body') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                        @error('photos') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                        @error('photos.*') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                        <p class="mt-1 text-[10px] text-muted">For your protection, keep payments and conversations on PanMotive.</p>
                    </form>
                @endif
            @endif
        </div>
    </div>
</div>
