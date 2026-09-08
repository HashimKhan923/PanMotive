<div class="max-w-3xl">
    <h1 class="mb-6 text-xl font-bold">{{ $product ? 'Edit product' : 'New product' }}</h1>

    <form wire:submit="save" class="space-y-5">
        <div class="flex gap-3 items-end">
            <div class="flex-1">
                <label class="text-sm font-medium">Part Number</label>
                <input wire:model="part_number" class="w-full rounded-lg border-slate-300 mt-1">
            </div>
            <button type="button" wire:click="generateWithAi" wire:loading.attr="disabled"
                    class="bg-slate-900 text-white text-sm rounded-lg px-4 py-2.5 whitespace-nowrap">
                <span wire:loading.remove wire:target="generateWithAi">✨ Generate with AI</span>
                <span wire:loading wire:target="generateWithAi">Generating...</span>
            </button>
        </div>

        <div>
            <label class="text-sm font-medium">Title</label>
            <input wire:model="title" class="w-full rounded-lg border-slate-300 mt-1">
            @error('title') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="text-sm font-medium">Category</label>
                <select wire:model="category_id" class="w-full rounded-lg border-slate-300 mt-1">
                    <option value="">Select...</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
                @error('category_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="text-sm font-medium">Brand</label>
                <select wire:model="brand_id" class="w-full rounded-lg border-slate-300 mt-1">
                    <option value="">Select...</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="text-sm font-medium">OEM Number</label>
                <input wire:model="oem_number" class="w-full rounded-lg border-slate-300 mt-1">
            </div>
            <div>
                <label class="text-sm font-medium">Condition</label>
                <select wire:model="condition" class="w-full rounded-lg border-slate-300 mt-1">
                    <option value="new">New</option>
                    <option value="used">Used</option>
                    <option value="refurbished">Refurbished</option>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-3 gap-4">
            <div>
                <label class="text-sm font-medium">Price</label>
                <input type="number" step="0.01" wire:model="price" class="w-full rounded-lg border-slate-300 mt-1">
                @error('price') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="text-sm font-medium">Sale Price</label>
                <input type="number" step="0.01" wire:model="sale_price" class="w-full rounded-lg border-slate-300 mt-1">
            </div>
            <div>
                <label class="text-sm font-medium">Quantity</label>
                <input type="number" wire:model="quantity" class="w-full rounded-lg border-slate-300 mt-1">
                @error('quantity') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="text-sm font-medium">Description</label>
            <textarea wire:model="description" rows="6" class="w-full rounded-lg border-slate-300 mt-1"></textarea>
        </div>

        <div>
            <label class="text-sm font-medium">Photos</label>
            <input type="file" wire:model="newImages" multiple class="w-full mt-1">
            <div wire:loading wire:target="newImages" class="text-xs text-slate-400">Uploading...</div>
        </div>

        {{-- Fitment vehicle assignment. Simple picker for MVP; swap in a proper
             tag-picker UI in the Phase 8 design pass. --}}
        <div>
            <label class="text-sm font-medium">Vehicle Fitment</label>
            @livewire('seller.fitment-picker', ['selected' => $fitmentVehicleIds], key('fitment-picker'))
        </div>

        <button class="bg-amber-500 hover:bg-amber-400 text-slate-900 font-semibold rounded-lg px-6 py-3 transition">
            Save &amp; Submit for Review
        </button>
    </form>
</div>
