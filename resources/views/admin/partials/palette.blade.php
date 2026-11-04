{{-- Ctrl/Cmd+K: jump to any page, or find an order, person, store or part. --}}
@php
    $pages = collect(\App\Support\AdminNav::groups(auth()->user()))
        ->flatMap(fn ($g) => collect($g['items'])->map(fn ($i) => ['label' => $i['label'], 'group' => $g['label'] ?? 'Overview', 'url' => route($i['route']), 'kind' => 'Page']))
        ->values();
@endphp
<div x-data="admPalette(@js($pages), @js(route('admin.search')))"
     @adm-palette.window="show()"
     @keydown.window.ctrl.k.prevent="show()"
     @keydown.window.meta.k.prevent="show()"
     @keydown.escape.window="open = false"
     x-show="open" x-cloak
     class="fixed inset-0 z-[90] flex items-start justify-center p-4 pt-[12vh]" role="dialog" aria-modal="true" aria-label="Search the admin console">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" x-show="open" x-transition.opacity @click="open = false"></div>
    <div class="adm-palette relative w-full max-w-xl overflow-hidden rounded-2xl" x-show="open"
         x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="opacity-0 scale-95 -translate-y-2" x-transition:enter-end="opacity-100 scale-100 translate-y-0">
        <div class="flex items-center gap-3 border-b border-line px-4">
            <x-ui.icon name="search" class="w-6 h-6 shrink-0 text-muted" />
            <input x-ref="input" x-model="q" @input.debounce.220ms="search()" @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="go()"
                   type="text" placeholder="Order number, email, store, part, or page name" aria-label="Search"
                   class="h-14 w-full border-0 bg-transparent px-0 text-[15px] text-fg placeholder:text-muted focus:ring-0">
            <span x-show="loading" class="h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-accent border-t-transparent"></span>
        </div>
        <ul class="adm-scroll max-h-[50vh] overflow-y-auto p-2" role="listbox">
            <template x-for="(row, i) in rows" :key="row.url + i">
                <li role="option" :aria-selected="i === active">
                    <a :href="row.url" @mouseenter="active = i" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm" :class="i === active ? 'bg-surface-2 text-fg' : ''">
                        <span class="w-16 shrink-0 text-[10px] font-bold uppercase tracking-wider text-muted" x-text="row.kind"></span>
                        <span class="min-w-0 flex-1 truncate font-medium" x-text="row.label"></span>
                        <span class="shrink-0 truncate text-xs text-muted" x-text="row.group"></span>
                    </a>
                </li>
            </template>
            <li x-show="!rows.length && !loading" class="px-3 py-8 text-center text-sm text-muted">Nothing found for that search.</li>
        </ul>
        <div class="flex items-center gap-4 border-t border-line px-4 py-2 text-[11px] text-muted">
            <span>↑ ↓ to move</span><span>Enter to open</span><span>Esc to close</span>
        </div>
    </div>
</div>
