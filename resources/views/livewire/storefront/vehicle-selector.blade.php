<form wire:submit="findParts" class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
    <label class="sr-only" for="vs-make">Vehicle make</label>
    <select id="vs-make" wire:model.live="makeId"
            class="rounded-xl border-0 bg-white px-3 py-3 text-sm font-medium text-slate-900 focus:ring-2 focus:ring-accent">
        <option value="">Make</option>
        @foreach ($makes as $make)
            <option value="{{ $make->id }}">{{ $make->name }}</option>
        @endforeach
    </select>

    <label class="sr-only" for="vs-model">Vehicle model</label>
    <select id="vs-model" wire:model.live="modelId" @disabled($models->isEmpty())
            class="rounded-xl border-0 bg-white px-3 py-3 text-sm font-medium text-slate-900 focus:ring-2 focus:ring-accent disabled:bg-white/60">
        <option value="">Model</option>
        @foreach ($models as $model)
            <option value="{{ $model->id }}">{{ $model->name }}</option>
        @endforeach
    </select>

    <label class="sr-only" for="vs-year">Vehicle year</label>
    <select id="vs-year" wire:model.live="year" @disabled($years->isEmpty())
            class="rounded-xl border-0 bg-white px-3 py-3 text-sm font-medium text-slate-900 focus:ring-2 focus:ring-accent disabled:bg-white/60">
        <option value="">Year</option>
        @foreach ($years as $y)
            <option value="{{ $y }}">{{ $y }}</option>
        @endforeach
    </select>

    <label class="sr-only" for="vs-engine">Vehicle engine</label>
    <select id="vs-engine" wire:model="engineId" @disabled($engines->isEmpty())
            class="rounded-xl border-0 bg-white px-3 py-3 text-sm font-medium text-slate-900 focus:ring-2 focus:ring-accent disabled:bg-white/60">
        <option value="">{{ $engines->isEmpty() ? 'Engine (any)' : 'Engine' }}</option>
        @foreach ($engines as $engine)
            <option value="{{ $engine->id }}">{{ $engine->name }}</option>
        @endforeach
    </select>

    <button class="btn-accent !py-3">
        <span wire:loading.remove wire:target="findParts">Find Parts</span>
        <span wire:loading wire:target="findParts">Searching…</span>
    </button>
</form>
