<div x-data="{ open: false }" class="fixed bottom-6 right-6 z-50">
    <button @click="open = !open" x-bind:aria-expanded="open" aria-label="Open support chat"
            class="bg-amber-500 hover:bg-amber-400 text-slate-900 rounded-full w-14 h-14 shadow-lg flex items-center justify-center font-bold">
        <span aria-hidden="true">💬</span>
    </button>

    <div x-show="open" x-transition x-cloak role="dialog" aria-label="Support chat"
         class="absolute bottom-16 right-0 w-80 bg-white border rounded-2xl shadow-xl flex flex-col h-96">
        <div class="p-3 border-b font-semibold text-sm">Pan Motive Support</div>
        <div class="flex-1 overflow-y-auto p-3 space-y-2 text-sm">
            @forelse ($messages as $msg)
                <div class="{{ $msg['role'] === 'user' ? 'text-right' : 'text-left' }}">
                    <span class="inline-block px-3 py-2 rounded-xl whitespace-pre-line {{ $msg['role'] === 'user' ? 'bg-amber-500 text-slate-900' : 'bg-slate-100' }}">
                        {{ $msg['content'] }}
                    </span>
                </div>
            @empty
                <p class="text-slate-400">Ask about orders, returns, shipping, or fitment.</p>
            @endforelse
            @if ($thinking)
                <div class="text-left"><span class="inline-block px-3 py-2 rounded-xl bg-slate-100 text-slate-400">Typing...</span></div>
            @endif
        </div>
        <form wire:submit="send" class="p-3 border-t flex gap-2">
            <input wire:model="input" placeholder="Ask a question..." class="flex-1 rounded-lg border-slate-300 text-sm">
            <button class="bg-slate-900 text-white rounded-lg px-3 text-sm">Send</button>
        </form>
    </div>
</div>
