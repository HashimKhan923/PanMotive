<div class="max-w-4xl">
    <h1 class="text-2xl font-bold">Social studio</h1>
    <p class="mt-1 text-sm text-muted">Write once, share everywhere. Every post gets its own tracked link, so you see the real clicks and sales it brings.</p>

    <div class="mt-6 flex gap-1 border-b border-line text-sm">
        @foreach (['compose' => 'Compose', 'posts' => 'Posts', 'accounts' => 'Auto-posting'] as $key => $label)
            <button wire:click="$set('tab', '{{ $key }}')"
                    class="-mb-px border-b-2 px-3 py-2 font-medium transition {{ $tab === $key ? 'border-accent text-accent' : 'border-transparent text-muted hover:text-fg' }}">{{ $label }}</button>
        @endforeach
    </div>

    {{-- ========== COMPOSE ========== --}}
    @if ($tab === 'compose')
        <form wire:submit="publish" class="card mt-6 space-y-4 p-6" data-reveal>
            <div>
                <label class="label">Channels</label>
                <div class="flex flex-wrap gap-2">
                    @foreach ($platforms as $key => $cfg)
                        <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-line px-3 py-1.5 text-sm has-[:checked]:border-accent has-[:checked]:bg-accent/5">
                            <input type="checkbox" value="{{ $key }}" wire:model="channels" class="rounded border-line text-accent focus:ring-accent">
                            {{ $cfg['label'] }}
                            @if (($accounts[$key] ?? null)?->isLive() && $autoPost[$key])<span class="text-[10px] font-semibold text-emerald-600">AUTO</span>@endif
                        </label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-muted">Channels marked AUTO post by themselves. The rest get a share kit — caption, image and a one-tap share button.</p>
                @error('channels') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="label" for="ss-prod">Attach a listing (optional)</label>
                    <select id="ss-prod" wire:model="productId" class="input">
                        <option value="">My store page</option>
                        @foreach ($products as $p)<option value="{{ $p->id }}">{{ $p->title }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="ss-tone">AI tone</label>
                    <select id="ss-tone" wire:model="tone" class="input">
                        <option value="friendly">Friendly</option>
                        <option value="expert">Expert</option>
                        <option value="hype">High energy</option>
                        <option value="deal">Deal-focused</option>
                    </select>
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between">
                    <label class="label mb-0" for="ss-body">Caption</label>
                    <button type="button" wire:click="generate" wire:loading.attr="disabled" wire:target="generate" class="btn-brand btn-sm">
                        <x-ui.icon name="sparkles" class="w-4 h-4" />
                        <span wire:loading.remove wire:target="generate">Generate with AI</span>
                        <span wire:loading wire:target="generate">Writing…</span>
                    </button>
                </div>
                <textarea id="ss-body" wire:model.live.debounce.500ms="body" rows="5" class="input mt-1" placeholder="What do you want to say?"></textarea>
                <p class="mt-1 text-xs text-muted">
                    {{ Str::length($body) }} characters
                    @foreach ($channels as $c)
                        @if (Str::length($body) > ($platforms[$c]['char_limit'] ?? 99999)) · <span class="text-amber-600">too long for {{ $platforms[$c]['label'] }} ({{ $platforms[$c]['char_limit'] }})</span>@endif
                    @endforeach
                </p>
                @error('body') <p class="text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="ss-media">Image</label>
                <input id="ss-media" type="file" wire:model="media" accept="image/*" class="text-sm">
                <p class="mt-1 text-xs text-muted">Instagram, Pinterest and TikTok need an image.</p>
                <div wire:loading wire:target="media" class="mt-1 text-xs text-muted">Uploading…</div>
                @error('media') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="label" for="ss-when">When</label>
                    <select id="ss-when" wire:model.live="when" class="input">
                        <option value="now">Now</option>
                        <option value="later">Schedule</option>
                    </select>
                </div>
                @if ($when === 'later')
                    <div>
                        <label class="label" for="ss-at">Date &amp; time</label>
                        <input id="ss-at" type="datetime-local" wire:model="scheduledAt" class="input">
                        @error('scheduledAt') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    </div>
                @endif
                <button type="submit" class="btn-accent">{{ $when === 'later' ? 'Schedule' : 'Create post' }}</button>
            </div>
        </form>

    {{-- ========== POSTS ========== --}}
    @elseif ($tab === 'posts')
        <div class="mt-6 grid grid-cols-3 gap-3">
            <div class="card p-4"><p class="text-xs text-muted">Clicks from social</p><p class="mt-1 text-2xl font-bold">{{ number_format($totals['clicks']) }}</p></div>
            <div class="card p-4"><p class="text-xs text-muted">Orders</p><p class="mt-1 text-2xl font-bold">{{ number_format($totals['orders']) }}</p></div>
            <div class="card p-4"><p class="text-xs text-muted">Sales</p><p class="mt-1 text-2xl font-bold">${{ number_format($totals['revenue'], 2) }}</p></div>
        </div>

        <div class="mt-4 space-y-3">
            @forelse ($posts as $post)
                @php($image = $post->media_path ? asset('storage/'.$post->media_path) : null)
                <div class="card p-5" wire:key="sp-{{ $post->id }}" data-reveal>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex min-w-0 gap-3">
                            @if ($image)<img src="{{ $image }}" alt="" class="h-16 w-16 shrink-0 rounded-lg object-cover">@endif
                            <div class="min-w-0">
                                <p class="line-clamp-2 text-sm">{{ $post->body }}</p>
                                <p class="mt-1 text-xs text-muted">
                                    @if ($post->status === 'scheduled') Scheduled {{ $post->scheduled_at?->format('M j, g:i A') }}
                                    @else Created {{ $post->created_at->format('M j, g:i A') }} @endif
                                    @if ($post->product) · {{ $post->product->title }} @endif
                                </p>
                            </div>
                        </div>
                        <div class="flex shrink-0 gap-3 text-xs">
                            @if (in_array($post->status, ['failed', 'partial'], true) && $post->targets->contains('status', 'failed'))
                                <button wire:click="retry({{ $post->id }})" class="text-accent hover:underline">Retry</button>
                            @endif
                            <button wire:click="deletePost({{ $post->id }})" wire:confirm="Delete this post and its links?" class="text-muted hover:text-rose-500">Delete</button>
                        </div>
                    </div>

                    <ul class="mt-4 space-y-2">
                        @foreach ($post->targets as $t)
                            @php($caption = Str::limit(trim($post->body), $platforms[$t->platform]['char_limit'] ?? 2000, ''))
                            @php($link = $t->link())
                            @php($share = $link ? \App\Services\Marketing\Social\PlatformRegistry::shareUrl($t->platform, $link, $caption, $image) : null)
                            <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-line p-3" wire:key="t-{{ $t->id }}"
                                x-data="{ copied: false, copy() { navigator.clipboard.writeText(@js($caption.($link ? "\n".$link : ''))); this.copied = true; setTimeout(() => this.copied = false, 1500) } }">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium">{{ $platforms[$t->platform]['label'] ?? $t->platform }}
                                        <span class="ml-1 text-xs font-normal {{ match ($t->status) { 'published', 'shared' => 'text-emerald-600', 'failed' => 'text-rose-500', default => 'text-muted' } }}">
                                            {{ match ($t->status) { 'share_kit' => 'ready to share', 'shared' => 'shared by you', 'published' => 'auto-posted', default => $t->status } }}
                                        </span>
                                    </p>
                                    <p class="text-xs text-muted">
                                        {{ number_format((int) $t->promoLink?->clicks) }} clicks · {{ (int) $t->promoLink?->orders }} orders · ${{ number_format((float) $t->promoLink?->revenue, 2) }}
                                        @if ($t->permalink) · <a href="{{ $t->permalink }}" target="_blank" rel="noopener" class="text-accent hover:underline">View post ↗</a>@endif
                                        @if ($t->error) · <span class="text-rose-500">{{ $t->error }}</span>@endif
                                    </p>
                                </div>
                                @if (in_array($t->status, ['share_kit', 'shared'], true))
                                    <div class="flex flex-wrap gap-1.5">
                                        <button type="button" @click="copy()" class="btn-ghost btn-sm text-xs"><x-ui.icon name="copy" class="w-3.5 h-3.5" /> <span x-text="copied ? 'Copied!' : 'Copy caption + link'"></span></button>
                                        @if ($image)<a href="{{ $image }}" download class="btn-ghost btn-sm text-xs"><x-ui.icon name="download" class="w-3.5 h-3.5" /> Image</a>@endif
                                        @if ($share)<a href="{{ $share }}" target="_blank" rel="noopener" class="btn-accent btn-sm text-xs">Share ↗</a>@endif
                                        @if ($t->status === 'share_kit')
                                            <button wire:click="markShared({{ $t->id }})" class="btn-ghost btn-sm text-xs">I posted it</button>
                                        @endif
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <div class="card grid place-items-center p-16 text-center">
                    <x-ui.icon name="chart" class="w-10 h-10 text-muted" />
                    <p class="mt-3 font-semibold">No posts yet</p>
                    <button wire:click="$set('tab', 'compose')" class="btn-accent btn-sm mt-4">Create your first post</button>
                </div>
            @endforelse
        </div>
        <p class="mt-4 text-xs text-muted">Clicks and sales are counted through each post's tracked link. Likes and reach live on the platform itself.</p>

    {{-- ========== AUTO-POSTING ========== --}}
    @elseif ($tab === 'accounts')
        <div class="mt-6 grid gap-3 sm:grid-cols-2">
            @foreach ($platforms as $key => $cfg)
                @php($acc = $accounts[$key] ?? null)
                <div class="card flex items-center justify-between p-4">
                    <div>
                        <p class="font-semibold">{{ $cfg['label'] }}</p>
                        <p class="text-xs text-muted">
                            @if ($acc?->isLive() && $autoPost[$key]) {{ $acc->handle }} · auto-posting on
                            @elseif ($autoPost[$key]) Connect to post automatically
                            @else Share kit · auto-posting not available yet @endif
                        </p>
                    </div>
                    @if ($acc)
                        <form method="POST" action="{{ route('seller.social.disconnect', $acc) }}">
                            @csrf @method('DELETE')
                            <button class="btn-ghost btn-sm text-muted hover:text-rose-500">Disconnect</button>
                        </form>
                    @elseif ($autoPost[$key])
                        <a href="{{ route('seller.social.connect', $key) }}" class="btn-outline btn-sm">Connect</a>
                    @endif
                </div>
            @endforeach
        </div>
        <p class="mt-4 text-xs text-muted">Auto-posting turns on per platform once Pan Motive's app for it is approved. Until then, share kits get your post out in two taps — with the same tracked links and sales reporting.</p>
    @endif
</div>
