<div>
    @if ($label)
        <div class="border-b border-line bg-surface-2">
            <div class="container-x flex flex-wrap items-center gap-x-4 gap-y-1 py-2 text-sm">
                <span class="inline-flex items-center gap-2 font-medium">
                    <x-ui.icon name="wrench" class="w-4 h-4 text-accent" />
                    Shopping for <span class="font-semibold">{{ $label }}</span>
                </span>
                <a href="{{ route('products.index', ['vehicleId' => $vehicleId]) }}" class="text-accent hover:underline">Shop parts that fit</a>
                <button wire:click="clear" class="text-muted hover:text-fg">Clear</button>
            </div>
        </div>
    @endif
</div>
