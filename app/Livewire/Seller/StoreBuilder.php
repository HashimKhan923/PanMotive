<?php

namespace App\Livewire\Seller;

use App\Models\Product;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.seller')]
class StoreBuilder extends Component
{
    use WithFileUploads;

    #[Validate('required|regex:/^#[0-9A-Fa-f]{6}$/')]
    public string $accent = '#DD1E25';

    #[Validate('nullable|string|max:80')]
    public string $heroHeadline = '';

    #[Validate('nullable|string|max:180')]
    public string $heroSubtext = '';

    #[Validate('nullable|string|max:2000')]
    public string $about = '';

    public array $featured = [];

    /** @var array<int, array{heading:string, body:string}> */
    public array $sections = [];

    public $banner;

    public function mount(): void
    {
        $profile = auth()->user()->sellerProfile;
        $theme = $profile->theme ?? [];

        $this->accent = $theme['accent'] ?? '#DD1E25';
        $this->heroHeadline = $theme['hero_headline'] ?? '';
        $this->heroSubtext = $theme['hero_subtext'] ?? '';
        $this->about = $theme['about'] ?? ($profile->bio ?? '');
        $this->sections = $theme['sections'] ?? [];
        $this->featured = $profile->featured_product_ids ?? [];
    }

    public function addSection(): void
    {
        $this->sections[] = ['heading' => '', 'body' => ''];
    }

    public function removeSection(int $i): void
    {
        unset($this->sections[$i]);
        $this->sections = array_values($this->sections);
    }

    public function save(): void
    {
        $this->validate();

        $profile = auth()->user()->sellerProfile;
        $bannerPath = $this->banner ? $this->banner->store('store-banners/'.auth()->id(), 'public') : ($profile->banner_path);

        $ownedFeatured = Product::where('seller_id', auth()->id())->whereIn('id', $this->featured)->pluck('id')->all();

        $profile->update([
            'banner_path' => $bannerPath,
            'featured_product_ids' => $ownedFeatured,
            'theme' => [
                'accent' => $this->accent,
                'hero_headline' => $this->heroHeadline ?: null,
                'hero_subtext' => $this->heroSubtext ?: null,
                'about' => $this->about ?: null,
                'sections' => collect($this->sections)
                    ->filter(fn ($s) => filled($s['heading'] ?? null) || filled($s['body'] ?? null))
                    ->values()->all(),
            ],
        ]);

        $this->banner = null;
        $this->dispatch('toast', message: 'Storefront updated');
    }

    public function render()
    {
        return view('livewire.seller.store-builder', [
            'profile' => auth()->user()->sellerProfile,
            'products' => Product::where('seller_id', auth()->id())->where('status', 'active')->orderBy('title')->get(['id', 'title']),
        ]);
    }
}
