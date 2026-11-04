@extends('layouts.admin')

@section('title', 'Homepage & announcement')

@section('content')
<x-admin.header title="Homepage & announcement" subtitle="The words shoppers see first, and a message across the top of every shop page." />

@php
    $tones = ['accent' => ['Brand red', 'bg-accent text-accent-fg'], 'info' => ['Black', 'bg-brand text-brand-fg'], 'warning' => ['Yellow warning', 'bg-amber-500 text-black'], 'danger' => ['Urgent', 'bg-rose-600 text-white']];
    $tone = old('announcement_tone', config('marketplace.platform.announcement_tone'));
@endphp

<div class="grid gap-4 lg:grid-cols-2" x-data="{ text: @js(old('announcement', config('marketplace.platform.announcement'))), tone: @js($tone), tones: @js(collect($tones)->map(fn ($t) => $t[1])) }">
    <x-admin.card title="Announcement bar" subtitle="Leave the message empty to turn it off.">
        <form method="POST" action="{{ route('admin.content.update') }}" class="space-y-4">
            @csrf
            <div><label class="label" for="a-text">Message</label><input id="a-text" name="announcement" x-model="text" maxlength="200" class="input text-sm" placeholder="Free shipping on all brake parts this weekend"></div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label" for="a-tone">Colour</label>
                    <select id="a-tone" name="announcement_tone" x-model="tone" class="input text-sm">
                        @foreach ($tones as $key => $t)<option value="{{ $key }}">{{ $t[0] }}</option>@endforeach
                    </select></div>
                <div><label class="label" for="a-until">Hide after <span class="font-normal text-muted">(optional)</span></label>
                    <input id="a-until" type="datetime-local" name="announcement_until" value="{{ old('announcement_until', config('marketplace.platform.announcement_until')) }}" class="input text-sm"></div>
            </div>
            <div><label class="label" for="a-link">Link <span class="font-normal text-muted">(optional)</span></label>
                <input id="a-link" name="announcement_link" value="{{ old('announcement_link', config('marketplace.platform.announcement_link')) }}" maxlength="255" class="input text-sm" placeholder="/deals"></div>
            <div><label class="label" for="a-mail">Support email shown to customers</label>
                <input id="a-mail" type="email" name="support_email" value="{{ old('support_email', config('marketplace.platform.support_email')) }}" class="input text-sm"></div>
            <button class="btn-accent btn-sm">Save and publish</button>
        </form>
    </x-admin.card>

    <x-admin.card title="Preview" subtitle="How it looks on the site">
        <div class="overflow-hidden rounded-xl border border-line">
            <div class="px-4 py-2 text-center text-xs font-semibold sm:text-sm" :class="tones[tone]" x-show="text" x-text="text"></div>
            <div class="flex h-12 items-center gap-2 border-b border-line bg-surface px-4 text-sm font-extrabold"><x-ui.logo class="h-6 w-auto" /></div>
            <div class="grid h-36 place-items-center bg-bg text-xs text-muted">Your shop page</div>
        </div>
        <p x-show="!text" class="mt-3 text-xs text-muted">No message: the bar is off.</p>
    </x-admin.card>
</div>
<x-admin.card title="Homepage hero" subtitle="The big message at the top of your homepage." class="mt-4">
    <form method="POST" action="{{ route('admin.content.home') }}" class="grid gap-3 md:grid-cols-3">
        @csrf
        <div class="md:col-span-3"><label class="label" for="h-badge">Small badge</label><input id="h-badge" name="hero_badge" value="{{ old('hero_badge', config('marketplace.platform.hero_badge')) }}" maxlength="80" class="input text-sm"></div>
        <div><label class="label" for="h-1">Headline, line 1</label><input id="h-1" name="hero_line_1" value="{{ old('hero_line_1', config('marketplace.platform.hero_line_1')) }}" maxlength="40" class="input text-sm" required></div>
        <div><label class="label" for="h-2">Headline, line 2</label><input id="h-2" name="hero_line_2" value="{{ old('hero_line_2', config('marketplace.platform.hero_line_2')) }}" maxlength="40" class="input text-sm" required></div>
        <div><label class="label" for="h-3">Highlighted word</label><input id="h-3" name="hero_accent" value="{{ old('hero_accent', config('marketplace.platform.hero_accent')) }}" maxlength="30" class="input text-sm"></div>
        <div class="md:col-span-3"><label class="label" for="h-t">Text under the headline</label><textarea id="h-t" name="hero_text" rows="2" maxlength="300" class="input text-sm" required>{{ old('hero_text', config('marketplace.platform.hero_text')) }}</textarea></div>
        @foreach ([1, 2, 3] as $i)
            <div><label class="label" for="h-p{{ $i }}">Tick point {{ $i }}</label><input id="h-p{{ $i }}" name="hero_point_{{ $i }}" value="{{ old('hero_point_'.$i, config('marketplace.platform.hero_point_'.$i)) }}" maxlength="60" class="input text-sm"></div>
        @endforeach
        <div class="md:col-span-3"><button class="btn-accent btn-sm">Save homepage</button></div>
    </form>
</x-admin.card>
@endsection
