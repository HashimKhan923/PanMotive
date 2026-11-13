<div class="pb-16">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <a href="{{ route('seller.campaigns.index') }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-accent"><x-ui.icon name="arrow-left" class="h-4 w-4" /> Campaigns</a>
            <h1 class="mt-1 truncate text-2xl font-bold">{{ $campaign->name }}</h1>
            <p class="text-sm text-muted">“{{ $campaign->subject }}”
                @if ($campaign->sent_at) · sent {{ $campaign->sent_at->format('M j, Y g:i A') }} @endif
                @if ($campaign->status === 'capped') · <span class="font-medium text-amber-600">{{ number_format($waiting) }} still to send (daily email limit, continues automatically)</span> @endif
            </p>
        </div>
        <a href="{{ route('seller.campaigns.export', $campaign) }}" class="btn-outline btn-sm"><x-ui.icon name="download" class="h-4 w-4" /> Export CSV</a>
    </div>

    {{-- headline numbers --}}
    <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-reveal-group>
        @foreach ([
            ['Delivered', number_format($delivered), 'of '.number_format($sent).' sent'.($bounced ? ' · '.$bounced.' bounced' : '')],
            ['Opened (estimated)', $openRate.'%', number_format($opened).' people · some phones open emails by themselves'],
            ['Clicked', $clickRate.'%', number_format($clicked).' people · '.number_format($clicks).' clicks · '.$clickToOpen.'% of openers'],
            ['Orders', number_format($orders), '$'.number_format($revenue, 2).' in sales · '.$orderRate.'% of people'],
        ] as [$label, $big, $small])
            <div class="card p-4">
                <p class="text-xs uppercase tracking-wide text-muted">{{ $label }}</p>
                <p class="mt-1 text-2xl font-bold">{{ $big }}</p>
                <p class="mt-0.5 text-xs text-muted">{{ $small }}</p>
            </div>
        @endforeach
    </div>
    <div class="mt-3 flex flex-wrap gap-2 text-xs">
        <span class="pill">{{ $unsubscribed }} unsubscribed</span>
        <span class="pill {{ $complaints ? '!bg-rose-500/10 !text-rose-600' : '' }}">{{ $complaints }} spam complaints</span>
        @if ($failed)<span class="pill !bg-amber-500/10 !text-amber-700">{{ $failed }} couldn’t be sent</span>@endif
    </div>

    <div class="mt-6 grid gap-4 xl:grid-cols-5">
        <div class="space-y-4 xl:col-span-2">
            {{-- first 48 hours --}}
            <div class="card p-5">
                <p class="font-semibold">First 48 hours</p>
                <div class="mt-4 flex h-32 items-end gap-[3px]" role="img" aria-label="Opens and clicks per hour">
                    @foreach ($hours as $h => $v)
                        <div class="flex flex-1 flex-col justify-end gap-px" title="Hour {{ $h + 1 }}: {{ $v['opens'] }} opens, {{ $v['clicks'] }} clicks">
                            <div class="rounded-t-sm bg-fg/25" style="height: {{ round($v['opens'] / $peak * 100) }}%"></div>
                            <div class="rounded-t-sm bg-accent" style="height: {{ round($v['clicks'] / $peak * 100) }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-2 flex justify-between text-[11px] text-muted"><span>Sent</span><span>24h</span><span>48h</span></div>
                <div class="mt-2 flex gap-4 text-xs"><span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-fg/25"></span> Opens</span><span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-accent"></span> Clicks</span></div>
            </div>

            {{-- what got clicked --}}
            <div class="card p-5">
                <p class="font-semibold">Top products clicked</p>
                @forelse ($topProducts as $tp)
                    <div class="mt-2 flex justify-between gap-3 text-sm"><span class="truncate">{{ $tp['title'] }}</span><span class="font-semibold">{{ $tp['clicks'] }}</span></div>
                @empty
                    <p class="mt-1 text-sm text-muted">No product clicks yet.</p>
                @endforelse
                <p class="mt-5 font-semibold">Links</p>
                @forelse ($links as $l)
                    <div class="mt-2 flex justify-between gap-3 text-xs">
                        <span class="truncate text-muted" title="{{ $l->url }}">{{ preg_match('/:p(\d+)$/', $l->link_key, $m) ? ($productTitles[(int) $m[1]] ?? 'Product') : \Illuminate\Support\Str::limit(preg_replace('~^https?://~', '', $l->url), 60) }}</span>
                        <span class="shrink-0 font-semibold">{{ $l->n }}</span>
                    </div>
                @empty
                    <p class="mt-1 text-sm text-muted">No clicks yet.</p>
                @endforelse
                <p class="mt-4 text-[11px] text-muted">Clicks from email security scanners (they open every link automatically) are left out.</p>
            </div>
        </div>

        {{-- click map --}}
        <div class="card p-4 xl:col-span-3">
            <p class="font-semibold">Click map</p>
            <p class="text-xs text-muted">Your email as sent, with the number of clicks on each part.</p>
            <iframe src="{{ route('seller.campaigns.preview', ['campaign' => $campaign, 'report' => 1]) }}" class="mt-3 h-[900px] w-full rounded-lg bg-[#F5F6F8] ring-1 ring-line" title="Click map" loading="lazy"></iframe>
        </div>
    </div>

    {{-- people --}}
    <div class="card mt-6 overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line p-4">
            <p class="font-semibold">People</p>
            <div class="flex flex-wrap gap-1 text-xs">
                @foreach (['all' => 'Everyone', 'opened' => 'Opened', 'clicked' => 'Clicked', 'ordered' => 'Ordered', 'not_opened' => 'Didn’t open', 'problems' => 'Problems'] as $key => $label)
                    <button type="button" wire:click="$set('who', '{{ $key }}')" class="rounded-full px-3 py-1 {{ $who === $key ? 'bg-accent text-white' : 'bg-surface-2 hover:text-accent' }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-surface-2 text-left text-xs uppercase tracking-wide text-muted">
                    <tr><th class="px-4 py-2">Customer</th><th class="px-4 py-2">Sent</th><th class="px-4 py-2">Opened</th><th class="px-4 py-2">Clicks</th><th class="px-4 py-2 text-right">Ordered</th></tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($people as $r)
                        <tr wire:key="r-{{ $r->id }}">
                            <td class="px-4 py-2"><span class="font-medium">{{ $r->user?->name ?? 'Removed account' }}</span></td>
                            <td class="px-4 py-2 text-muted">
                                @if ($r->bounced_at) <span class="text-rose-600">Bounced</span>
                                @elseif ($r->sent_at) {{ $r->sent_at->format('M j, g:i A') }}
                                @elseif ($r->failure) <span class="text-amber-700" title="{{ $r->failure }}">Not sent</span>
                                @else Waiting @endif
                            </td>
                            <td class="px-4 py-2 text-muted">{{ $r->opened_at?->format('M j, g:i A') ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $r->human_clicks ?: '—' }}</td>
                            <td class="px-4 py-2 text-right font-medium">{{ $r->converted_order_id ? '$'.number_format((float) $r->conversion_value, 2) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-muted">Nobody here yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $people->links() }}</div>
    </div>
</div>
