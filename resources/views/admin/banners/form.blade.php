@extends('layouts.admin')

@section('title', $banner->exists ? $banner->title : 'New banner')

@section('content')
<x-admin.header :title="$banner->exists ? 'Edit banner' : 'New banner'" :back="route('admin.banners.index')"
    subtitle="Short and clear works best: a headline of a few words, one line of detail, one button." />

@if ($errors->any())
    <div class="mb-4 rounded-xl bg-rose-500/10 px-4 py-3 text-sm text-rose-600">
        @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
    </div>
@endif

@php
    $local = fn ($date) => $date ? $date->format('Y-m-d\TH:i') : '';
@endphp

<form method="POST" enctype="multipart/form-data"
      action="{{ $banner->exists ? route('admin.banners.update', $banner) : route('admin.banners.store') }}"
      class="grid gap-4 xl:grid-cols-3">
    @csrf
    @if ($banner->exists) @method('PATCH') @endif

    <div class="space-y-4 xl:col-span-2">
        <x-admin.card title="Words">
            <div class="space-y-4">
                <div>
                    <label class="label" for="bn-eyebrow">Small label above the headline <span class="font-normal text-muted">(optional)</span></label>
                    <input id="bn-eyebrow" name="eyebrow" value="{{ old('eyebrow', $banner->eyebrow) }}" maxlength="40" class="input text-sm" placeholder="Winter sale">
                </div>
                <div>
                    <label class="label" for="bn-title">Headline</label>
                    <input id="bn-title" name="title" value="{{ old('title', $banner->title) }}" maxlength="120" class="input text-sm" required placeholder="Brake parts, up to 30% off">
                </div>
                <div>
                    <label class="label" for="bn-subtitle">One line of detail <span class="font-normal text-muted">(optional)</span></label>
                    <textarea id="bn-subtitle" name="subtitle" rows="2" maxlength="280" class="input text-sm" placeholder="Pads, rotors and calipers from independent sellers.">{{ old('subtitle', $banner->subtitle) }}</textarea>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="bn-btn">Main button text</label>
                        <input id="bn-btn" name="button_label" value="{{ old('button_label', $banner->button_label) }}" maxlength="30" class="input text-sm" placeholder="Shop brakes">
                    </div>
                    <div>
                        <label class="label" for="bn-btn-url">Main button link</label>
                        <input id="bn-btn-url" name="button_url" value="{{ old('button_url', $banner->button_url) }}" maxlength="500" class="input text-sm" placeholder="/shop?category=brakes">
                    </div>
                    <div>
                        <label class="label" for="bn-btn2">Second button text <span class="font-normal text-muted">(optional)</span></label>
                        <input id="bn-btn2" name="second_label" value="{{ old('second_label', $banner->second_label) }}" maxlength="30" class="input text-sm" placeholder="See all deals">
                    </div>
                    <div>
                        <label class="label" for="bn-btn2-url">Second button link</label>
                        <input id="bn-btn2-url" name="second_url" value="{{ old('second_url', $banner->second_url) }}" maxlength="500" class="input text-sm" placeholder="/deals">
                    </div>
                </div>
                <p class="text-xs text-muted">Links can be a page on this site, like <code>/deals</code>, or a full address starting with <code>https://</code>.</p>
            </div>
        </x-admin.card>

        <x-admin.card title="Look">
            <div class="space-y-5">
                <fieldset>
                    <legend class="label">Colour</legend>
                    <div class="grid gap-3 sm:grid-cols-3">
                        @foreach (\App\Models\Banner::STYLES as $value => $label)
                            <label class="group relative cursor-pointer">
                                <input type="radio" name="look" value="{{ $value }}" class="peer sr-only" @checked(old('look', $banner->style) === $value)>
                                <span class="block overflow-hidden rounded-xl ring-1 ring-line transition peer-checked:ring-2 peer-checked:ring-accent peer-focus-visible:ring-2 peer-focus-visible:ring-fg">
                                    <span class="relative block h-16 {{ ['charcoal' => 'bg-brand', 'red' => 'pm-banner--red', 'light' => 'bg-surface-2'][$value] }}">
                                        <span class="absolute left-3 top-3 block h-2 w-16 rounded-full {{ $value === 'light' ? 'bg-fg/70' : 'bg-white/90' }}"></span>
                                        <span class="absolute left-3 top-7 block h-1.5 w-10 rounded-full {{ $value === 'light' ? 'bg-fg/30' : 'bg-white/50' }}"></span>
                                        <span class="absolute bottom-3 left-3 block h-3 w-9 rounded {{ $value === 'red' ? 'bg-white' : 'bg-accent' }}"></span>
                                    </span>
                                    <span class="block bg-surface px-3 py-2 text-[13px] font-medium">{{ $label }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div>
                    <label class="label" for="bn-image">Photo <span class="font-normal text-muted">(optional)</span></label>
                    @if ($banner->imageUrl())
                        <div class="mb-3 flex items-center gap-3">
                            <img src="{{ $banner->imageUrl() }}" alt="Current banner photo" class="h-20 w-36 rounded-lg object-cover ring-1 ring-line">
                            <label class="flex items-center gap-2 text-[13px]"><input type="checkbox" name="remove_image" value="1" class="rounded text-accent focus:ring-accent"> Remove this photo</label>
                        </div>
                    @endif
                    <input id="bn-image" name="image" type="file" accept="image/jpeg,image/png,image/webp" class="block w-full text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-surface-2 file:px-3 file:py-2 file:text-sm file:font-medium file:text-fg hover:file:bg-line">
                    <p class="mt-1 text-xs text-muted">JPG, PNG or WebP, up to 5 MB, at least 600 × 300. A wide photo around 1600 × 900 looks best. It shows on the right with the brand's angled edge. Without a photo, the Panmotive mark shows instead.</p>
                </div>
            </div>
        </x-admin.card>
    </div>

    <div class="space-y-4">
        <x-admin.card title="Where and when">
            <div class="space-y-4">
                <div>
                    <label class="label" for="bn-place">Place on the homepage</label>
                    <select id="bn-place" name="placement" class="input text-sm">
                        @foreach (\App\Models\Banner::PLACEMENTS as $value => $label)
                            <option value="{{ $value }}" @selected(old('placement', $banner->placement) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="bn-order">Order in that place</label>
                    <input id="bn-order" name="sort_order" type="number" min="0" max="999" value="{{ old('sort_order', $banner->sort_order) }}" class="input text-sm">
                    <p class="mt-1 text-xs text-muted">Lower numbers play first.</p>
                </div>
                <div>
                    <label class="label" for="bn-start">Show from <span class="font-normal text-muted">(optional)</span></label>
                    <input id="bn-start" name="starts_at" type="datetime-local" value="{{ old('starts_at', $local($banner->starts_at)) }}" class="input text-sm">
                </div>
                <div>
                    <label class="label" for="bn-end">Hide after <span class="font-normal text-muted">(optional)</span></label>
                    <input id="bn-end" name="ends_at" type="datetime-local" value="{{ old('ends_at', $local($banner->ends_at)) }}" class="input text-sm">
                    <p class="mt-1 text-xs text-muted">Times are the site's time zone ({{ config('app.timezone') }}).</p>
                </div>
                <label class="flex items-center gap-2 text-[13px]"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $banner->is_active)) class="rounded text-accent focus:ring-accent"> Switched on</label>
                <button class="btn-accent btn-sm w-full">{{ $banner->exists ? 'Save banner' : 'Create banner' }}</button>
                @if ($banner->exists)
                    <p class="text-center text-xs text-muted">Status now: <x-admin.status :value="['live' => 'active', 'scheduled' => 'scheduled', 'ended' => 'expired', 'off' => 'off'][$banner->state()]" :label="ucfirst($banner->state())" /></p>
                @endif
            </div>
        </x-admin.card>
    </div>
</form>

@if ($banner->exists)
    <x-admin.card title="Preview" subtitle="How the saved banner looks and moves on the homepage. Save to update it." class="mt-4" flush>
        <div class="pb-2">
            <x-home.banners :banners="[$banner]" />
        </div>
    </x-admin.card>
@endif
@endsection
