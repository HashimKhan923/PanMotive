{{-- Global toast host — listens for window 'toast' CustomEvents and Livewire 'toast' dispatches.
     Toasts spring up from the bottom, carry an icon for their kind, and show a shrinking timer bar. --}}
<div x-data="{
        toasts: [],
        add(message, kind = 'info') {
            if (!message) return;
            kind = kind || (/(couldn|could not|failed|error|sorry|can't|cannot)/i.test(message) ? 'error' : 'success');
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message, kind, show: false });
            this.$nextTick(() => { const t = this.toasts.find(t => t.id === id); if (t) t.show = true; });
            setTimeout(() => this.remove(id), 3600);
        },
        remove(id) {
            const t = this.toasts.find(t => t.id === id);
            if (!t) return;
            t.show = false;
            setTimeout(() => this.toasts = this.toasts.filter(t => t.id !== id), 260);
        },
     }"
     x-init="
        window.addEventListener('toast', e => add(e.detail?.message ?? e.detail?.[0]?.message, e.detail?.kind ?? null));
        if (window.Livewire) window.Livewire.on('toast', p => add(Array.isArray(p) ? p[0]?.message : p?.message, Array.isArray(p) ? p[0]?.kind : p?.kind));
     "
     aria-live="polite"
     class="pointer-events-none fixed inset-x-0 bottom-4 z-[70] flex flex-col items-center gap-2 px-4">
    <template x-for="toast in toasts" :key="toast.id">
        <div x-show="toast.show"
             x-transition:enter="transition duration-500 ease-[cubic-bezier(.2,1.4,.4,1)]"
             x-transition:enter-start="translate-y-6 scale-90 opacity-0"
             x-transition:enter-end="translate-y-0 scale-100 opacity-100"
             x-transition:leave="transition duration-200 ease-in"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             @click="remove(toast.id)"
             class="pointer-events-auto relative flex min-w-[220px] max-w-md cursor-pointer items-center gap-2.5 overflow-hidden rounded-2xl bg-brand py-3 pl-3 pr-4 text-sm font-medium text-brand-fg shadow-[0_18px_40px_-12px_rgb(21_25_30/.45)] ring-1 ring-white/10"
             :class="toast.kind === 'error' ? '!bg-rose-600' : ''">
            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full"
                  :class="toast.kind === 'error' ? 'bg-white/20' : 'bg-accent text-accent-fg'">
                <svg x-show="toast.kind !== 'error'" class="pm-check h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <svg x-show="toast.kind === 'error'" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M12 7v6M12 17h.01"/></svg>
            </span>
            <span x-text="toast.message"></span>
            <span class="pm-toast-timer absolute inset-x-0 bottom-0 h-0.5 origin-left bg-white/40"></span>
        </div>
    </template>
</div>
