<div class="pb-10" wire:ignore
     x-data="pmSocialDesigner({ size: @js($size), canvasJson: @js($current?->canvas), products: @js($products), reviews: @js($reviews), store: @js($store), logo: @js($logo), color: @js($color), productId: @js($product) })"
     @keydown.window="key($event)">
    {{-- top bar --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
            <a href="{{ route('seller.social.index') }}" class="btn-ghost btn-sm"><x-ui.icon name="arrow-left" class="h-4 w-4" /> Studio</a>
            <input wire:model.blur="name" maxlength="80" class="input h-9 w-56 text-sm font-semibold" aria-label="Design name">
        </div>
        <div class="flex flex-wrap items-center gap-1.5">
            <template x-for="(s, key) in (mod ? mod.SIZES : {})" :key="key">
                <button type="button" @click="changeSize(key)" :class="ed && ed.size === key ? 'bg-accent text-white' : 'bg-surface-2 hover:text-accent'" class="rounded-lg px-2.5 py-1.5 text-xs font-medium" :title="s.hint" x-text="s.label"></button>
            </template>
            <span class="mx-1 h-6 w-px bg-line"></span>
            <button type="button" @click="ed.undo()" class="btn-ghost btn-sm !px-2" title="Undo (Ctrl+Z)">↶</button>
            <button type="button" @click="ed.redo()" class="btn-ghost btn-sm !px-2" title="Redo (Ctrl+Y)">↷</button>
            <button type="button" @click="download()" class="btn-outline btn-sm"><x-ui.icon name="download" class="h-4 w-4" /> Download</button>
            <button type="button" @click="save(false)" :disabled="busy" class="btn-outline btn-sm"><x-ui.icon name="check" class="h-4 w-4" /> Save</button>
            <button type="button" @click="save(true)" :disabled="busy" class="btn-accent btn-sm"><x-ui.icon name="arrow-right" class="h-4 w-4" /> <span x-text="busy ? 'Saving…' : 'Use in a post'"></span></button>
        </div>
    </div>
    <p x-show="error" x-text="error" class="mt-2 text-sm text-rose-600"></p>

    <div class="mt-4 grid gap-4 xl:grid-cols-[300px_minmax(0,1fr)_280px]">
        {{-- left: tools --}}
        <aside class="card h-max p-3 xl:sticky xl:top-20">
            <div class="grid grid-cols-4 gap-1 text-[11px] font-medium">
                <template x-for="t in [['templates','Templates'],['text','Text'],['elements','Elements'],['photos','Photos'],['uploads','Uploads'],['background','Backdrop'],['saved','My designs']]" :key="t[0]">
                    <button type="button" @click="tab = t[0]" :class="tab === t[0] ? 'bg-accent text-white' : 'bg-surface-2 hover:text-accent'" class="rounded-md px-1 py-1.5" x-text="t[1]"></button>
                </template>
            </div>

            {{-- templates --}}
            <div x-show="tab === 'templates'" class="mt-3">
                <div class="mb-2 space-y-2">
                    <select x-model.number="productId" @change="makeThumbs()" class="input h-9 text-sm">
                        <template x-for="p in products" :key="p.id"><option :value="p.id" x-text="p.title" :selected="p.id === productId"></option></template>
                    </select>
                    <input x-model="headline" @change="makeThumbs()" class="input h-9 text-sm" placeholder="Headline (optional), e.g. Upgrade your ride">
                    <p class="text-[11px] text-muted" x-show="!products.length">Add a photo to a listing to use it in templates.</p>
                </div>
                <div class="grid max-h-[62vh] grid-cols-2 gap-2 overflow-y-auto pr-1">
                    <template x-for="(tpl, key) in (mod ? mod.TEMPLATES : {})" :key="key">
                        <button type="button" @click="useTemplate(key)" class="group overflow-hidden rounded-lg border border-line text-left hover:border-accent">
                            <template x-if="thumbs[key]"><img :src="thumbs[key]" alt="" class="block w-full"></template>
                            <template x-if="!thumbs[key]"><div class="grid aspect-square place-items-center bg-surface-2 text-[11px] text-muted">…</div></template>
                            <span class="block truncate px-2 py-1 text-[11px] font-medium group-hover:text-accent" x-text="tpl.name"></span>
                        </button>
                    </template>
                </div>
            </div>

            {{-- text --}}
            <div x-show="tab === 'text'" x-cloak class="mt-3 space-y-2">
                <button type="button" @click="ed.addText('heading')" class="block w-full rounded-lg border border-line px-3 py-3 text-left text-xl font-extrabold hover:border-accent">Add a headline</button>
                <button type="button" @click="ed.addText('sub')" class="block w-full rounded-lg border border-line px-3 py-2.5 text-left text-base font-bold hover:border-accent">Add a subheading</button>
                <button type="button" @click="ed.addText('body')" class="block w-full rounded-lg border border-line px-3 py-2 text-left text-sm hover:border-accent">Add body text</button>
                <button type="button" @click="ed.addText('price')" class="block w-full rounded-lg border border-line px-3 py-2 text-left text-lg font-black text-accent hover:border-accent" style="font-family: Impact, sans-serif">Add a price</button>
                <p class="text-[11px] text-muted">Double-click text on the canvas to type. Use the panel on the right to change the font, size and colour.</p>
            </div>

            {{-- elements --}}
            <div x-show="tab === 'elements'" x-cloak class="mt-3 space-y-3">
                <div>
                    <p class="mb-1.5 text-xs font-semibold text-muted">Shapes</p>
                    <div class="grid grid-cols-3 gap-2">
                        <button type="button" @click="ed.addShape('rect')" class="grid h-14 place-items-center rounded-lg bg-surface-2 hover:ring-1 hover:ring-accent"><span class="h-6 w-9 bg-accent"></span></button>
                        <button type="button" @click="ed.addShape('rounded')" class="grid h-14 place-items-center rounded-lg bg-surface-2 hover:ring-1 hover:ring-accent"><span class="h-4 w-10 rounded-full bg-accent"></span></button>
                        <button type="button" @click="ed.addShape('circle')" class="grid h-14 place-items-center rounded-lg bg-surface-2 hover:ring-1 hover:ring-accent"><span class="h-8 w-8 rounded-full bg-accent"></span></button>
                        <button type="button" @click="ed.addShape('burst')" class="grid h-14 place-items-center rounded-lg bg-surface-2 text-2xl text-accent hover:ring-1 hover:ring-accent">✸</button>
                        <button type="button" @click="ed.addShape('line')" class="grid h-14 place-items-center rounded-lg bg-surface-2 hover:ring-1 hover:ring-accent"><span class="h-1 w-10 bg-accent"></span></button>
                        <button type="button" @click="ed.addShape('band')" class="grid h-14 place-items-center rounded-lg bg-gradient-to-b from-transparent to-black/80 text-[10px] text-white hover:ring-1 hover:ring-accent">Shade</button>
                    </div>
                </div>
                <div>
                    <p class="mb-1.5 text-xs font-semibold text-muted">Stickers</p>
                    <div class="grid grid-cols-4 gap-2">
                        <template x-for="s in stickers" :key="s">
                            <button type="button" @click="ed.addSticker(s)" class="grid h-12 place-items-center rounded-lg bg-surface-2 text-xl font-black hover:ring-1 hover:ring-accent" x-text="s"></button>
                        </template>
                    </div>
                </div>
            </div>

            {{-- photos --}}
            <div x-show="tab === 'photos'" x-cloak class="mt-3">
                <p class="mb-2 text-[11px] text-muted">Click to add a photo. Shift-click to make it the background.</p>
                <div class="grid max-h-[62vh] grid-cols-3 gap-2 overflow-y-auto">
                    <template x-for="p in products" :key="p.id">
                        <button type="button" @click="$event.shiftKey ? ed.setBackgroundImage(p.image) : ed.addImage(p.image, 'Product photo')" class="overflow-hidden rounded-lg ring-1 ring-line hover:ring-accent" :title="p.title">
                            <img :src="p.image" alt="" class="aspect-square w-full object-cover" loading="lazy">
                        </button>
                    </template>
                </div>
            </div>

            {{-- uploads --}}
            <div x-show="tab === 'uploads'" x-cloak class="mt-3 space-y-2">
                <label class="btn-outline btn-sm w-full cursor-pointer"><x-ui.icon name="upload" class="h-4 w-4" /> Upload an image
                    <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" @change="$wire.upload('upload', $event.target.files[0])">
                </label>
                <p class="text-[11px] text-muted">JPG, PNG, WebP or GIF up to 6 MB, for example a shop photo, a car or your logo.</p>
                @if ($logo)<button type="button" @click="ed.addImage(logo, 'Logo')" class="btn-ghost btn-sm w-full">Add my store logo</button>@endif
            </div>

            {{-- background --}}
            <div x-show="tab === 'background'" x-cloak class="mt-3">
                <div class="grid grid-cols-3 gap-2">
                    <template x-for="(b, i) in backgrounds" :key="i">
                        <button type="button" @click="ed.setBackground(b)" class="h-14 rounded-lg ring-1 ring-line hover:ring-accent"
                                :style="Array.isArray(b) ? `background:linear-gradient(135deg, ${b[0]}, ${b[1]})` : `background:${b}`"></button>
                    </template>
                </div>
                <label class="mt-3 flex items-center gap-2 text-xs">Custom <input type="color" @input="ed.setBackground($event.target.value)" class="h-8 w-12 cursor-pointer rounded border border-line bg-transparent"></label>
            </div>

            {{-- saved --}}
            <div x-show="tab === 'saved'" x-cloak class="mt-3">
                <div class="grid max-h-[62vh] grid-cols-2 gap-2 overflow-y-auto">
                    @forelse ($saved as $d)
                        <div class="group relative overflow-hidden rounded-lg ring-1 ring-line" wire:key="sd-{{ $d->id }}">
                            <a href="{{ route('seller.social.design', ['design' => $d->id]) }}"><img src="{{ $d->imageUrl() }}" alt="" class="w-full"></a>
                            <p class="truncate px-2 py-1 text-[11px]">{{ $d->name }}</p>
                            <button type="button" wire:click="deleteDesign({{ $d->id }})" wire:confirm="Delete this design?" class="absolute right-1 top-1 hidden rounded bg-black/60 px-1.5 text-xs text-white group-hover:block">✕</button>
                        </div>
                    @empty
                        <p class="col-span-2 text-xs text-muted">Saved designs appear here.</p>
                    @endforelse
                </div>
                <a href="{{ route('seller.social.design', ['size' => 'square']) }}" class="btn-ghost btn-sm mt-2 w-full">+ New blank design</a>
            </div>
        </aside>

        {{-- centre: canvas --}}
        <section>
            <div x-ref="wrap" class="grid min-h-[420px] place-items-center rounded-2xl bg-[repeating-conic-gradient(#EEF0F3_0%_25%,#F8F9FA_0%_50%)] bg-[length:24px_24px] p-3">
                <div class="shadow-2xl"><canvas x-ref="canvas"></canvas></div>
            </div>
            <p class="mt-2 text-center text-[11px] text-muted" x-show="ready">Drag to move · corners to resize · top handle to rotate · Ctrl+Z undo · Ctrl+D duplicate · Delete removes · arrows nudge</p>
            <p class="mt-2 text-center text-sm text-muted" x-show="!ready">Loading the designer…</p>
        </section>

        {{-- right: selected element + layers --}}
        <aside class="space-y-4 xl:sticky xl:top-20 xl:h-max">
            <div class="card p-4">
                <template x-if="!sel"><p class="text-sm text-muted">Click something on the canvas to style it.</p></template>
                <template x-if="sel">
                    <div class="space-y-3 text-sm">
                        <p class="font-semibold" x-text="sel.multiple ? 'Several items' : sel.name"></p>
                        <template x-if="sel.isText">
                            <div class="space-y-2">
                                <select @change="setProp('fontFamily', $event.target.value)" class="input h-9 text-sm">
                                    <template x-for="f in ed.FONTS" :key="f"><option :value="f" x-text="f" :selected="sel.fontFamily && sel.fontFamily.startsWith(f)" :style="'font-family:' + f"></option></template>
                                </select>
                                <div class="flex items-center gap-2">
                                    <input type="number" min="8" max="400" :value="Math.round(sel.fontSize)" @change="setProp('fontSize', +$event.target.value)" class="input h-9 w-20 text-sm" aria-label="Font size">
                                    <button type="button" @click="setProp('fontWeight', String(sel.fontWeight) >= '700' ? 400 : 800)" :class="String(sel.fontWeight) >= '700' ? 'bg-accent text-white' : 'bg-surface-2'" class="h-9 w-9 rounded-md font-bold">B</button>
                                    <button type="button" @click="setProp('fontStyle', sel.fontStyle === 'italic' ? 'normal' : 'italic')" :class="sel.fontStyle === 'italic' ? 'bg-accent text-white' : 'bg-surface-2'" class="h-9 w-9 rounded-md italic">I</button>
                                    <button type="button" @click="setProp('underline', !sel.underline)" :class="sel.underline ? 'bg-accent text-white' : 'bg-surface-2'" class="h-9 w-9 rounded-md underline">U</button>
                                </div>
                                <div class="flex gap-1">
                                    <template x-for="a in ['left','center','right']" :key="a">
                                        <button type="button" @click="setProp('textAlign', a)" :class="sel.textAlign === a ? 'bg-accent text-white' : 'bg-surface-2'" class="flex-1 rounded-md py-1 text-xs capitalize" x-text="a"></button>
                                    </template>
                                </div>
                                <label class="block text-xs text-muted">Letter spacing <input type="range" min="-50" max="400" :value="sel.charSpacing" @change="setProp('charSpacing', +$event.target.value)" class="w-full"></label>
                                <label class="block text-xs text-muted">Line height <input type="range" min="0.8" max="2" step="0.05" :value="sel.lineHeight" @change="setProp('lineHeight', +$event.target.value)" class="w-full"></label>
                                <div class="flex items-center gap-3 text-xs">
                                    <label class="flex items-center gap-1.5"><input type="checkbox" :checked="!!sel.stroke" @change="setProp('stroke', $event.target.checked ? '#15191E' : '')"> Outline</label>
                                    <label class="flex items-center gap-1.5"><input type="checkbox" :checked="sel.shadow" @change="setProp('shadow', $event.target.checked)"> Shadow</label>
                                </div>
                            </div>
                        </template>
                        <div>
                            <p class="mb-1 text-xs text-muted">Colour</p>
                            <div class="flex flex-wrap gap-1.5">
                                <template x-for="c in colors" :key="c">
                                    <button type="button" @click="setProp('fill', c)" class="h-7 w-7 rounded-full ring-1 ring-line" :style="'background:' + c" :class="sel.fill === c && 'ring-2 ring-accent'"></button>
                                </template>
                                <input type="color" :value="sel.fill && sel.fill.startsWith('#') ? sel.fill : '#15191E'" @input="setProp('fill', $event.target.value)" class="h-7 w-9 cursor-pointer rounded border border-line bg-transparent">
                            </div>
                        </div>
                        <label class="block text-xs text-muted">Transparency <input type="range" min="0.05" max="1" step="0.05" :value="sel.opacity" @change="setProp('opacity', +$event.target.value)" class="w-full"></label>
                        <div class="grid grid-cols-3 gap-1 text-xs">
                            <button type="button" @click="ed.align('left')" class="rounded bg-surface-2 py-1">⇤ Left</button>
                            <button type="button" @click="ed.align('centerX')" class="rounded bg-surface-2 py-1">↔ Centre</button>
                            <button type="button" @click="ed.align('right')" class="rounded bg-surface-2 py-1">Right ⇥</button>
                            <button type="button" @click="ed.align('top')" class="rounded bg-surface-2 py-1">⤒ Top</button>
                            <button type="button" @click="ed.align('middle')" class="rounded bg-surface-2 py-1">↕ Middle</button>
                            <button type="button" @click="ed.align('bottom')" class="rounded bg-surface-2 py-1">Bottom ⤓</button>
                        </div>
                        <div class="grid grid-cols-4 gap-1 text-xs">
                            <button type="button" @click="ed.layer('top')" class="rounded bg-surface-2 py-1.5" title="Bring to front">⬆⬆</button>
                            <button type="button" @click="ed.layer('up')" class="rounded bg-surface-2 py-1.5" title="Forward">⬆</button>
                            <button type="button" @click="ed.layer('down')" class="rounded bg-surface-2 py-1.5" title="Backward">⬇</button>
                            <button type="button" @click="ed.layer('bottom')" class="rounded bg-surface-2 py-1.5" title="Send to back">⬇⬇</button>
                        </div>
                        <div class="flex gap-1.5">
                            <button type="button" @click="ed.duplicate()" class="btn-ghost btn-sm flex-1 !text-xs">⧉ Duplicate</button>
                            <button type="button" @click="ed.toggleLock(); sel = ed.props()" class="btn-ghost btn-sm flex-1 !text-xs" x-text="sel.locked ? '🔓 Unlock' : '🔒 Lock'"></button>
                            <button type="button" @click="ed.remove()" class="btn-ghost btn-sm flex-1 !text-xs text-rose-600">✕ Delete</button>
                        </div>
                    </div>
                </template>
            </div>

            <div class="card p-4">
                <p class="mb-2 text-sm font-semibold">Layers</p>
                <ul class="max-h-72 space-y-1 overflow-y-auto text-xs">
                    <template x-for="l in layersList" :key="l.i + l.name">
                        <li class="flex items-center gap-2 rounded-md px-2 py-1.5" :class="l.active ? 'bg-accent/10 text-accent' : 'hover:bg-surface-2'">
                            <button type="button" @click="ed.selectLayer(l.i)" class="min-w-0 flex-1 truncate text-left" x-text="l.name"></button>
                            <span x-show="l.locked">🔒</span>
                            <button type="button" @click="ed.toggleVisible(l.i)" :title="l.visible ? 'Hide' : 'Show'" x-text="l.visible ? '👁' : '◌'"></button>
                        </li>
                    </template>
                </ul>
            </div>
        </aside>
    </div>
</div>
