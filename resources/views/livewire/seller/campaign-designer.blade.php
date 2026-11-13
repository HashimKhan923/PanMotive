<div class="pb-16">
    {{-- top bar --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <a href="{{ route('seller.campaigns.index') }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-accent"><x-ui.icon name="arrow-left" class="h-4 w-4" /> Campaigns</a>
            <h1 class="mt-1 truncate text-2xl font-bold">Design: {{ $campaign->name }}</h1>
        </div>
        @unless ($choosing)
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" x-data @click="$dispatch('pm-preview')" class="btn-outline btn-sm"><x-ui.icon name="external" class="h-4 w-4" /> Full preview</button>
                <button type="button" wire:click="sendTest" wire:loading.attr="disabled" class="btn-outline btn-sm"><x-ui.icon name="mail" class="h-4 w-4" /> Send me a test</button>
                <a href="{{ route('seller.campaigns.index', ['edit' => $campaign->id]) }}" class="btn-accent btn-sm"><x-ui.icon name="check" class="h-4 w-4" /> Done — choose who gets it</a>
            </div>
        @endunless
    </div>

    @if ($choosing)
        {{-- template chooser --}}
        <div class="mt-6">
            <h2 class="text-lg font-semibold">Pick a starting point</h2>
            <p class="text-sm text-muted">You can change everything afterwards.</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-reveal-group>
                @foreach ($starters as $key => $s)
                    <button type="button" wire:click="useStarter('{{ $key }}')" class="card group p-5 text-left transition hover:-translate-y-0.5 hover:shadow-lg">
                        <div class="mb-4 space-y-1.5 rounded-lg bg-surface-2 p-3">
                            <div class="h-2.5 w-2/3 rounded bg-fg/70"></div>
                            <div class="h-1.5 w-full rounded bg-muted/40"></div>
                            <div class="h-1.5 w-5/6 rounded bg-muted/40"></div>
                            @if (in_array($key, ['new_arrivals', 'sale', 'fits_your_car', 'seasonal', 'thank_you'], true))
                                <div class="grid grid-cols-2 gap-1.5 pt-1"><div class="h-8 rounded bg-muted/25"></div><div class="h-8 rounded bg-muted/25"></div></div>
                            @endif
                            <div class="mx-auto mt-1 h-3 w-1/3 rounded bg-accent"></div>
                        </div>
                        <p class="font-semibold group-hover:text-accent">{{ $s['name'] }}</p>
                        <p class="text-xs text-muted">{{ $s['about'] }}</p>
                    </button>
                @endforeach
            </div>
            @if ($saved->isNotEmpty())
                <h3 class="mt-8 font-semibold">Your templates</h3>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($saved as $t)
                        <div class="card flex items-center justify-between gap-2 p-4" wire:key="tpl-{{ $t->id }}">
                            <button type="button" wire:click="useSaved({{ $t->id }})" class="min-w-0 text-left">
                                <span class="block truncate font-medium hover:text-accent">{{ $t->name }}</span>
                                <span class="text-xs text-muted">Saved {{ $t->created_at->diffForHumans() }}</span>
                            </button>
                            <button type="button" wire:click="deleteTemplate({{ $t->id }})" wire:confirm="Delete this template?" class="text-muted hover:text-rose-600" aria-label="Delete template"><x-ui.icon name="trash" class="h-4 w-4" /></button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @else
        {{-- subject + preview text --}}
        <div class="card mt-5 grid gap-4 p-4 md:grid-cols-2">
            <div>
                <label class="label" for="d-subject">Subject</label>
                <input id="d-subject" wire:model.blur="subject" wire:change="save" maxlength="120" class="input text-sm" placeholder="e.g. New brake parts just landed, {first_name}">
            </div>
            <div>
                <label class="label" for="d-preview">Preview text <span class="text-muted">(shown after the subject in the inbox)</span></label>
                <input id="d-preview" wire:model.blur="preview" wire:change="save" maxlength="150" class="input text-sm" placeholder="e.g. 10% off this weekend only">
            </div>
        </div>

        <div wire:ignore class="mt-4"
             x-data="pmDesigner({
                blocks: @js($blocks), brand: @js($brand), types: @js(collect($types)->map(fn ($t) => ['label' => $t['label'], 'icon' => $t['icon']])),
                defaults: @js(collect($types)->map(fn ($t) => $t['props'])), catalog: @js($catalog), coupons: @js($coupons),
                store: @js($storeName), sources: @js(\App\Services\Marketing\CampaignDesign::SMART_SOURCES),
             })"
             @campaign-image.window="setImage($event.detail.block, $event.detail.url)"
             @keydown.window.ctrl.z.prevent="undo()" @keydown.window.ctrl.y.prevent="redo()">
            <div class="grid gap-4 lg:grid-cols-[230px_minmax(0,1fr)_300px]">
                {{-- left: blocks / brand / templates --}}
                <aside class="card h-max p-3 lg:sticky lg:top-20">
                    <div class="flex gap-1 rounded-lg bg-surface-2 p-1 text-xs font-medium">
                        <template x-for="t in [['blocks','Blocks'],['brand','Brand'],['save','Template']]" :key="t[0]">
                            <button type="button" @click="tab = t[0]" :class="tab === t[0] ? 'bg-surface text-fg shadow-sm' : 'text-muted'" class="flex-1 rounded-md px-2 py-1.5" x-text="t[1]"></button>
                        </template>
                    </div>

                    <div x-show="tab === 'blocks'" class="mt-3 grid grid-cols-2 gap-2">
                        <template x-for="(t, key) in types" :key="key">
                            <button type="button" draggable="true" @dragstart="dragType = key; dragId = null" @dragend="dragType = null; dropAt = null" @click="add(key)"
                                    class="flex cursor-grab flex-col items-center gap-1 rounded-lg border border-line p-2.5 text-center text-xs font-medium hover:border-accent hover:text-accent active:cursor-grabbing">
                                <span class="text-lg leading-none" x-text="emoji(key)"></span>
                                <span x-text="t.label"></span>
                            </button>
                        </template>
                        <p class="col-span-2 mt-1 text-[11px] text-muted">Drag a block onto the email, or click to add it.</p>
                    </div>

                    <div x-show="tab === 'brand'" x-cloak class="mt-3 space-y-4 text-sm">
                        <div>
                            <p class="label">Main colour</p>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="c in ['#E51B2B','#15191E','#1D4ED8','#047857','#EA580C','#7C3AED']" :key="c">
                                    <button type="button" @click="brand.color = c; commit()" :style="'background:' + c" :class="brand.color === c ? 'ring-2 ring-offset-2 ring-fg' : ''" class="h-7 w-7 rounded-full"></button>
                                </template>
                                <input type="color" x-model="brand.color" @change="brand.color = brand.color.toUpperCase(); commit()" class="h-7 w-9 cursor-pointer rounded border border-line bg-transparent" aria-label="Custom colour">
                            </div>
                        </div>
                        <div>
                            <p class="label">Buttons</p>
                            <div class="flex gap-2">
                                <template x-for="s in [['rounded','8px'],['pill','999px'],['square','0']]" :key="s[0]">
                                    <button type="button" @click="brand.button = s[0]; commit()" :class="brand.button === s[0] ? 'ring-2 ring-accent' : ''" class="rounded-lg border border-line px-2 py-1.5">
                                        <span class="block px-3 py-1 text-[11px] text-white" :style="'background:' + brand.color + ';border-radius:' + s[1]">Shop</span>
                                    </button>
                                </template>
                            </div>
                        </div>
                        <div>
                            <p class="label">Header</p>
                            <div class="flex gap-2 text-xs">
                                <button type="button" @click="brand.header = 'dark'; commit()" :class="brand.header === 'dark' ? 'ring-2 ring-accent' : ''" class="flex-1 rounded-lg bg-[#15191E] px-3 py-2 text-white">Dark</button>
                                <button type="button" @click="brand.header = 'light'; commit()" :class="brand.header === 'light' ? 'ring-2 ring-accent' : ''" class="flex-1 rounded-lg border border-line bg-white px-3 py-2 text-[#15191E]">Light</button>
                            </div>
                        </div>
                        <p class="text-[11px] text-muted">Your brand is saved for all your emails. Your store logo comes from Store settings.</p>
                    </div>

                    <div x-show="tab === 'save'" x-cloak class="mt-3 space-y-2 text-sm">
                        <p class="text-xs text-muted">Save this design to reuse it for future campaigns.</p>
                        <input wire:model="templateName" maxlength="80" class="input h-9 text-sm" placeholder="Template name">
                        <button type="button" wire:click="saveTemplate" class="btn-outline btn-sm w-full">Save as my template</button>
                        <button type="button" @click="if (confirm('Replace this design with a different template?')) $wire.set('choosing', true)" class="w-full text-xs text-muted hover:text-accent">Start again from a template</button>
                    </div>
                </aside>

                {{-- centre: the email --}}
                <section>
                    <div class="mb-2 flex items-center justify-between gap-2 text-xs">
                        <div class="flex gap-1 rounded-lg bg-surface-2 p-1 font-medium">
                            <button type="button" @click="device = 'desktop'" :class="device === 'desktop' ? 'bg-surface text-fg shadow-sm' : 'text-muted'" class="rounded-md px-3 py-1">Desktop</button>
                            <button type="button" @click="device = 'phone'" :class="device === 'phone' ? 'bg-surface text-fg shadow-sm' : 'text-muted'" class="rounded-md px-3 py-1">Phone</button>
                        </div>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="undo()" :disabled="!past.length" class="btn-ghost btn-sm !px-2 disabled:opacity-40" title="Undo (Ctrl+Z)">↶ Undo</button>
                            <button type="button" @click="redo()" :disabled="!future.length" class="btn-ghost btn-sm !px-2 disabled:opacity-40" title="Redo (Ctrl+Y)">↷ Redo</button>
                            <span class="ml-2 text-muted" x-text="saving ? 'Saving…' : 'All changes saved'"></span>
                        </div>
                    </div>

                    <div class="rounded-2xl bg-[#F5F6F8] p-4 sm:p-6">
                        <div class="mx-auto overflow-hidden rounded-xl border border-[#E6E9ED] bg-white text-[#15191E] shadow-sm transition-all" :style="device === 'phone' ? 'max-width:375px' : 'max-width:600px'">
                            <div class="px-6 py-4 text-center" :style="'background:' + (brand.header === 'dark' ? '#15191E' : '#fff') + ';border-bottom:3px solid ' + brand.color">
                                <span class="text-lg font-extrabold" :style="'color:' + (brand.header === 'dark' ? '#fff' : '#15191E')" x-text="store"></span>
                            </div>
                            <div class="space-y-1 p-4 sm:p-6" @dragover.prevent="if (!blocks.length) dropAt = 0" @drop.prevent="if (!blocks.length) drop(0)">
                                <template x-if="!blocks.length">
                                    <div class="rounded-xl border-2 border-dashed border-[#D9DDE3] p-10 text-center text-sm text-[#8A919C]">Drag blocks here</div>
                                </template>
                                <template x-for="(b, i) in blocks" :key="b.id">
                                    <div>
                                        <div class="h-1 rounded-full transition" :class="dropAt === i ? 'bg-accent' : ''" @dragover.prevent="dropAt = i" @drop.prevent="drop(i)"></div>
                                        <div draggable="true" @dragstart="dragId = b.id; dragType = null" @dragend="dragId = null; dropAt = null"
                                             @dragover.prevent="dropAt = ($event.offsetY > $event.target.offsetHeight / 2 ? i + 1 : i)" @drop.prevent="drop(dropAt ?? i)"
                                             @click="selected = b.id"
                                             :class="selected === b.id ? 'ring-2 ring-accent' : 'hover:ring-1 hover:ring-[#D9DDE3]'"
                                             class="group relative cursor-pointer rounded-lg p-2">
                                            <div class="absolute -top-3 right-2 z-10 hidden gap-1 group-hover:flex" :class="selected === b.id && '!flex'">
                                                <button type="button" @click.stop="move(i, -1)" class="rounded bg-[#15191E] px-1.5 text-xs text-white" title="Move up">↑</button>
                                                <button type="button" @click.stop="move(i, 1)" class="rounded bg-[#15191E] px-1.5 text-xs text-white" title="Move down">↓</button>
                                                <button type="button" @click.stop="duplicate(i)" class="rounded bg-[#15191E] px-1.5 text-xs text-white" title="Duplicate">⧉</button>
                                                <button type="button" @click.stop="remove(i)" class="rounded bg-[#BB1220] px-1.5 text-xs text-white" title="Delete">✕</button>
                                            </div>
                                            <div x-html="preview(b)"></div>
                                        </div>
                                    </div>
                                </template>
                                <div class="h-6 rounded-full transition" :class="dropAt === blocks.length ? 'bg-accent/60' : ''" @dragover.prevent="dropAt = blocks.length" @drop.prevent="drop(blocks.length)"></div>
                            </div>
                        </div>
                        <p class="mx-auto mt-3 max-w-[600px] text-center text-[11px] text-[#8A919C]">The unsubscribe link and your business address are added at the bottom of every email automatically.</p>
                    </div>
                </section>

                {{-- right: settings for the selected block --}}
                <aside class="card h-max p-4 lg:sticky lg:top-20">
                    <template x-if="!current()">
                        <div class="text-sm text-muted">
                            <p class="font-medium text-fg">Click a block to edit it</p>
                            <p class="mt-1">Personal touches you can type in any text:</p>
                            <ul class="mt-2 space-y-1 text-xs">
                                @foreach (\App\Services\Marketing\CampaignDesign::PLACEHOLDERS as $tag => $about)
                                    <li><code class="rounded bg-surface-2 px-1">{{ $tag }}</code> {{ $about }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </template>
                    <template x-if="current()">
                        <div class="space-y-3 text-sm" x-data="{ get p() { return current().props } }">
                            <p class="font-semibold" x-text="types[current().type].label"></p>

                            <template x-if="['heading','text'].includes(current().type)">
                                <div class="space-y-3">
                                    <textarea x-model="p.text" @input.debounce.400ms="commit()" rows="5" class="input text-sm"></textarea>
                                    <div class="flex gap-1">
                                        <template x-for="a in ['left','center','right']" :key="a">
                                            <button type="button" @click="p.align = a; commit()" :class="p.align === a ? 'bg-accent text-white' : 'bg-surface-2'" class="flex-1 rounded-md py-1 text-xs capitalize" x-text="a"></button>
                                        </template>
                                    </div>
                                    <template x-if="current().type === 'heading'">
                                        <select x-model="p.size" @change="commit()" class="input h-9 text-sm"><option value="large">Large</option><option value="medium">Medium</option></select>
                                    </template>
                                    <div class="rounded-lg bg-surface-2 p-2.5">
                                        <p class="text-xs font-medium">✨ Write it for me</p>
                                        <select x-model="aiGoal" class="input mt-1.5 h-8 text-xs">
                                            <option value="new_arrivals">New arrivals</option><option value="sale">A sale</option><option value="restock">Back in stock</option><option value="thank_you">Thank you</option><option value="win_back">We miss you</option>
                                        </select>
                                        <button type="button" @click="ai()" :disabled="aiBusy" class="btn-brand btn-sm mt-1.5 w-full !text-xs" x-text="aiBusy ? 'Writing…' : 'Write it'"></button>
                                    </div>
                                </div>
                            </template>

                            <template x-if="current().type === 'image'">
                                <div class="space-y-3">
                                    <template x-if="p.url"><img :src="p.url" alt="" class="w-full rounded-lg border border-line"></template>
                                    <label class="btn-outline btn-sm w-full cursor-pointer">
                                        <x-ui.icon name="upload" class="h-4 w-4" /> Upload image
                                        <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" class="hidden" @change="$wire.set('photoFor', current().id); $wire.upload('photo', $event.target.files[0])">
                                    </label>
                                    <p class="text-[11px] text-muted">JPG, PNG, GIF or WebP, up to 3 MB.</p>
                                    <select @change="if ($event.target.value) { p.url = $event.target.value; commit() }" class="input h-9 text-sm">
                                        <option value="">…or use a product photo</option>
                                        <template x-for="c in catalog.filter(c => c.image)" :key="c.id"><option :value="c.image" x-text="c.title"></option></template>
                                    </select>
                                    <input x-model="p.alt" @input.debounce.400ms="commit()" class="input h-9 text-sm" placeholder="Short description (for people who can’t see images)">
                                    <select x-model="p.link" @change="commit()" class="input h-9 text-sm"><option value="store">Links to my store</option><option value="custom">Links to a web address</option><option value="none">No link</option></select>
                                    <template x-if="p.link === 'custom'"><input x-model="p.custom_url" @input.debounce.500ms="commit()" class="input h-9 text-sm" placeholder="https://"></template>
                                </div>
                            </template>

                            <template x-if="current().type === 'button'">
                                <div class="space-y-3">
                                    <input x-model="p.label" @input.debounce.400ms="commit()" maxlength="40" class="input h-9 text-sm" placeholder="Button text">
                                    <select x-model="p.link" @change="commit()" class="input h-9 text-sm"><option value="store">Goes to my store</option><option value="product">Goes to a product</option><option value="custom">Goes to a web address</option></select>
                                    <template x-if="p.link === 'product'">
                                        <select x-model.number="p.product_id" @change="commit()" class="input h-9 text-sm">
                                            <option value="">Choose a product…</option>
                                            <template x-for="c in catalog" :key="c.id"><option :value="c.id" x-text="c.title" :selected="p.product_id === c.id"></option></template>
                                        </select>
                                    </template>
                                    <template x-if="p.link === 'custom'"><input x-model="p.custom_url" @input.debounce.500ms="commit()" class="input h-9 text-sm" placeholder="https://"></template>
                                    <div class="flex gap-1">
                                        <template x-for="a in ['left','center','right']" :key="a">
                                            <button type="button" @click="p.align = a; commit()" :class="p.align === a ? 'bg-accent text-white' : 'bg-surface-2'" class="flex-1 rounded-md py-1 text-xs capitalize" x-text="a"></button>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <template x-if="current().type === 'product'">
                                <div class="space-y-3">
                                    <input x-model="search" class="input h-9 text-sm" placeholder="Search your products…">
                                    <div class="max-h-64 space-y-1 overflow-y-auto">
                                        <template x-for="c in found()" :key="c.id">
                                            <button type="button" @click="p.product_id = c.id; commit()" :class="p.product_id === c.id ? 'bg-accent/10 ring-1 ring-accent' : 'hover:bg-surface-2'" class="flex w-full items-center gap-2 rounded-lg p-1.5 text-left">
                                                <img :src="c.image || ''" alt="" class="h-9 w-9 shrink-0 rounded bg-surface-2 object-cover">
                                                <span class="min-w-0 flex-1"><span class="block truncate text-xs font-medium" x-text="c.title"></span><span class="text-[11px] text-muted" x-text="c.price + (c.stock > 0 ? '' : ' · out of stock')"></span></span>
                                            </button>
                                        </template>
                                    </div>
                                    <input x-model="p.button_label" @input.debounce.400ms="commit()" maxlength="40" class="input h-9 text-sm" placeholder="Button text">
                                    <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="p.show_description" @change="commit()" class="rounded border-line text-accent"> Show key features</label>
                                </div>
                            </template>

                            <template x-if="current().type === 'products'">
                                <div class="space-y-3">
                                    <p class="text-xs text-muted">Pick up to 9 products.</p>
                                    <input x-model="search" class="input h-9 text-sm" placeholder="Search your products…">
                                    <div class="max-h-64 space-y-1 overflow-y-auto">
                                        <template x-for="c in found()" :key="c.id">
                                            <button type="button" @click="toggleProduct(c.id)" :class="p.product_ids.includes(c.id) ? 'bg-accent/10 ring-1 ring-accent' : 'hover:bg-surface-2'" class="flex w-full items-center gap-2 rounded-lg p-1.5 text-left">
                                                <img :src="c.image || ''" alt="" class="h-9 w-9 shrink-0 rounded bg-surface-2 object-cover">
                                                <span class="min-w-0 flex-1 truncate text-xs font-medium" x-text="c.title"></span>
                                                <span x-show="p.product_ids.includes(c.id)" class="text-accent">✓</span>
                                            </button>
                                        </template>
                                    </div>
                                    <div class="flex gap-1">
                                        <template x-for="n in [1,2,3]" :key="n">
                                            <button type="button" @click="p.columns = n; commit()" :class="p.columns === n ? 'bg-accent text-white' : 'bg-surface-2'" class="flex-1 rounded-md py-1 text-xs" x-text="n + ' per row'"></button>
                                        </template>
                                    </div>
                                    <input x-model="p.button_label" @input.debounce.400ms="commit()" maxlength="40" class="input h-9 text-sm" placeholder="Button text">
                                </div>
                            </template>

                            <template x-if="current().type === 'smart_products'">
                                <div class="space-y-3">
                                    <select x-model="p.source" @change="commit()" class="input h-9 text-sm">
                                        <template x-for="(label, key) in sources" :key="key"><option :value="key" x-text="label" :selected="p.source === key"></option></template>
                                    </select>
                                    <p class="text-[11px] text-muted" x-show="p.source === 'fits_vehicle'">Each person sees your parts that fit the vehicle in their garage. People without a vehicle see your bestsellers.</p>
                                    <input x-model="p.title" @input.debounce.400ms="commit()" class="input h-9 text-sm" placeholder="Title above the products">
                                    <label class="text-xs">How many: <input type="number" min="1" max="9" x-model.number="p.count" @change="commit()" class="input ml-1 inline-block h-8 w-16 text-sm"></label>
                                    <div class="flex gap-1">
                                        <template x-for="n in [2,3]" :key="n">
                                            <button type="button" @click="p.columns = n; commit()" :class="p.columns === n ? 'bg-accent text-white' : 'bg-surface-2'" class="flex-1 rounded-md py-1 text-xs" x-text="n + ' per row'"></button>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <template x-if="current().type === 'coupon'">
                                <div class="space-y-3">
                                    <template x-if="!coupons.length"><p class="rounded-lg bg-amber-500/10 p-2 text-xs text-amber-700">You have no active coupons. Create one in Marketing → Promotions first.</p></template>
                                    <select x-model.number="p.coupon_id" @change="commit()" class="input h-9 text-sm">
                                        <option value="">Choose a coupon…</option>
                                        <template x-for="c in coupons" :key="c.id"><option :value="c.id" x-text="c.code + ' — ' + c.off" :selected="p.coupon_id === c.id"></option></template>
                                    </select>
                                    <input x-model="p.headline" @input.debounce.400ms="commit()" class="input h-9 text-sm" placeholder="Headline">
                                    <input x-model="p.button_label" @input.debounce.400ms="commit()" maxlength="40" class="input h-9 text-sm" placeholder="Button text">
                                </div>
                            </template>

                            <template x-if="current().type === 'columns'">
                                <div class="space-y-3">
                                    <textarea x-model="p.left" @input.debounce.400ms="commit()" rows="3" class="input text-sm" placeholder="Left column"></textarea>
                                    <textarea x-model="p.right" @input.debounce.400ms="commit()" rows="3" class="input text-sm" placeholder="Right column"></textarea>
                                    <p class="text-[11px] text-muted">On phones the columns stack.</p>
                                </div>
                            </template>

                            <template x-if="current().type === 'video'">
                                <div class="space-y-3">
                                    <input x-model="p.url" @input.debounce.500ms="commit()" class="input h-9 text-sm" placeholder="YouTube link">
                                    <input x-model="p.caption" @input.debounce.400ms="commit()" class="input h-9 text-sm" placeholder="Caption">
                                    <p class="text-[11px] text-muted">Email apps can’t play video, so we show the video’s picture with a play link.</p>
                                </div>
                            </template>

                            <template x-if="current().type === 'social'">
                                <div class="space-y-2">
                                    <template x-for="n in ['facebook','instagram','youtube','tiktok']" :key="n">
                                        <input x-model="p[n]" @input.debounce.500ms="commit()" class="input h-9 text-sm" :placeholder="n.charAt(0).toUpperCase() + n.slice(1) + ' link (https://…)'">
                                    </template>
                                </div>
                            </template>

                            <template x-if="current().type === 'spacer'">
                                <label class="text-xs">Height: <input type="range" min="8" max="80" x-model.number="p.size" @change="commit()" class="ml-2 align-middle"> <span x-text="p.size + 'px'"></span></label>
                            </template>
                            <template x-if="current().type === 'divider'"><p class="text-xs text-muted">A thin line between sections.</p></template>
                        </div>
                    </template>
                </aside>
            </div>

            {{-- full preview (the real email from the server) --}}
            <div x-show="full" x-cloak @pm-preview.window="full = true; frameKey++" @keydown.escape.window="full = false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                <div class="flex h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-surface shadow-2xl" @click.outside="full = false">
                    <div class="flex items-center justify-between border-b border-line px-4 py-2 text-sm">
                        <span class="font-semibold">Preview: exactly what customers receive (with sample details)</span>
                        <button type="button" @click="full = false" class="btn-ghost btn-sm">Close</button>
                    </div>
                    <template x-if="full"><iframe :src="@js(route('seller.campaigns.preview', $campaign)) + '?v=' + frameKey" class="w-full flex-1 bg-[#F5F6F8]" title="Email preview"></iframe></template>
                </div>
            </div>
        </div>

        {{-- quality check (from the server, after each save) --}}
        <div class="card mt-4 p-4">
            <p class="font-semibold">Check before sending</p>
            @if ($issues === [])
                <p class="mt-1 flex items-center gap-1.5 text-sm text-emerald-600"><x-ui.icon name="check" class="h-4 w-4" /> Looks great. Ready to send.</p>
            @else
                <ul class="mt-2 space-y-1.5 text-sm">
                    @foreach ($issues as $issue)
                        <li class="flex items-start gap-2">
                            <span class="mt-0.5 shrink-0 rounded px-1.5 text-[10px] font-bold uppercase {{ ['error' => 'bg-rose-500/15 text-rose-600', 'warning' => 'bg-amber-500/15 text-amber-700', 'tip' => 'bg-surface-2 text-muted'][$issue['level']] }}">{{ ['error' => 'Fix', 'warning' => 'Check', 'tip' => 'Tip'][$issue['level']] }}</span>
                            <span>{{ $issue['text'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>
