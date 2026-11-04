<div>
    @auth
        @if ($sent)
            <p class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                <x-ui.icon name="check" class="w-4 h-4" /> Message sent —
                <a href="{{ route('messages.index') }}" class="underline">view in Messages</a>
            </p>
        @elseif ($open)
            <form wire:submit="send" class="mt-2 space-y-2">
                <textarea wire:model="body" rows="3" class="input text-sm" placeholder="Ask about fitment, condition, shipping…"></textarea>
                @error('body') <p class="text-xs text-rose-500">{{ $message }}</p> @enderror
                <div class="flex gap-2">
                    <button class="btn-accent btn-sm">Send</button>
                    <button type="button" wire:click="$set('open', false)" class="btn-ghost btn-sm">Cancel</button>
                </div>
            </form>
        @else
            <button wire:click="$set('open', true)" class="inline-flex items-center gap-1.5 text-xs font-medium text-muted hover:text-fg">
                <x-ui.icon name="mail" class="w-4 h-4" /> Message seller
            </button>
        @endif
    @else
        <a href="{{ route('login') }}" class="inline-flex items-center gap-1.5 text-xs font-medium text-muted hover:text-fg">
            <x-ui.icon name="mail" class="w-4 h-4" /> Message seller
        </a>
    @endauth
</div>
