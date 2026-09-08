<form wire:submit="findParts" class="mx-auto flex max-w-2xl flex-wrap gap-2 rounded-2xl bg-white/10 p-2 backdrop-blur">
    <label class="sr-only" for="vs-make">Vehicle make</label>
    <select id="vs-make" wire:model.live="makeId"
            class="flex-1 rounded-lg border-0 bg-white/90 px-3 py-2.5 text-sm text-slate-900">
        <option value="">Make</option>
        @foreach ($makes as $make)
            <option value="{{ $make->id }}">{{ $make->name }}</option>
        @endforeach
    </select>

    <label class="sr-only" for="vs-model">Vehicle model</label>
    <select id="vs-model" wire:model.live="modelId" @disabled($models->isEmpty())
            class="flex-1 rounded-lg border-0 bg-white/90 px-3 py-2.5 text-sm text-slate-900 disabled:opacity-50">
        <option value="">Model</option>
        @foreach ($models as $model)
            <option value="{{ $model->id }}">{{ $model->name }}</option>
        @endforeach
    </select>

    <label class="sr-only" for="vs-year">Vehicle year</label>
    <select id="vs-year" wire:model="year" @disabled($years->isEmpty())
            class="w-28 rounded-lg border-0 bg-white/90 px-3 py-2.5 text-sm text-slate-900 disabled:opacity-50">
        <option value="">Year</option>
        @foreach ($years as $y)
            <option value="{{ $y }}">{{ $y }}</option>
        @endforeach
    </select>

    <button class="rounded-lg bg-amber-500 px-5 py-2.5 text-sm font-semibold text-slate-900 hover:bg-amber-400">
        Find Parts
    </button>
</form>
