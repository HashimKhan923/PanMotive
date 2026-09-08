<?php

namespace App\Livewire\Seller;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\AI\ListingAssistantService;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.seller')]
class ProductForm extends Component
{
    use WithFileUploads;

    public ?Product $product = null;

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('required|exists:categories,id')]
    public ?int $category_id = null;

    public ?int $brand_id = null;

    #[Validate('nullable|string|max:100')]
    public string $part_number = '';

    #[Validate('nullable|string|max:100')]
    public string $oem_number = '';

    #[Validate('required|in:new,used,refurbished')]
    public string $condition = 'new';

    #[Validate('required|numeric|min:0.01')]
    public float $price = 0;

    #[Validate('nullable|numeric')]
    public ?float $sale_price = null;

    #[Validate('required|integer|min:0')]
    public int $quantity = 0;

    public string $description = '';

    public array $newImages = [];

    public array $fitmentVehicleIds = [];

    public function mount(?Product $product = null): void
    {
        if ($product?->exists) {
            abort_unless($product->seller_id === auth()->id(), 403);

            $this->product = $product;
            $this->title = $product->title;
            $this->category_id = $product->category_id;
            $this->brand_id = $product->brand_id;
            $this->part_number = $product->part_number ?? '';
            $this->oem_number = $product->oem_number ?? '';
            $this->condition = $product->condition;
            $this->price = (float) $product->price;
            $this->sale_price = $product->sale_price !== null ? (float) $product->sale_price : null;
            $this->quantity = $product->quantity;
            $this->description = $product->description ?? '';
            $this->fitmentVehicleIds = $product->fitments->pluck('id')->all();
        }
    }

    /** Kept in sync by the nested FitmentPicker component. */
    #[On('fitment-updated')]
    public function setFitments(array $ids): void
    {
        $this->fitmentVehicleIds = array_values(array_unique(array_map('intval', $ids)));
    }

    /** AI-assisted: generate title/description/bullets from a part number. */
    public function generateWithAi(ListingAssistantService $ai): void
    {
        $this->validate(['part_number' => 'required|string']);

        $result = $ai->generateListingDraft($this->part_number, $this->category_id);

        $this->title = $result['title'] ?? $this->title;
        $this->description = $result['description'] ?? $this->description;
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'seller_id' => auth()->id(),
            'title' => $this->title,
            'slug' => Str::slug($this->title).'-'.Str::random(6),
            'category_id' => $this->category_id,
            'brand_id' => $this->brand_id,
            'part_number' => $this->part_number ?: null,
            'oem_number' => $this->oem_number ?: null,
            'condition' => $this->condition,
            'price' => $this->price,
            'sale_price' => $this->sale_price,
            'quantity' => $this->quantity,
            'description' => $this->description ?: null,
            'status' => 'pending_review',
        ];

        if ($this->product) {
            $this->product->update($data);
            $product = $this->product;
        } else {
            $product = Product::create($data);
        }

        $product->fitments()->sync($this->fitmentVehicleIds);

        foreach ($this->newImages as $image) {
            $product->addMedia($image->getRealPath())
                ->usingFileName($image->getClientOriginalName())
                ->toMediaCollection('images');
        }

        session()->flash('success', 'Product saved and submitted for review.');
        $this->redirect(route('seller.products.index'), navigate: false);
    }

    public function render()
    {
        return view('livewire.seller.product-form', [
            'categories' => Category::orderBy('name')->get(),
            'brands' => Brand::orderBy('name')->get(),
        ]);
    }
}
