<div class="inline-flex items-center gap-2">
    <button wire:click="toggle"
            class="btn-sm transition {{ $following ? 'btn-outline !border-accent !text-accent' : 'btn-accent' }}">
        <x-ui.icon name="{{ $following ? 'check' : 'plus' }}" class="w-4 h-4" />
        {{ $following ? 'Following' : 'Follow store' }}
    </button>
    <span class="text-xs text-muted">{{ number_format($count) }} {{ Str::plural('follower', $count) }}</span>
</div>
