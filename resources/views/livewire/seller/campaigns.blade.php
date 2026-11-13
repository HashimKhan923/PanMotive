<div class="max-w-4xl">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Campaigns</h1>
            <p class="mt-1 text-sm text-muted">Email{{ $smsAvailable ? ' &amp; SMS' : '' }} to your own customers, followers and cart-abandoners — sent from Panmotive, with an unsubscribe link in every email.</p>
            <p class="mt-1 text-xs {{ $sentThisWeek >= $weeklyLimit ? 'text-amber-600' : 'text-muted' }}">{{ $sentThisWeek }} of {{ $weeklyLimit }} campaigns sent this week.</p>
        </div>
        <button wire:click="newCampaign" class="btn-accent btn-sm"><x-ui.icon name="plus" class="w-4 h-4" /> New campaign</button>
    </div>

    @if ($showForm)
        <form wire:submit.prevent="save(false)" class="card mt-6 space-y-4 p-6" data-reveal>
            <h2 class="font-bold">{{ $editingId ? 'Edit campaign' : 'New campaign' }}</h2>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="label" for="cp-name">Name</label>
                    <input id="cp-name" wire:model="name" class="input" placeholder="March restock">
                    @error('name') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="cp-ch">Channel</label>
                    <select id="cp-ch" wire:model.live="channel" class="input">
                        <option value="email">Email</option>
                        @if ($smsAvailable)<option value="sms">SMS (opted-in buyers)</option>@endif
                    </select>
                </div>
                <div>
                    <label class="label" for="cp-aud">Audience</label>
                    <select id="cp-aud" wire:model.live="audienceType" class="input">
                        @foreach ($audiences as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
            </div>
            <p class="text-xs text-muted">Reaches <span class="font-semibold text-fg">{{ number_format($this->audienceCount) }}</span> {{ Str::plural('person', $this->audienceCount) }}{{ $channel === 'sms' ? ' who opted in to texts' : '' }} — buyers who unsubscribed or that you blocked are left out automatically.</p>

            @if ($channel === 'email')
                @php($editing = $editingId ? \App\Models\MarketingCampaign::find($editingId) : null)
                <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-dashed border-line bg-surface-2 p-4">
                    <div class="flex items-center gap-3">
                        <span class="grid h-11 w-11 place-items-center rounded-xl bg-accent/10 text-accent"><x-ui.icon name="sparkles" class="h-5 w-5" /></span>
                        <div>
                            <p class="font-semibold">{{ $editing?->design ? 'Your email design' : 'Design your email' }}</p>
                            <p class="text-xs text-muted">{{ $editing?->design ? count($editing->design).' blocks · drag and drop products, coupons, images and buttons' : 'Pick a template, then drag in products, coupons, images and buttons.' }}</p>
                        </div>
                    </div>
                    <button type="button" wire:click="design" class="btn-brand btn-sm"><x-ui.icon name="cog" class="h-4 w-4" /> {{ $editing?->design ? 'Edit design' : 'Open the designer' }}</button>
                </div>
            @endif

            @if ($channel === 'sms')
            <div class="flex flex-wrap items-end gap-3 rounded-xl bg-surface-2 p-3">
                <div>
                    <label class="label" for="cp-goal">AI goal</label>
                    <select id="cp-goal" wire:model="goal" class="input h-9 text-sm">
                        <option value="new_arrivals">New arrivals</option>
                        <option value="sale">Run a sale</option>
                        <option value="win_back">Win back lapsed buyers</option>
                        <option value="restock">Back in stock</option>
                    </select>
                </div>
                <div>
                    <label class="label" for="cp-feat">Feature a product</label>
                    <select id="cp-feat" wire:model="featureProductId" class="input h-9 text-sm">
                        <option value="">None</option>
                        @foreach ($products as $p)<option value="{{ $p->id }}">{{ $p->title }}</option>@endforeach
                    </select>
                </div>
                <button type="button" wire:click="generate" wire:loading.attr="disabled" wire:target="generate" class="btn-brand btn-sm">
                    <x-ui.icon name="sparkles" class="w-4 h-4" />
                    <span wire:loading.remove wire:target="generate">Write it for me</span>
                    <span wire:loading wire:target="generate">Writing…</span>
                </button>
            </div>
            @endif

            @if ($channel === 'email')
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="cp-subj">Subject</label>
                        <input id="cp-subj" wire:model="subject" class="input" maxlength="120">
                        @error('subject') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="cp-prev">Preview text</label>
                        <input id="cp-prev" wire:model="preview" class="input">
                    </div>
                </div>
            @endif

            @if ($channel === 'sms')
            <div>
                <label class="label" for="cp-body">Message</label>
                <textarea id="cp-body" wire:model="body" rows="7" class="input"></textarea>
                <p class="mt-1 text-xs text-muted">Placeholders: <code>@verbatim{{name}}{{store}}{{shop_url}}@endverbatim</code> — first name, store name, tracked link</p>
                @error('body') <p class="text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>
            @endif

            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="label" for="cp-when">Send</label>
                    <select id="cp-when" wire:model.live="when" class="input">
                        <option value="now">Now</option>
                        <option value="later">Schedule</option>
                    </select>
                </div>
                @if ($when === 'later')
                    <div>
                        <label class="label" for="cp-at">When</label>
                        <input id="cp-at" type="datetime-local" wire:model="scheduledAt" class="input">
                        @error('scheduledAt') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    </div>
                @endif
                @if ($channel === 'sms')<button type="button" wire:click="testSend" class="btn-ghost btn-sm">Send test to me</button>@endif
                <button type="button" wire:click="save(false)" class="btn-outline btn-sm">Save draft</button>
                <button type="button" wire:click="save(true)" class="btn-accent btn-sm">{{ $when === 'later' ? 'Schedule' : 'Send now' }}</button>
            </div>
        </form>
    @endif

    <div class="mt-6 space-y-3" data-reveal-group>
        @forelse ($campaigns as $c)
            <div class="card p-5" wire:key="cmp-{{ $c->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">{{ $c->name }} <span class="pill ml-1 uppercase">{{ $c->channel }}</span></p>
                        <p class="text-xs text-muted">{{ $audiences[$c->audience['type'] ?? ''] ?? '' }} · <span class="{{ $c->status === 'failed' ? 'text-rose-500' : ($c->status === 'capped' ? 'text-amber-600' : '') }}">{{ $c->status === 'capped' ? 'Sending (daily email limit — continues automatically)' : ucfirst($c->status) }}</span>
                            @if ($c->sent_at) · {{ $c->status === 'sending' ? 'started' : 'sent' }} {{ $c->sent_at->diffForHumans() }}
                            @elseif ($c->scheduled_at) · {{ $c->scheduled_at->format('M j, g:i A') }} @endif
                        </p>
                        @if (! empty($c->stats['error']))<p class="mt-1 text-xs text-rose-500">{{ $c->stats['error'] }}</p>@endif
                    </div>
                    <div class="flex gap-3 text-sm">
                        @if (in_array($c->status, ['draft', 'scheduled', 'failed'], true))
                            <button wire:click="edit({{ $c->id }})" class="font-medium text-accent hover:underline">Edit</button>
                            @if ($c->channel === 'email')<a href="{{ route('seller.campaigns.design', $c) }}" class="font-medium text-accent hover:underline">Design</a>@endif
                            @if ($c->status !== 'scheduled')<button wire:click="sendDraft({{ $c->id }})" class="font-medium text-accent hover:underline">Send</button>@endif
                            <button wire:click="delete({{ $c->id }})" wire:confirm="Delete this campaign?" class="text-muted hover:text-rose-500">Delete</button>
                        @else
                            @if ($c->channel === 'email')<a href="{{ route('seller.campaigns.report', $c) }}" class="font-medium text-accent hover:underline">Report</a>@endif
                            <button wire:click="duplicate({{ $c->id }})" class="font-medium text-accent hover:underline">Duplicate</button>
                        @endif
                    </div>
                </div>
                @if (in_array($c->status, ['sent', 'capped'], true))
                    <div class="mt-3 grid grid-cols-3 gap-3 border-t border-line pt-3 text-center text-sm sm:grid-cols-6">
                        <div><p class="text-xs text-muted">Sent</p><p class="font-bold">{{ number_format($c->stats['recipients'] ?? 0) }}</p></div>
                        <div><p class="text-xs text-muted">Opens</p><p class="font-bold">{{ $c->rate('opened') }}%</p></div>
                        <div><p class="text-xs text-muted">Clicks</p><p class="font-bold">{{ $c->rate('clicked') }}%</p></div>
                        <div><p class="text-xs text-muted">Orders</p><p class="font-bold">{{ $c->stats['converted'] ?? 0 }}</p></div>
                        <div><p class="text-xs text-muted">Revenue</p><p class="font-bold">${{ number_format($c->stats['revenue'] ?? 0, 0) }}</p></div>
                        <div><p class="text-xs text-muted">Unsubscribed</p><p class="font-bold">{{ $c->stats['unsubscribed'] ?? 0 }}</p></div>
                    </div>
                @endif
            </div>
        @empty
            <div class="card grid place-items-center p-16 text-center">
                <x-ui.icon name="mail" class="w-12 h-12 text-muted" />
                <p class="mt-3 font-semibold">No campaigns yet</p>
                <p class="mt-1 text-sm text-muted">Bring back cart-abandoners or tell followers about a restock.</p>
            </div>
        @endforelse
    </div>
</div>
