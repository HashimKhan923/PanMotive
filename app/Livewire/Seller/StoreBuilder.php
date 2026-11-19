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
    public string $accent = '#E51B2B';

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

    public $logo;

    public function mount(): void
    {
        $profile = auth()->user()->sellerProfile;
        $theme = $profile->theme ?? [];

        $this->accent = $theme['accent'] ?? '#E51B2B';
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
        $this->validate([
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048|dimensions:min_width=100,min_height=100',
            'banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:6144',
        ], [
            'logo.dimensions' => 'The logo should be at least 100 × 100 pixels.',
            'logo.max' => 'The logo must be 2 MB or smaller.',
        ]);

        $profile = auth()->user()->sellerProfile;
        $disk = \Illuminate\Support\Facades\Storage::disk('public');

        $bannerPath = $profile->banner_path;
        if ($this->banner) {
            $bannerPath = $this->banner->store('store-banners/'.auth()->id(), 'public');
            if ($profile->banner_path && $profile->banner_path !== $bannerPath) {
                $disk->delete($profile->banner_path);
            }
        }

        $logoPath = $profile->logo_path;
        if ($this->logo) {
            $logoPath = $this->logo->store('store-logos/'.auth()->id(), 'public');
            if ($profile->logo_path && $profile->logo_path !== $logoPath) {
                $disk->delete($profile->logo_path);
            }
        }

        $ownedFeatured = Product::where('seller_id', auth()->id())->whereIn('id', $this->featured)->pluck('id')->all();

        $profile->update([
            'banner_path' => $bannerPath,
            'logo_path' => $logoPath,
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
        $this->logo = null;
        $this->dispatch('toast', message: 'Storefront updated');
    }

    public function removeLogo(): void
    {
        $profile = auth()->user()->sellerProfile;
        if ($profile->logo_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($profile->logo_path);
            $profile->update(['logo_path' => null]);
        }
        $this->logo = null;
        $this->dispatch('toast', message: 'Logo removed');
    }

    public function render()
    {
        return view('livewire.seller.store-builder', [
            'profile' => auth()->user()->sellerProfile,
            'products' => Product::where('seller_id', auth()->id())->where('status', 'active')->orderBy('title')->get(['id', 'title']),
        ]);
    }
}
