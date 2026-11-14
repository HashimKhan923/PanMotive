<?php

namespace App\Livewire\Seller;

use App\Models\Product;
use App\Models\Review;
use App\Models\SocialDesign;
use App\Services\Marketing\CampaignDesign;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/** The free-hand social image designer: templates, text, shapes, stickers, photos, layers. */
#[Layout('layouts.seller')]
class SocialDesigner extends Component
{
    use WithFileUploads;

    public const SIZES = ['square', 'story', 'wide', 'pin'];

    #[Url]
    public ?int $design = null;

    #[Url]
    public string $size = 'square';

    #[Url]
    public ?int $product = null;

    public string $name = 'Untitled design';

    /** The exported PNG, uploaded by the editor just before saving. */
    public $png = null;

    /** Images the seller adds to a design (kept on our servers). */
    public $upload = null;

    public function mount(): void
    {
        $this->size = in_array($this->size, self::SIZES, true) ? $this->size : 'square';
        if ($this->design) {
            $d = SocialDesign::where('seller_id', auth()->id())->findOrFail($this->design);
            $this->name = $d->name;
            $this->size = $d->size;
        }
    }

    /** Save the design (canvas + PNG). With $thenPost, open the composer with this image attached. */
    public function save(string $canvas, string $size, bool $thenPost = false)
    {
        abort_unless(\App\Support\SellerContext::can('marketing'), 403);
        $this->validate([
            'png' => 'required|image|mimes:png|max:12288',
            'name' => 'required|string|max:80',
        ], ['png.required' => 'The image could not be prepared. Try again.']);
        abort_unless(in_array($size, self::SIZES, true), 422);
        abort_if(strlen($canvas) > 6_000_000, 422, 'This design is too big to save. Remove some uploaded photos.');
        json_decode($canvas);
        abort_if(json_last_error() !== JSON_ERROR_NONE, 422);

        $d = $this->design ? SocialDesign::where('seller_id', auth()->id())->findOrFail($this->design) : new SocialDesign(['seller_id' => auth()->id()]);
        $old = $d->image_path;
        $path = $this->png->storeAs('social/'.auth()->id().'/designs', Str::lower(Str::random(24)).'.png', 'public');
        $d->fill(['name' => $this->name, 'size' => $size, 'canvas' => $canvas, 'image_path' => $path])->save();
        if ($old && $old !== $path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($old);
        }
        $this->design = $d->id;
        $this->size = $size;
        $this->reset('png');

        if ($thenPost) {
            return $this->redirectRoute('seller.social.index', ['tab' => 'compose', 'design' => $d->id]);
        }
        $this->dispatch('toast', message: 'Design saved.');

        return null;
    }

    /** An image the seller adds to the canvas: stored, then handed back to the editor by address. */
    public function updatedUpload(): void
    {
        $this->validate(['upload' => 'image|mimes:jpg,jpeg,png,webp,gif|max:6144']);
        $path = $this->upload->store('social/'.auth()->id().'/uploads', 'public');
        $this->dispatch('designer-image', url: route('seller.social.upload', ['path' => basename($path)]));
        $this->reset('upload');
    }

    public function deleteDesign(int $id)
    {
        $d = SocialDesign::where('seller_id', auth()->id())->findOrFail($id);
        if ($d->image_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($d->image_path);
        }
        $d->delete();

        return $this->redirectRoute('seller.social.design', $this->design === $id ? ['size' => $this->size] : ['design' => $this->design]);
    }

    public function render()
    {
        $sellerId = auth()->id();
        $sp = auth()->user()->sellerProfile;
        $current = $this->design ? SocialDesign::where('seller_id', $sellerId)->find($this->design) : null;

        $products = Product::where('seller_id', $sellerId)->where('status', 'active')->whereHas('media')
            ->with(['media', 'fitments.make', 'fitments.model'])->orderBy('title')->limit(300)->get()
            ->map(fn ($p) => [
                'id' => $p->id, 'title' => $p->title, 'image' => route('seller.social.image', $p),
                'price' => '$'.number_format($p->effectivePrice(), 2),
                'was' => $p->effectivePrice() < (float) $p->price ? '$'.number_format((float) $p->price, 2) : null,
                'off' => $p->effectivePrice() < (float) $p->price && (float) $p->price > 0 ? (int) round((1 - $p->effectivePrice() / (float) $p->price) * 100) : null,
                'fits' => $p->fitments->first() ? trim($p->fitments->first()->year.' '.$p->fitments->first()->make?->name.' '.$p->fitments->first()->model?->name) : null,
            ])->values();

        return view('livewire.seller.social-designer', [
            'products' => $products,
            'reviews' => Review::whereHas('product', fn ($q) => $q->where('seller_id', $sellerId))->where('status', 'published')->where('rating', '>=', 4)
                ->with('user:id,name')->latest()->limit(20)->get()
                ->map(fn ($r) => ['rating' => (int) $r->rating, 'text' => Str::limit((string) $r->body, 200), 'name' => Str::before((string) $r->user?->name, ' ') ?: 'A customer'])->values(),
            'saved' => SocialDesign::where('seller_id', $sellerId)->latest('updated_at')->limit(24)->get(['id', 'name', 'size', 'image_path', 'updated_at']),
            'current' => $current,
            'store' => $sp?->business_name ?? 'My store',
            'logo' => $sp?->logo_path ? route('seller.social.logo') : null,
            'color' => CampaignDesign::brand($sp?->email_brand)['color'],
        ]);
    }
}
