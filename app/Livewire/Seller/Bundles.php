<?php

namespace App\Livewire\Seller;

use App\Models\Bundle;
use App\Models\Product;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.seller')]
class Bundles extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $title = '';

    public string $discount_type = 'percentage';

    public ?float $discount_value = null;

    /** @var array<int, array{product_id:?int, quantity:int}> */
    public array $lines = [];

    public function rules(): array
    {
        // A fixed discount can't be more than the bundle is worth.
        $maxFixed = max(0.01, round($this->pricePreview()['full'] - 0.01, 2));

        return [
            'title' => 'required|string|max:120',
            'discount_type' => 'required|in:percentage,fixed',
            'discount_value' => ['required', 'numeric', 'min:0.01', $this->discount_type === 'percentage' ? 'max:80' : 'max:'.$maxFixed],
            'lines' => 'array|min:2',
            'lines.*.product_id' => 'required|integer|distinct',
            'lines.*.quantity' => 'required|integer|min:1|max:50',
        ];
    }

    protected function messages(): array
    {
        return [
            'lines.min' => 'A bundle needs at least 2 different products.',
            'lines.*.product_id.distinct' => 'That product is already in the bundle — change its quantity instead.',
            'lines.*.product_id.required' => 'Choose a listing for every line.',
            'discount_value.max' => $this->discount_type === 'percentage'
                ? 'The discount can be at most 80%.'
                : 'The discount must be less than the bundle\'s full price.',
        ];
    }

    /** Live price of the bundle being built, so the seller sees exactly what buyers will see. */
    protected function pricePreview(): array
    {
        $ids = collect($this->lines)->pluck('product_id')->filter()->map(fn ($id) => (int) $id);
        $products = Product::where('seller_id', auth()->id())->whereIn('id', $ids)->get()->keyBy('id');

        $full = round(collect($this->lines)->sum(function ($l) use ($products) {
            $p = $products[(int) ($l['product_id'] ?? 0)] ?? null;

            return $p ? $p->effectivePrice() * max(1, (int) ($l['quantity'] ?? 1)) : 0;
        }), 2);

        $value = (float) ($this->discount_value ?? 0);
        $off = $this->discount_type === 'percentage' ? $full * $value / 100 : $value;
        $price = round(max(0, $full - $off), 2);

        return ['full' => $full, 'price' => $price, 'savings' => round($full - $price, 2)];
    }

    public function newBundle(): void
    {
        $this->reset('editingId', 'title', 'discount_value');
        $this->discount_type = 'percentage';
        $this->lines = [['product_id' => null, 'quantity' => 1], ['product_id' => null, 'quantity' => 1]];
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $b = $this->owned($id);
        $this->editingId = $b->id;
        $this->title = $b->title;
        $this->discount_type = $b->discount_type;
        $this->discount_value = (float) $b->discount_value;
        $this->lines = $b->items->map(fn ($i) => ['product_id' => $i->product_id, 'quantity' => $i->quantity])->all();
        $this->showForm = true;
    }

    public function addLine(): void
    {
        $this->lines[] = ['product_id' => null, 'quantity' => 1];
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    public function save(): void
    {
        $data = $this->validate();

        $ownedIds = Product::where('seller_id', auth()->id())->pluck('id');
        foreach ($this->lines as $line) {
            abort_unless($ownedIds->contains($line['product_id']), 403);
        }

        $bundle = $this->editingId
            ? $this->owned($this->editingId)
            : new Bundle(['seller_id' => auth()->id()]);

        $bundle->fill([
            'title' => $data['title'],
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
        ])->save();

        $bundle->items()->delete();
        foreach ($this->lines as $line) {
            $bundle->items()->create(['product_id' => $line['product_id'], 'quantity' => $line['quantity']]);
        }

        $this->showForm = false;
        $this->dispatch('toast', message: 'Bundle saved');
    }

    public function toggle(int $id): void
    {
        $b = $this->owned($id);
        $b->update(['is_active' => ! $b->is_active]);
    }

    public function delete(int $id): void
    {
        $this->owned($id)->delete();
        $this->dispatch('toast', message: 'Bundle deleted');
    }

    private function owned(int $id): Bundle
    {
        return Bundle::where('seller_id', auth()->id())->with('items', 'products')->findOrFail($id);
    }

    public function render()
    {
        // Listings already in the bundle stay selectable even if they've since been paused,
        // so editing never silently blanks a line.
        $inBundle = collect($this->lines)->pluck('product_id')->filter();

        return view('livewire.seller.bundles', [
            'bundles' => Bundle::where('seller_id', auth()->id())->with('products')->latest()->get(),
            'products' => Product::where('seller_id', auth()->id())
                ->where(fn ($q) => $q->where('status', 'active')->orWhereIn('id', $inBundle))
                ->orderBy('title')->get(),
            'preview' => $this->showForm ? $this->pricePreview() : null,
        ]);
    }
}
