<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;

/**
 * Serves a seller's own product photo and logo from the seller portal's address, so the
 * Social studio image maker can draw them on a canvas (browsers block reading pixels of
 * images from another address, and photos live on the shop's or the media address).
 * Works whether the files are on this server or in cloud storage.
 */
class SocialImageController extends Controller
{
    private const HEADERS = ['Cache-Control' => 'private, max-age=3600'];

    public function product(Product $product)
    {
        abort_unless($product->seller_id === auth()->id(), 404);
        $media = $product->getFirstMedia('images');
        $path = $media?->getPathRelativeToRoot();
        abort_unless($path && Storage::disk($media->disk)->exists($path), 404);

        return Storage::disk($media->disk)->response($path, null, self::HEADERS);
    }

    public function logo()
    {
        $path = auth()->user()->sellerProfile?->logo_path;
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, self::HEADERS);
    }

    /** An image the seller uploaded into a design (only their own folder). */
    public function upload(string $path)
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{20,60}\.(jpg|jpeg|png|webp|gif)$/', $path), 404);
        $file = 'social/'.auth()->id().'/uploads/'.$path;
        abort_unless(Storage::disk('public')->exists($file), 404);

        return Storage::disk('public')->response($file, null, self::HEADERS);
    }
}
