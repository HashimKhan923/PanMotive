<div class="max-w-6xl">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Social studio</h1>
            <p class="mt-1 text-sm text-muted">Make branded post images, write a caption for each app, plan your week, and see the real clicks and sales every post brings.</p>
        </div>
        <button type="button" wire:click="planWeek" wire:loading.attr="disabled" wire:target="planWeek" class="btn-brand btn-sm">
            <x-ui.icon name="sparkles" class="h-4 w-4" />
            <span wire:loading.remove wire:target="planWeek">Plan my week with AI</span>
            <span wire:loading wire:target="planWeek">Planning…</span>
        </button>
    </div>

    <div class="mt-6 flex gap-1 overflow-x-auto border-b border-line text-sm">
        @foreach (['compose' => 'Compose', 'calendar' => 'Calendar', 'posts' => 'Posts & results', 'bio' => 'Link in bio', 'accounts' => 'Auto-posting'] as $key => $label)
            <button wire:click="$set('tab', '{{ $key }}')"
                    class="-mb-px shrink-0 border-b-2 px-3 py-2 font-medium transition {{ $tab === $key ? 'border-accent text-fg' : 'border-transparent text-muted hover:text-fg' }}">{{ $label }}</button>
        @endforeach
    </div>

    {{-- ========== COMPOSE ========== --}}
    @if ($tab === 'compose')
        <div class="mt-6 grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <form wire:submit="publish" class="card h-max space-y-4 p-6" data-reveal>
                <div>
                    <label class="label">Channels</label>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($platforms as $key => $cfg)
                            <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-line px-3 py-1.5 text-sm has-[:checked]:border-accent has-[:checked]:bg-accent/5">
                                <input type="checkbox" value="{{ $key }}" wire:model.live="channels" class="rounded border-line text-accent focus:ring-accent">
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
                        <select id="ss-prod" wire:model.live="productId" class="input">
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
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <label class="label mb-0" for="ss-body">Caption</label>
                        <div class="flex gap-2">
                            <button type="button" wire:click="generate" wire:loading.attr="disabled" wire:target="generate,generateAll" class="btn-ghost btn-sm">
                                <x-ui.icon name="sparkles" class="w-4 h-4" /> <span wire:loading.remove wire:target="generate">One caption</span><span wire:loading wire:target="generate">Writing…</span>
                            </button>
                            <button type="button" wire:click="generateAll" wire:loading.attr="disabled" wire:target="generate,generateAll" class="btn-brand btn-sm">
                                <x-ui.icon name="sparkles" class="w-4 h-4" /> <span wire:loading.remove wire:target="generateAll">A caption for each app</span><span wire:loading wire:target="generateAll">Writing…</span>
                            </button>
                        </div>
                    </div>
                    <textarea id="ss-body" wire:model.live.debounce.500ms="body" rows="5" class="input mt-1" placeholder="What do you want to say?"></textarea>
                    <p class="mt-1 text-xs text-muted">{{ Str::length($body) }} characters · used for every app unless you give one its own caption below.</p>
                    @error('body') <p class="text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>

                {{-- hashtag ideas --}}
                <div x-data>
                    <p class="text-xs font-medium text-muted">Hashtag ideas (click to add)</p>
                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                        @foreach ($hashtags as $tag)
                            <button type="button" @click="$wire.set('body', ($wire.body.trim() + ' {{ $tag }}').trim())" class="rounded-full bg-surface-2 px-2.5 py-0.5 text-xs hover:bg-accent/10 hover:text-accent">{{ $tag }}</button>
                        @endforeach
                    </div>
                </div>

                {{-- a caption per app --}}
                @if ($captions)
                    <div class="space-y-3 rounded-xl bg-surface-2 p-3">
                        <p class="text-xs font-semibold">Caption for each app</p>
                        @foreach ($channels as $c)
                            @continue(! array_key_exists($c, $captions))
                            @php($limit = $platforms[$c]['char_limit'] ?? 5000)
                            <div wire:key="cap-{{ $c }}">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-medium">{{ $platforms[$c]['label'] }}</span>
                                    <span class="{{ Str::length($captions[$c]) > $limit ? 'font-semibold text-rose-600' : 'text-muted' }}">{{ Str::length($captions[$c]) }}/{{ $limit }}
                                        <button type="button" wire:click="clearCaption('{{ $c }}')" class="ml-2 text-muted hover:text-rose-600">Use main caption</button></span>
                                </div>
                                <textarea wire:model.live.debounce.600ms="captions.{{ $c }}" rows="3" class="input mt-1 text-sm"></textarea>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div>
                    <label class="label" for="ss-media">Image</label>
                    @if ($media && method_exists($media, 'temporaryUrl'))
                        <div class="mb-2 flex items-center gap-3">
                            <img src="{{ $media->temporaryUrl() }}" alt="" class="h-20 w-20 rounded-lg object-cover ring-1 ring-line">
                            <button type="button" wire:click="$set('media', null)" class="text-xs text-muted hover:text-rose-600">Remove</button>
                        </div>
                    @endif
                    <input id="ss-media" type="file" wire:model="media" accept="image/*" class="text-sm">
                    <p class="mt-1 text-xs text-muted">Upload one, or make a branded image with the image maker →</p>
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
                <p class="text-xs text-muted">⏰ Best time to post: <strong>{{ \Illuminate\Support\Carbon::createFromTime($bestHour['hour'])->format('g A') }}</strong>. {{ $bestHour['reason'] }}</p>
            </form>

            <div class="space-y-5">
                {{-- image maker --}}
                <div wire:ignore class="card p-5" data-reveal
                     x-data="pmPostImage({ products: @js($designProducts), reviews: @js($reviews), store: @js($storeName), logo: @js($logo), color: @js($brandColor), productId: @js($productId) })">
                    <div class="flex items-center justify-between">
                        <p class="font-semibold">Image maker</p>
                        <span class="text-xs text-muted" x-text="sizes[size].label"></span>
                    </div>
                    <div class="mt-3 grid grid-cols-4 gap-1.5">
                        <template x-for="(d, key) in designs" :key="key">
                            <button type="button" @click="design = key; applyDesign(); draw()" :class="design === key ? 'ring-2 ring-accent bg-accent/5' : 'bg-surface-2 hover:bg-surface'" class="rounded-lg px-1.5 py-2 text-[11px] font-medium leading-tight" x-text="d.label"></button>
                        </template>
                    </div>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                        <select x-show="design !== 'review'" x-model.number="productId" @change="applyDesign(); load()" class="input h-9 text-sm">
                            <option value="">Choose a product…</option>
                            <template x-for="p in products" :key="p.id"><option :value="p.id" x-text="p.title" :selected="p.id === productId"></option></template>
                        </select>
                        <select x-show="design === 'review'" x-model.number="reviewId" @change="applyDesign(); draw()" class="input h-9 text-sm">
                            <option value="">Choose a 4–5 star review…</option>
                            <template x-for="r in reviews" :key="r.id"><option :value="r.id" x-text="'★'.repeat(r.rating) + ' ' + r.name + ': ' + r.text.slice(0, 40)"></option></template>
                        </select>
                        <div class="flex gap-1">
                            <template x-for="(s, key) in sizes" :key="key">
                                <button type="button" @click="size = key; draw()" :class="size === key ? 'bg-accent text-white' : 'bg-surface-2'" class="flex-1 rounded-md px-1 py-1.5 text-[11px]" x-text="s.short"></button>
                            </template>
                        </div>
                        <input x-model="headline" @input.debounce.200ms="draw()" class="input h-9 text-sm" placeholder="Big text">
                        <input x-model="badge" @input.debounce.200ms="draw()" class="input h-9 text-sm" placeholder="Badge, e.g. SALE −20%">
                        <input x-model="sub" @input.debounce.200ms="draw()" class="input h-9 text-sm sm:col-span-2" placeholder="Small text, e.g. price or 'Fits 2018 Toyota Camry'">
                        <div class="flex items-center gap-2 text-xs sm:col-span-2">
                            <span class="text-muted">Text</span>
                            <template x-for="t in ['top','bottom']" :key="t"><button type="button" @click="textPos = t; draw()" :class="textPos === t ? 'bg-accent text-white' : 'bg-surface-2'" class="rounded-md px-2 py-1 capitalize" x-text="t"></button></template>
                            <span class="ml-2 text-muted">Colour</span>
                            <input type="color" x-model="color" @input="draw()" class="h-7 w-9 cursor-pointer rounded border border-line bg-transparent">
                        </div>
                    </div>
                    <div class="mt-3 grid place-items-center rounded-xl bg-surface-2 p-3">
                        <canvas x-ref="canvas" class="max-h-[420px] w-auto max-w-full rounded-lg shadow"></canvas>
                    </div>
                    <p x-show="error" x-text="error" class="mt-2 text-xs text-rose-600"></p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" @click="use()" :disabled="busy" class="btn-accent btn-sm"><x-ui.icon name="check" class="h-4 w-4" /> <span x-text="busy ? 'Adding…' : 'Use for this post'"></span></button>
                        <button type="button" @click="download()" class="btn-outline btn-sm"><x-ui.icon name="download" class="h-4 w-4" /> Download</button>
                        <button type="button" @click="downloadAll()" class="btn-ghost btn-sm">All 4 sizes</button>
                    </div>
                </div>

                {{-- how it looks on each app --}}
                <div class="card p-5" data-reveal>
                    <p class="font-semibold">How it looks</p>
                    @php($img = $media && method_exists($media, 'temporaryUrl') ? $media->temporaryUrl() : null)
                    <div class="mt-3 space-y-4">
                        @forelse (array_slice($channels, 0, 4) as $c)
                            @php($text = trim((string) ($captions[$c] ?? $body)))
                            @php($limit = $platforms[$c]['char_limit'] ?? 5000)
                            @php($tags = preg_match_all('/#\w+/', $text))
                            <div wire:key="pv-{{ $c }}" class="overflow-hidden rounded-xl border border-line bg-white text-[#15191E]">
                                <div class="flex items-center gap-2 border-b border-[#E6E9ED] px-3 py-2">
                                    <span class="grid h-7 w-7 place-items-center rounded-full text-xs font-bold text-white" style="background: {{ $brandColor }}">{{ mb_strtoupper(mb_substr($storeName, 0, 1)) }}</span>
                                    <span class="text-sm font-semibold">{{ $storeName }}</span>
                                    <span class="ml-auto text-[11px] text-[#8A919C]">{{ $platforms[$c]['label'] }}</span>
                                </div>
                                @if ($c === 'x')
                                    <p class="whitespace-pre-line px-3 py-2 text-sm">{{ Str::limit($text, 280) }}</p>
                                    @if ($img)<img src="{{ $img }}" alt="" class="mx-3 mb-3 aspect-video w-[calc(100%-1.5rem)] rounded-xl object-cover">@endif
                                @else
                                    @if ($img)<img src="{{ $img }}" alt="" class="{{ in_array($c, ['instagram', 'tiktok'], true) ? 'aspect-square' : ($c === 'pinterest' ? 'aspect-[2/3]' : 'aspect-video') }} w-full object-cover">@elseif (($platforms[$c]['needs_media'] ?? false))<div class="grid aspect-square place-items-center bg-[#F5F6F8] text-xs text-[#BB1220]">{{ $platforms[$c]['label'] }} needs an image</div>@endif
                                    <p class="whitespace-pre-line px-3 py-2 text-sm">@if (in_array($c, ['instagram', 'tiktok'], true))<strong>{{ Str::slug($storeName, '') }}</strong> @endif{{ in_array($c, ['instagram', 'tiktok'], true) && Str::length($text) > 125 ? Str::limit($text, 125, '') : $text }}@if (in_array($c, ['instagram', 'tiktok'], true) && Str::length($text) > 125)<span class="text-[#8A919C]">… more</span>@endif</p>
                                @endif
                                <div class="flex flex-wrap gap-x-3 border-t border-[#E6E9ED] px-3 py-1.5 text-[11px]">
                                    <span class="{{ Str::length($text) > $limit ? 'font-semibold text-[#BB1220]' : 'text-[#5E6672]' }}">{{ Str::length($text) }}/{{ $limit }} characters</span>
                                    @if ($c === 'instagram' && $tags > 30)<span class="font-semibold text-[#BB1220]">{{ $tags }} hashtags (max 30)</span>@endif
                                    @if ($c === 'x' && $tags > 3)<span class="text-[#B45309]">tip: 1–2 hashtags work best on X</span>@endif
                                    @if (in_array($c, ['instagram', 'tiktok'], true) && Str::length($text) > 125)<span class="text-[#5E6672]">first 125 characters show before “more”</span>@endif
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-muted">Pick a channel to see a preview.</p>
                        @endforelse
                        @if (count($channels) > 4)<p class="text-xs text-muted">+{{ count($channels) - 4 }} more apps</p>@endif
                    </div>
                </div>
            </div>
        </div>

    {{-- ========== CALENDAR ========== --}}
    @elseif ($tab === 'calendar')
        @php($start = $calMonth->copy()->startOfMonth()->startOfWeek(\Illuminate\Support\Carbon::MONDAY))
        @php($end = $calMonth->copy()->endOfMonth()->endOfWeek(\Illuminate\Support\Carbon::SUNDAY))
        <div class="mt-6 grid gap-5 lg:grid-cols-[minmax(0,1fr)_280px]">
            <div class="card p-4" x-data="{ drag: null }">
                <div class="flex items-center justify-between">
                    <button wire:click="$set('month', '{{ $calMonth->copy()->subMonth()->format('Y-m') }}')" class="btn-ghost btn-sm">←</button>
                    <p class="font-semibold">{{ $calMonth->format('F Y') }}</p>
                    <button wire:click="$set('month', '{{ $calMonth->copy()->addMonth()->format('Y-m') }}')" class="btn-ghost btn-sm">→</button>
                </div>
                <div class="mt-3 grid grid-cols-7 gap-1 text-center text-[11px] font-semibold uppercase text-muted">
                    @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)<div>{{ $d }}</div>@endforeach
                </div>
                <div class="mt-1 grid grid-cols-7 gap-1">
                    @for ($day = $start->copy(); $day <= $end; $day->addDay())
                        @php($key = $day->format('Y-m-d'))
                        <div wire:key="day-{{ $key }}" @dragover.prevent @drop.prevent="if (drag) $wire.reschedule(drag, '{{ $key }}'); drag = null"
                             class="min-h-24 rounded-lg border p-1 text-left {{ $day->isSameMonth($calMonth) ? 'border-line bg-surface' : 'border-transparent bg-surface-2/50 opacity-60' }} {{ $day->isToday() ? 'ring-1 ring-accent' : '' }}">
                            <p class="text-[11px] {{ $day->isToday() ? 'font-bold text-accent' : 'text-muted' }}">{{ $day->day }}</p>
                            @foreach (($calPosts[$key] ?? collect())->take(3) as $p)
                                <div draggable="{{ $p->status === 'scheduled' ? 'true' : 'false' }}" @dragstart="drag = {{ $p->id }}" wire:key="cp-{{ $p->id }}"
                                     title="{{ Str::limit($p->body, 120) }}"
                                     class="mt-0.5 truncate rounded px-1 py-0.5 text-[10px] {{ $p->status === 'scheduled' ? 'cursor-grab bg-accent/15 text-accent' : 'bg-emerald-500/10 text-emerald-700' }}">
                                    {{ ($p->scheduled_at ?? $p->created_at)->format('g:ia') }} {{ $p->product?->title ?? Str::limit($p->body, 20) }}
                                </div>
                            @endforeach
                            @if (($calPosts[$key] ?? collect())->count() > 3)<p class="text-[10px] text-muted">+{{ $calPosts[$key]->count() - 3 }}</p>@endif
                        </div>
                    @endfor
                </div>
                <p class="mt-3 text-xs text-muted">Drag a scheduled post (red) to another day to move it. Green ones are already out.</p>
            </div>

            <form wire:submit="saveQueue" class="card h-max space-y-3 p-4">
                <p class="font-semibold">Auto-queue</p>
                <p class="text-xs text-muted">Post one of your in-stock products every day, with an AI caption and its photo. Share kits are ready at the time you choose.</p>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="queue.enabled" class="rounded border-line text-accent"> Turn on the auto-queue</label>
                <div>
                    <label class="label" for="q-hour">Time</label>
                    <select id="q-hour" wire:model="queue.hour" class="input h-9 text-sm">
                        @for ($h = 6; $h <= 22; $h++)<option value="{{ $h }}">{{ \Illuminate\Support\Carbon::createFromTime($h)->format('g A') }}{{ $h === $bestHour['hour'] ? ' (your best time)' : '' }}</option>@endfor
                    </select>
                </div>
                <div>
                    <p class="label">Days</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach ([1 => 'M', 2 => 'T', 3 => 'W', 4 => 'T', 5 => 'F', 6 => 'S', 7 => 'S'] as $n => $d)
                            <label class="grid h-8 w-8 cursor-pointer place-items-center rounded-full border border-line text-xs has-[:checked]:border-accent has-[:checked]:bg-accent has-[:checked]:text-white">
                                <input type="checkbox" value="{{ $n }}" wire:model="queue.days" class="hidden">{{ $d }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <p class="label">Apps</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($platforms as $key => $cfg)
                            <label class="inline-flex cursor-pointer items-center gap-1 rounded-md border border-line px-2 py-1 text-xs has-[:checked]:border-accent has-[:checked]:bg-accent/5">
                                <input type="checkbox" value="{{ $key }}" wire:model="queue.channels" class="h-3 w-3 rounded border-line text-accent">{{ $cfg['label'] }}
                            </label>
                        @endforeach
                    </div>
                    @error('queue.channels') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <button class="btn-accent btn-sm w-full">Save auto-queue</button>
            </form>
        </div>

    {{-- ========== POSTS & RESULTS ========== --}}
    @elseif ($tab === 'posts')
        <div class="mt-6 grid grid-cols-3 gap-3">
            <div class="card p-4"><p class="text-xs text-muted">Clicks from social</p><p class="mt-1 text-2xl font-bold">{{ number_format($totals['clicks']) }}</p></div>
            <div class="card p-4"><p class="text-xs text-muted">Orders</p><p class="mt-1 text-2xl font-bold">{{ number_format($totals['orders']) }}</p></div>
            <div class="card p-4"><p class="text-xs text-muted">Sales</p><p class="mt-1 text-2xl font-bold">${{ number_format($totals['revenue'], 2) }}</p></div>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <div class="card overflow-hidden">
                <p class="border-b border-line px-4 py-3 font-semibold">Which apps bring sales</p>
                <table class="w-full text-sm">
                    <thead class="bg-surface-2 text-left text-xs text-muted"><tr><th class="px-4 py-2">App</th><th class="px-4 py-2">Posts</th><th class="px-4 py-2">Clicks</th><th class="px-4 py-2">Orders</th><th class="px-4 py-2 text-right">Sales</th></tr></thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($byPlatform as $platform => $r)
                            <tr><td class="px-4 py-2 font-medium">{{ $platforms[$platform]['label'] ?? $platform }}</td><td class="px-4 py-2">{{ $r['posts'] }}</td><td class="px-4 py-2">{{ number_format($r['clicks']) }}</td><td class="px-4 py-2">{{ $r['orders'] }}</td><td class="px-4 py-2 text-right font-semibold">${{ number_format($r['revenue'], 2) }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-muted">No posts yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card p-4">
                <p class="font-semibold">Your best posts</p>
                @forelse ($best as $i => $r)
                    <div class="mt-3 flex items-center gap-3" wire:key="best-{{ $r['post']->id }}">
                        <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-surface-2 text-xs font-bold">{{ $i + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm">{{ $r['post']->product?->title ?? Str::limit($r['post']->body, 50) }}</p>
                            <p class="text-xs text-muted">{{ $r['clicks'] }} clicks · ${{ number_format($r['revenue'], 2) }}</p>
                        </div>
                        <button wire:click="postAgain({{ $r['post']->id }})" class="btn-ghost btn-sm text-xs">Post again</button>
                    </div>
                @empty
                    <p class="mt-2 text-sm text-muted">Your top posts show here once people click them.</p>
                @endforelse
            </div>
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
                            <button wire:click="postAgain({{ $post->id }})" class="text-accent hover:underline">Post again</button>
                            @if (in_array($post->status, ['failed', 'partial'], true) && $post->targets->contains('status', 'failed'))
                                <button wire:click="retry({{ $post->id }})" class="text-accent hover:underline">Retry</button>
                            @endif
                            <button wire:click="deletePost({{ $post->id }})" wire:confirm="Delete this post and its links?" class="text-muted hover:text-rose-500">Delete</button>
                        </div>
                    </div>

                    <ul class="mt-4 space-y-2">
                        @foreach ($post->targets as $t)
                            @php($caption = Str::limit(trim($t->caption ?: $post->body), $platforms[$t->platform]['char_limit'] ?? 2000, ''))
                            @php($link = $t->link())
                            @php($share = $link ? \App\Services\Marketing\Social\PlatformRegistry::shareUrl($t->platform, $link, $caption, $image) : null)
                            <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-line p-3" wire:key="t-{{ $t->id }}"
                                x-data="{ copied: false, copy() { navigator.clipboard.writeText(@js($caption.($link ? "\n".$link : ''))); this.copied = true; setTimeout(() => this.copied = false, 1500) } }">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium">{{ $platforms[$t->platform]['label'] ?? $t->platform }}
                                        <span class="ml-1 text-xs font-normal {{ match ($t->status) { 'published', 'shared' => 'text-emerald-600', 'failed' => 'text-rose-500', default => 'text-muted' } }}">
                                            {{ match ($t->status) { 'share_kit' => 'ready to share', 'shared' => 'shared by you', 'published' => 'auto-posted', default => $t->status } }}
                                        </span>
                                        @if ($t->caption)<span class="ml-1 rounded bg-surface-2 px-1 text-[10px] text-muted">own caption</span>@endif
                                    </p>
                                    <p class="text-xs text-muted">
                                        {{ number_format((int) $t->promoLink?->clicks) }} clicks · {{ (int) $t->promoLink?->orders }} orders · ${{ number_format((float) $t->promoLink?->revenue, 2) }}
                                        @if ($t->permalink) · <a href="{{ $t->permalink }}" target="_blank" rel="noopener" class="text-accent hover:underline">View post ↗</a>@endif
                                        @if ($t->error) · <span class="text-rose-500">{{ $t->error }}</span>@endif
                                    </p>
                                </div>
                                @if (in_array($t->status, ['share_kit', 'shared'], true))
                                    <div class="flex flex-wrap gap-1.5">
                                        <button type="button" @click="copy()" class="btn-ghost btn-sm text-xs"><x-ui.icon name="copy" class="w-4 h-4" /> <span x-text="copied ? 'Copied!' : 'Copy caption + link'"></span></button>
                                        @if ($image)<a href="{{ $image }}" download class="btn-ghost btn-sm text-xs"><x-ui.icon name="download" class="w-4 h-4" /> Image</a>@endif
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
                    <x-ui.icon name="chart" class="w-12 h-12 text-muted" />
                    <p class="mt-3 font-semibold">No posts yet</p>
                    <button wire:click="$set('tab', 'compose')" class="btn-accent btn-sm mt-4">Create your first post</button>
                </div>
            @endforelse
        </div>
        <p class="mt-4 text-xs text-muted">Clicks and sales are counted through each post's tracked link. Likes and reach live on the platform itself.</p>

    {{-- ========== LINK IN BIO ========== --}}
    @elseif ($tab === 'bio')
        <div class="mt-6 grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
            <form wire:submit="saveBio" class="card h-max space-y-4 p-6">
                <div>
                    <p class="font-semibold">Your link-in-bio page</p>
                    <p class="text-sm text-muted">One link for your Instagram and TikTok bio. It shows your featured parts, deals and a coupon, and every tap is tracked.</p>
                </div>
                @if ($bioUrl)
                    <div class="flex flex-wrap items-center gap-2 rounded-xl bg-surface-2 p-3" x-data="{ copied: false }">
                        <code class="min-w-0 flex-1 truncate text-sm">{{ $bioUrl }}</code>
                        <button type="button" @click="navigator.clipboard.writeText(@js($bioUrl)); copied = true; setTimeout(() => copied = false, 1500)" class="btn-outline btn-sm" x-text="copied ? 'Copied!' : 'Copy link'"></button>
                        <a href="{{ $bioUrl }}" target="_blank" rel="noopener" class="btn-ghost btn-sm">Open ↗</a>
                    </div>
                @endif
                <div class="grid grid-cols-4 gap-2 text-center">
                    <div class="rounded-xl bg-surface-2 p-2"><p class="text-lg font-bold">{{ number_format($bioStats['views']) }}</p><p class="text-[11px] text-muted">visits</p></div>
                    <div class="rounded-xl bg-surface-2 p-2"><p class="text-lg font-bold">{{ number_format($bioStats['clicks']) }}</p><p class="text-[11px] text-muted">taps</p></div>
                    <div class="rounded-xl bg-surface-2 p-2"><p class="text-lg font-bold">{{ $bioStats['orders'] }}</p><p class="text-[11px] text-muted">orders</p></div>
                    <div class="rounded-xl bg-surface-2 p-2"><p class="text-lg font-bold">${{ number_format($bioStats['revenue'], 0) }}</p><p class="text-[11px] text-muted">sales</p></div>
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="bio.enabled" class="rounded border-line text-accent"> Page is live</label>
                <div>
                    <label class="label" for="bio-head">Headline</label>
                    <input id="bio-head" wire:model="bio.headline" maxlength="80" class="input" placeholder="Shop our parts">
                </div>
                <div>
                    <p class="label">Featured products <span class="text-muted">(up to 12; none picked = your newest)</span></p>
                    <div class="max-h-64 space-y-1 overflow-y-auto rounded-xl border border-line p-2">
                        @foreach ($products as $p)
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg p-1.5 text-sm hover:bg-surface-2" wire:key="bp-{{ $p->id }}">
                                <input type="checkbox" value="{{ $p->id }}" wire:model="bio.product_ids" class="rounded border-line text-accent">
                                <img src="{{ $p->getFirstMediaUrl('images', 'thumb') }}" alt="" class="h-8 w-8 rounded object-cover bg-surface-2">
                                <span class="truncate">{{ $p->title }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('bio.product_ids') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="label" for="bio-coupon">Coupon to show</label>
                        <select id="bio-coupon" wire:model="bio.coupon_id" class="input">
                            <option value="">None</option>
                            @foreach ($coupons as $c)<option value="{{ $c->id }}">{{ $c->code }}</option>@endforeach
                        </select>
                    </div>
                    <label class="mt-6 flex items-center gap-2 text-sm"><input type="checkbox" wire:model="bio.show_deals" class="rounded border-line text-accent"> Show items on sale</label>
                </div>
                <button class="btn-accent">Save page</button>
            </form>
            @if ($bioUrl)
                <div class="card h-max overflow-hidden p-3">
                    <p class="px-1 pb-2 text-xs font-semibold text-muted">Live preview</p>
                    <iframe src="{{ $bioUrl }}" class="h-[640px] w-full rounded-2xl ring-1 ring-line" title="Link in bio preview" wire:key="bio-frame-{{ md5(json_encode($bio)) }}"></iframe>
                </div>
            @endif
        </div>

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
        <p class="mt-4 text-xs text-muted">Auto-posting turns on per platform once Panmotive's app for it is approved. Until then, share kits get your post out in two taps — with the same tracked links and sales reporting.</p>
    @endif
</div>
