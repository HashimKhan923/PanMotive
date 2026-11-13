<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;

/**
 * Serves a seller's own product photo and logo from the seller portal's address, so the
 * Social studio image maker can draw them on a canvas (browsers block reading pixels of
 * images from another address, and photos live on the shop's address).
 */
class SocialImageController extends Controller
{
    public function product(Product $product)
    {
        abort_unless($product->seller_id === auth()->id(), 404);
        $media = $product->getFirstMedia('images');
        abort_unless($media && is_file($media->getPath()), 404);

        return response()->file($media->getPath(), ['Cache-Control' => 'private, max-age=3600']);
    }

    public function logo()
    {
        $path = auth()->user()->sellerProfile?->logo_path;
        abort_unless($path && \Illuminate\Support\Facades\Storage::disk('public')->exists($path), 404);

        return response()->file(\Illuminate\Support\Facades\Storage::disk('public')->path($path), ['Cache-Control' => 'private, max-age=3600']);
    }
}
