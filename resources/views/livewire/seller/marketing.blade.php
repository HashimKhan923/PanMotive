<div class="max-w-4xl">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Marketing</h1>
            <p class="mt-1 text-sm text-muted">Promote your listings, build an audience and track what works — all on PanMotive.</p>
        </div>
        <a href="{{ route('seller.ad-credit.index') }}" class="rounded-xl border border-line px-4 py-2 text-right transition hover:border-accent">
            <p class="text-xs text-muted">Ad credit</p>
            <p class="text-lg font-bold">${{ number_format($profile->ad_credit, 2) }}</p>
            <p class="text-xs font-medium text-accent">Add funds →</p>
        </a>
    </div>

    <div class="mt-6 flex gap-1 border-b border-line text-sm">
        @foreach (['boost' => 'Boost listings', 'announce' => 'Announcements', 'links' => 'Tracked links', 'refer' => 'Refer a seller'] as $key => $label)
            <button wire:click="$set('tab', '{{ $key }}')"
                    class="-mb-px border-b-2 px-3 py-2 font-medium transition {{ $tab === $key ? 'border-accent text-accent' : 'border-transparent text-muted hover:text-fg' }}">{{ $label }}</button>
        @endforeach
    </div>

    {{-- ================= BOOST ================= --}}
    @if ($tab === 'boost')
        <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_300px]" data-reveal>
            <form wire:submit="startBoost" class="card space-y-4 p-6">
                <h2 class="font-bold">New boost</h2>
                <div>
                    <label class="label" for="b-prod">Listing</label>
                    <select id="b-prod" wire:model="boostProductId" class="input">
                        <option value="">Choose a listing…</option>
                        @foreach ($products as $p)
                            <option value="{{ $p->id }}">{{ $p->title }}</option>
                        @endforeach
                    </select>
                    @error('boostProductId') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="b-place">Placement</label>
                        <select id="b-place" wire:model.live="boostPlacement" class="input">
                            <option value="search">Search results — ${{ number_format($rates['search'], 2) }}/day</option>
                            <option value="category">Category pages — ${{ number_format($rates['category'], 2) }}/day</option>
                            <option value="home">Homepage feature — ${{ number_format($rates['home'], 2) }}/day</option>
                        </select>
                    </div>
                    <div>
                        <label class="label" for="b-days">Duration (days)</label>
                        <input id="b-days" type="number" min="1" max="30" wire:model.live="boostDays" class="input">
                    </div>
                </div>
                <div class="flex items-center justify-between rounded-xl bg-surface-2 px-4 py-3">
                    <span class="text-sm text-muted">Total cost</span>
                    <span class="text-xl font-bold">${{ number_format($this->quote, 2) }}</span>
                </div>
                @error('boost') <p class="text-xs text-rose-500">{{ $message }}</p> @enderror
                <button class="btn-accent w-full" @disabled($profile->ad_credit < $this->quote)>
                    <x-ui.icon name="sparkles" class="w-4 h-4" /> Start boost
                </button>
            </form>

            <div class="space-y-4">
                <div class="card p-5">
                    <p class="text-sm font-semibold">How boosts work</p>
                    <ul class="mt-2 space-y-1.5 text-xs text-muted">
                        <li>• Boosted listings appear first with a "Sponsored" tag.</li>
                        <li>• Flat daily fee from ad credit — no bidding.</li>
                        <li>• Cancel anytime; unused full days are refunded.</li>
                        <li>• Impressions, clicks and attributed sales are tracked below.</li>
                    </ul>
                    <a href="{{ route('seller.ad-credit.index') }}" class="btn-outline btn-sm mt-3 w-full">Add ad credit</a>
                </div>
            </div>
        </div>

        <h2 class="mt-8 font-bold">Your campaigns</h2>
        <div class="mt-3 overflow-x-auto rounded-xl border border-line">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-surface-2 text-left text-xs uppercase text-muted">
                    <tr><th class="px-4 py-3">Listing</th><th class="px-4 py-3">Placement</th><th class="px-4 py-3">Spend</th><th class="px-4 py-3">Impr.</th><th class="px-4 py-3">Clicks</th><th class="px-4 py-3">CTR</th><th class="px-4 py-3">Sales</th><th class="px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($boosts as $b)
                        <tr class="bg-surface" wire:key="boost-{{ $b->id }}">
                            <td class="px-4 py-3">{{ $b->product->title }}</td>
                            <td class="px-4 py-3 capitalize">{{ $b->placement }}</td>
                            <td class="px-4 py-3">${{ number_format($b->fee, 2) }}</td>
                            <td class="px-4 py-3 text-muted">{{ number_format($b->impressions) }}</td>
                            <td class="px-4 py-3 text-muted">{{ number_format($b->clicks) }}</td>
                            <td class="px-4 py-3 text-muted">{{ $b->ctr() }}%</td>
                            <td class="px-4 py-3">{{ $b->attributed_orders }} · ${{ number_format($b->attributed_revenue, 0) }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $b->status === 'active' ? 'bg-emerald-500/10 text-emerald-600' : 'bg-surface-2 text-muted' }}">{{ $b->status }}</span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if ($b->status === 'active')
                                    <button wire:click="cancelBoost({{ $b->id }})" wire:confirm="Cancel this boost?" class="text-xs text-muted hover:text-rose-500">Cancel</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-10 text-center text-sm text-muted">No campaigns yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    {{-- ================= ANNOUNCE ================= --}}
    @elseif ($tab === 'announce')
        <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_260px]" data-reveal>
            <form wire:submit="publishAnnouncement" class="card space-y-4 p-6">
                <h2 class="font-bold">Store announcement</h2>
                <p class="text-sm text-muted">Shows as a banner on your store page and notifies everyone following your store.</p>
                <div>
                    <textarea wire:model="announcement" rows="3" maxlength="180" class="input" placeholder="Fresh stock of OEM brake kits just landed — 15% off this week with code…"></textarea>
                    @error('announcement') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div class="w-40">
                    <label class="label" for="a-days">Show for (days)</label>
                    <input id="a-days" type="number" min="1" max="30" wire:model="announcementDays" class="input">
                </div>
                <div class="flex gap-2">
                    <button class="btn-accent btn-sm">Publish</button>
                    @if ($profile->announcement)
                        <button type="button" wire:click="clearAnnouncement" class="btn-ghost btn-sm">Clear current</button>
                    @endif
                </div>
            </form>
            <div class="card p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted">Audience</p>
                <p class="mt-1 text-3xl font-bold" data-counter-to="{{ $followerCount }}">{{ $followerCount }}</p>
                <p class="text-xs text-muted">followers</p>
                @if ($profile->activeAnnouncement())
                    <div class="mt-4 rounded-lg bg-surface-2 p-3 text-xs">
                        <p class="font-semibold text-accent">Live now</p>
                        <p class="mt-1">{{ $profile->announcement }}</p>
                    </div>
                @endif
            </div>
        </div>

    {{-- ================= LINKS ================= --}}
    @elseif ($tab === 'links')
        <form wire:submit="createLink" class="card mt-6 flex flex-wrap items-end gap-3 p-6" data-reveal>
            <div class="min-w-[200px] flex-1">
                <label class="label" for="l-prod">Destination</label>
                <select id="l-prod" wire:model="linkProductId" class="input">
                    <option value="">My whole store</option>
                    @foreach ($products as $p)
                        <option value="{{ $p->id }}">{{ $p->title }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[160px] flex-1">
                <label class="label" for="l-label">Label (optional)</label>
                <input id="l-label" wire:model="linkLabel" class="input" placeholder="Instagram bio">
            </div>
            <button class="btn-accent btn-sm">Create link</button>
        </form>

        <div class="mt-4 space-y-2" data-reveal-group>
            @forelse ($links as $link)
                <div class="card flex flex-wrap items-center justify-between gap-3 p-4" wire:key="link-{{ $link->id }}">
                    <div class="min-w-0">
                        <p class="font-mono text-sm font-semibold" x-data="{ copied: false }">
                            {{ url('/go/'.$link->code) }}
                            <button @click="navigator.clipboard.writeText('{{ url('/go/'.$link->code) }}'); copied = true; setTimeout(() => copied = false, 1500)"
                                    class="ml-2 text-xs font-sans text-accent hover:underline" x-text="copied ? 'Copied!' : 'Copy'"></button>
                        </p>
                        <p class="text-xs text-muted">{{ $link->label ?: ($link->product?->title ?? 'Store') }}</p>
                    </div>
                    <div class="flex items-center gap-5 text-sm">
                        <span class="text-muted">{{ number_format($link->clicks) }} clicks</span>
                        <span class="text-muted">{{ $link->orders }} orders</span>
                        <span class="font-semibold">${{ number_format($link->revenue, 0) }}</span>
                        <button wire:click="deleteLink({{ $link->id }})" class="text-muted hover:text-rose-500"><x-ui.icon name="trash" class="w-4 h-4" /></button>
                    </div>
                </div>
            @empty
                <div class="card grid place-items-center p-16 text-center">
                    <x-ui.icon name="external" class="w-10 h-10 text-muted" />
                    <p class="mt-3 font-semibold">No links yet</p>
                    <p class="mt-1 text-sm text-muted">Create a link to share on social — we count the clicks and sales it drives.</p>
                </div>
            @endforelse
        </div>

    {{-- ================= REFER ================= --}}
    @elseif ($tab === 'refer')
        <div class="card mt-6 p-6" data-reveal>
            <h2 class="font-bold">Bring another seller — you both save</h2>
            <p class="mt-1 text-sm text-muted">
                When someone you refer is approved, you <span class="font-semibold text-fg">both</span> get
                <span class="font-semibold text-fg">{{ rtrim(rtrim(number_format($referralLink->commission_discount_pct, 2), '0'), '.') }}% off commission for {{ $referralLink->reward_months }} months</span>.
            </p>

            <div class="mt-4 flex flex-wrap items-center gap-2" x-data="{ copied: false }">
                <code class="rounded-lg bg-surface-2 px-3 py-2 text-sm">{{ url('/sell/onboarding?sref='.$referralLink->code) }}</code>
                <button @click="navigator.clipboard.writeText('{{ url('/sell/onboarding?sref='.$referralLink->code) }}'); copied = true; setTimeout(() => copied = false, 1500)"
                        class="btn-outline btn-sm" x-text="copied ? 'Copied!' : 'Copy link'"></button>
            </div>

            @if ($profile->hasReferralBonus())
                <p class="mt-4 inline-flex items-center gap-2 rounded-lg bg-emerald-500/10 px-3 py-2 text-sm text-emerald-600">
                    <x-ui.icon name="check" class="w-4 h-4" />
                    Your referral bonus is active — {{ rtrim(rtrim(number_format($profile->referral_bonus_pct, 2), '0'), '.') }}% off until {{ $profile->referral_bonus_until->format('M j, Y') }}.
                </p>
            @endif
        </div>

        <div class="mt-4 space-y-2" data-reveal-group>
            @forelse ($referrals as $r)
                <div class="card flex items-center justify-between gap-3 p-4" wire:key="ref-{{ $r->id }}">
                    <div>
                        <p class="font-medium">{{ $r->referred->name }}</p>
                        <p class="text-xs text-muted">Joined {{ $r->created_at->format('M j, Y') }}</p>
                    </div>
                    <span class="pill capitalize">{{ $r->status }}{{ $r->ends_at ? ' · ends '.$r->ends_at->format('M j') : '' }}</span>
                </div>
            @empty
                <p class="card p-8 text-center text-sm text-muted">No referrals yet — share your link.</p>
            @endforelse
        </div>
    @endif
</div>
