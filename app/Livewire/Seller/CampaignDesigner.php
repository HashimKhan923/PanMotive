<?php

namespace App\Livewire\Seller;

use App\Models\CampaignImage;
use App\Models\CampaignTemplate;
use App\Models\Coupon;
use App\Models\MarketingCampaign;
use App\Models\Product;
use App\Services\Marketing\CampaignDesign;
use App\Services\Marketing\CampaignRenderer;
use App\Services\Marketing\MarketingCopyService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/** The drag-and-drop email designer for one campaign. */
#[Layout('layouts.seller')]
class CampaignDesigner extends Component
{
    use WithFileUploads;

    public int $campaignId;

    public array $blocks = [];

    public array $brand = [];

    public string $subject = '';

    public string $preview = '';

    public bool $choosing = false;

    public $photo = null;

    public ?string $photoFor = null;

    public string $templateName = '';

    public int $previewVersion = 0;

    public function mount(MarketingCampaign $campaign): void
    {
        abort_unless($campaign->seller_id === auth()->id() && $campaign->channel === 'email', 404);
        if (! in_array($campaign->status, ['draft', 'scheduled', 'failed'], true)) {
            $this->redirectRoute('seller.campaigns.report', $campaign);

            return;
        }

        $this->campaignId = $campaign->id;
        $this->blocks = $campaign->design ?? [];
        $this->choosing = $this->blocks === [];
        $this->subject = (string) $campaign->subject;
        $this->preview = (string) $campaign->preview;
        $this->brand = CampaignDesign::brand(auth()->user()->sellerProfile?->email_brand);
    }

    private function campaign(): MarketingCampaign
    {
        return MarketingCampaign::where('seller_id', auth()->id())->findOrFail($this->campaignId);
    }

    public function useStarter(string $key): void
    {
        $starter = CampaignDesign::starters()[$key] ?? abort(404);
        $this->blocks = $starter['design'];
        $this->choosing = false;
        $this->save();
    }

    public function useSaved(int $id): void
    {
        $t = CampaignTemplate::where('seller_id', auth()->id())->findOrFail($id);
        $this->blocks = collect($t->design)->map(fn ($b) => ['id' => 'b'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8))] + $b)->all();
        $this->choosing = false;
        $this->save();
    }

    public function deleteTemplate(int $id): void
    {
        CampaignTemplate::where('seller_id', auth()->id())->whereKey($id)->delete();
        $this->dispatch('toast', message: 'Template deleted.');
    }

    /** Autosave, called by the builder after every change. */
    public function save(?array $blocks = null, ?array $brand = null): void
    {
        abort_unless(\App\Support\SellerContext::can('marketing'), 403);
        $this->blocks = CampaignDesign::sanitize($blocks ?? $this->blocks, auth()->id());
        $this->subject = \Illuminate\Support\Str::limit(trim($this->subject), 120, '');
        $this->preview = \Illuminate\Support\Str::limit(trim($this->preview), 150, '');

        $this->campaign()->update(['design' => $this->blocks, 'subject' => $this->subject ?: null, 'preview' => $this->preview ?: null]);

        if ($brand !== null) {
            $this->brand = CampaignDesign::brand($brand);
            auth()->user()->sellerProfile?->update(['email_brand' => $this->brand]);
        }
        $this->previewVersion++;
    }

    public function saveTemplate(): void
    {
        $this->validate(['templateName' => 'required|string|max:80']);
        abort_if(CampaignTemplate::where('seller_id', auth()->id())->count() >= 30, 422, 'You can keep up to 30 templates. Delete one first.');
        CampaignTemplate::create(['seller_id' => auth()->id(), 'name' => $this->templateName, 'design' => collect($this->blocks)->map(fn ($b) => collect($b)->except('id')->all())->all()]);
        $this->templateName = '';
        $this->dispatch('toast', message: 'Saved to your templates.');
    }

    /** Image upload for an image block. Kept on our servers so it shows reliably in every inbox. */
    public function updatedPhoto(): void
    {
        $this->validate(['photo' => 'image|mimes:jpg,jpeg,png,gif,webp|max:3072|dimensions:max_width=4000,max_height=4000']);
        $path = $this->photo->store('campaign-images/'.auth()->id(), 'public');
        $image = CampaignImage::create(['seller_id' => auth()->id(), 'path' => $path, 'size' => (int) $this->photo->getSize()]);
        $this->dispatch('campaign-image', url: $image->url(), block: $this->photoFor);
        $this->reset('photo');
    }

    /** "Write it for me" on a text or heading block. */
    public function writeText(string $goal, ?int $productId = null): array
    {
        $product = $productId ? Product::where('seller_id', auth()->id())->find($productId) : null;
        $out = app(MarketingCopyService::class)->emailCopy($goal, $product, auth()->user()->sellerProfile?->business_name);
        if ($this->subject === '') {
            $this->subject = $out['subject'];
        }
        if ($this->preview === '') {
            $this->preview = $out['preview'];
        }

        return ['heading' => $out['subject'], 'text' => str_replace(['{{name}}', '{{store}}'], ['{first_name}', '{store_name}'], preg_replace('/\{\{shop_url\}\}\s*/', '', $out['body']))];
    }

    public function sendTest(): void
    {
        $this->save();
        $out = app(CampaignRenderer::class)->render($this->campaign(), auth()->user(), null, 'preview');
        \Illuminate\Support\Facades\Mail::mailer(config('marketplace.marketing.campaign_mailer') ?: null)->to(auth()->user()->email)
            ->send(new \App\Mail\DesignedCampaignMail('[TEST] '.$out['subject'], $out['html'], auth()->user()->sellerProfile?->business_name ?? 'Your store'));
        $this->dispatch('toast', message: 'Test sent to '.auth()->user()->email);
    }

    public function render()
    {
        $sellerId = auth()->id();

        return view('livewire.seller.campaign-designer', [
            'campaign' => $this->campaign(),
            'types' => CampaignDesign::types(),
            'starters' => CampaignDesign::starters(),
            'saved' => CampaignTemplate::where('seller_id', $sellerId)->latest()->get(['id', 'name', 'created_at']),
            'issues' => CampaignDesign::quality($this->subject, $this->preview, $this->blocks, $sellerId),
            'catalog' => Product::where('seller_id', $sellerId)->where('status', 'active')->with('media')->orderBy('title')->limit(500)->get()
                ->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'price' => '$'.number_format($p->effectivePrice(), 2),
                    'image' => $p->getFirstMediaUrl('images', 'thumb') ?: null, 'stock' => (int) $p->quantity])->values(),
            'coupons' => Coupon::where('seller_id', $sellerId)->whereNull('user_id')->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->get()
                ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'off' => $c->type === 'percentage' ? rtrim(rtrim(number_format((float) $c->value, 2), '0'), '.').'% off' : '$'.number_format((float) $c->value, 2).' off'])->values(),
            'storeName' => auth()->user()->sellerProfile?->business_name ?? 'Your store',
        ]);
    }
}
