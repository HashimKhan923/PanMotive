<div class="max-w-xl">
    <h1 class="mb-2 text-xl font-bold">Bulk upload</h1>
    <p class="mb-6 text-sm text-slate-600">
        Upload a CSV with these columns:
        <code class="rounded bg-slate-100 px-1">title, category_id, sku, part_number, oem_number, condition, price, quantity, description</code>.
        Products are created as <strong>pending review</strong>.
    </p>

    @if (session('success'))
        <p class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</p>
    @endif

    <form wire:submit="upload" class="space-y-4">
        <input type="file" wire:model="csvFile" accept=".csv,text/csv" class="w-full">
        <div wire:loading wire:target="csvFile" class="text-xs text-slate-400">Uploading…</div>
        @error('csvFile') <p class="text-xs text-red-500">{{ $message }}</p> @enderror

        <button class="rounded-lg bg-amber-500 px-6 py-3 font-semibold text-slate-900 transition hover:bg-amber-400"
                wire:loading.attr="disabled" wire:target="upload">
            Upload &amp; process
        </button>
    </form>
</div>
