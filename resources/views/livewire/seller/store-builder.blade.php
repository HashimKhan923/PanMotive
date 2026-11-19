<div class="max-w-3xl">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Storefront builder</h1>
            <p class="mt-1 text-sm text-muted">Design your public store page. Share the link anywhere — it's your shopfront on Panmotive.</p>
        </div>
        @if ($profile->slug)
            <a href="{{ route('sellers.show', $profile) }}" target="_blank" class="btn-outline btn-sm"><x-ui.icon name="external" class="w-4 h-4" /> View store</a>
        @endif
    </div>

    <form wire:submit="save" class="mt-6 space-y-6">
        <section class="card p-6">
            <h2 class="font-bold">Branding</h2>
            <div class="mt-4 flex flex-wrap items-center gap-5">
                @php $logoPreview = $logo && method_exists($logo, 'temporaryUrl') && ! $errors->has('logo') ? $logo->temporaryUrl() : $profile->logoUrl(); @endphp
                <div class="grid h-20 w-20 shrink-0 place-items-center overflow-hidden rounded-2xl border border-line bg-surface-2">
                    @if ($logoPreview)
                        <img src="{{ $logoPreview }}" alt="Store logo" class="h-full w-full object-contain">
                    @else
                        <span class="text-2xl font-bold text-muted">{{ \Illuminate\Support\Str::of($profile->business_name)->substr(0, 1)->upper() }}</span>
                    @endif
                </div>
                <div class="min-w-0 flex-1">
                    <label class="label" for="sb-logo">Store logo</label>
                    <input id="sb-logo" type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="text-sm">
                    <div wire:loading wire:target="logo" class="mt-1 text-xs text-muted">Uploading…</div>
                    <p class="mt-1 text-xs text-muted">Square PNG, JPG or WebP, at least 100 × 100 pixels, up to 2 MB. Shown on your store page and next to your listings.</p>
                    @error('logo') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    @if ($profile->logo_path && ! $logo)
                        <button type="button" wire:click="removeLogo" wire:confirm="Remove your store logo?" class="mt-2 text-xs font-semibold text-rose-500 hover:underline">Remove logo</button>
                    @endif
                </div>
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-[120px_1fr]">
                <div>
                    <label class="label" for="sb-accent">Accent colour</label>
                    <input id="sb-accent" type="color" wire:model="accent" class="h-10 w-full rounded-lg border border-line">
                    @error('accent') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="sb-banner">Banner image</label>
                    <input id="sb-banner" type="file" wire:model="banner" accept="image/*" class="text-sm">
                    <div wire:loading wire:target="banner" class="mt-1 text-xs text-muted">Uploading…</div>
                    @if ($profile->banner_path)<p class="mt-1 text-xs text-muted">Current banner set.</p>@endif
                    @error('banner') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="sb-hh">Hero headline</label>
                    <input id="sb-hh" wire:model="heroHeadline" maxlength="80" class="input" placeholder="Brakes &amp; suspension, done right">
                </div>
                <div>
                    <label class="label" for="sb-hs">Hero subtext</label>
                    <input id="sb-hs" wire:model="heroSubtext" maxlength="180" class="input">
                </div>
            </div>
        </section>

        <section class="card p-6">
            <h2 class="font-bold">About</h2>
            <textarea wire:model="about" rows="4" class="input mt-3" placeholder="Your story, specialities, guarantees…"></textarea>
        </section>

        <section class="card p-6">
            <h2 class="font-bold">Featured products</h2>
            <p class="mt-1 text-sm text-muted">Pick a few to spotlight at the top of your store.</p>
            <select multiple wire:model="featured" class="input mt-3 h-40 text-sm">
                @foreach ($products as $p)<option value="{{ $p->id }}">{{ $p->title }}</option>@endforeach
            </select>
        </section>

        <section class="card p-6">
            <div class="flex items-center justify-between">
                <h2 class="font-bold">Content sections</h2>
                <button type="button" wire:click="addSection" class="btn-outline btn-sm"><x-ui.icon name="plus" class="w-4 h-4" /> Add section</button>
            </div>
            <div class="mt-3 space-y-3">
                @foreach ($sections as $i => $s)
                    <div wire:key="sec-{{ $i }}" class="rounded-xl border border-line p-3">
                        <div class="flex items-center gap-2">
                            <input wire:model="sections.{{ $i }}.heading" class="input h-9 text-sm" placeholder="Section heading">
                            <button type="button" wire:click="removeSection({{ $i }})" class="btn-ghost !p-2 text-muted hover:text-rose-500"><x-ui.icon name="trash" class="w-4 h-4" /></button>
                        </div>
                        <textarea wire:model="sections.{{ $i }}.body" rows="3" class="input mt-2 text-sm" placeholder="Body text"></textarea>
                    </div>
                @endforeach
            </div>
        </section>

        <button type="submit" class="btn-accent">Save storefront</button>
    </form>
</div>
