<div x-data="{ open: false }" class="pm-chat fixed bottom-5 right-5 z-50 transition-[bottom] duration-300">
    <button @click="open = !open" x-bind:aria-expanded="open" aria-label="Open support chat"
            class="group relative grid h-12 w-12 place-items-center rounded-full bg-brand text-brand-fg shadow-[0_12px_30px_-10px_rgb(15_23_42/.6)] ring-1 ring-white/10 transition hover:scale-105 active:scale-95 dark:bg-accent dark:text-accent-fg">
        {{-- chat bubble ↔ close icon --}}
        <svg x-show="! open" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.4A8 8 0 1 1 21 12Z"/><path d="M8.5 11.5h.01M12 11.5h.01M15.5 11.5h.01" stroke-width="2.6"/>
        </svg>
        <svg x-show="open" x-cloak class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
        <span x-show="! open" class="absolute right-0 top-0 h-3 w-3 rounded-full border-2 border-bg bg-emerald-500" aria-hidden="true"></span>
    </button>

    <div x-show="open" x-cloak role="dialog" aria-label="Support chat"
         x-transition:enter="transition duration-300 ease-[cubic-bezier(.22,1,.36,1)]"
         x-transition:enter-start="opacity-0 translate-y-3 scale-95" x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition duration-150 ease-in"
         x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
         class="absolute bottom-16 right-0 flex h-[26rem] w-[22rem] max-w-[calc(100vw-2.5rem)] origin-bottom-right flex-col overflow-hidden rounded-2xl border border-line bg-surface text-fg shadow-[0_30px_60px_-20px_rgb(15_23_42/.45)]">
        <div class="flex items-center gap-3 border-b border-line bg-brand px-4 py-3 text-brand-fg">
            <span class="grid h-8 w-8 place-items-center rounded-full bg-accent text-accent-fg"><x-ui.icon name="sparkles" class="w-4 h-4" /></span>
            <div class="leading-tight">
                <p class="text-sm font-semibold">Pan Motive Support</p>
                <p class="text-[11px] text-white/60">Orders, returns, shipping &amp; fitment</p>
            </div>
        </div>
        <div class="flex-1 space-y-2 overflow-y-auto p-3 text-sm">
            @forelse ($messages as $msg)
                <div class="{{ $msg['role'] === 'user' ? 'text-right' : 'text-left' }}">
                    <span class="inline-block max-w-[85%] whitespace-pre-line rounded-2xl px-3 py-2 text-left {{ $msg['role'] === 'user' ? 'bg-accent text-accent-fg' : 'bg-surface-2' }}">
                        {{ $msg['content'] }}
                    </span>
                </div>
            @empty
                <p class="text-muted">Hi! Ask about an order, a return, shipping times or whether a part fits your vehicle.</p>
            @endforelse
            @if ($thinking)
                <div class="text-left"><span class="inline-block rounded-2xl bg-surface-2 px-3 py-2 text-muted">Typing…</span></div>
            @endif
        </div>
        <form wire:submit="send" class="flex gap-2 border-t border-line p-3">
            <input wire:model="input" placeholder="Type your question…" class="input h-10 flex-1 text-sm" aria-label="Your question">
            <button class="btn-accent btn-sm">Send</button>
        </form>
    </div>
</div>
