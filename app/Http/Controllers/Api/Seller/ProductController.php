<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::where('seller_id', $request->user()->id)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('id')
            ->paginate(min(100, (int) $request->integer('per_page', 50)));

        return response()->json($products->through(fn ($p) => $this->transform($p)));
    }

    public function show(Request $request, Product $product)
    {
        $this->authorizeOwner($request, $product);

        return response()->json($this->transform($product));
    }

    public function update(Request $request, Product $product)
    {
        $this->authorizeOwner($request, $product);

        $data = $request->validate([
            'price' => 'sometimes|numeric|min:0.01',
            'sale_price' => 'sometimes|nullable|numeric|lt:price',
            'quantity' => 'sometimes|integer|min:0',
            'status' => 'sometimes|in:active,archived',
        ]);

        $product->update($data);

        return response()->json($this->transform($product->fresh()));
    }

    private function authorizeOwner(Request $request, Product $product): void
    {
        abort_unless($product->seller_id === $request->user()->id, 404);
    }

    private function transform(Product $p): array
    {
        return [
            'id' => $p->id,
            'title' => $p->title,
            'sku' => $p->sku,
            'part_number' => $p->part_number,
            'price' => (float) $p->price,
            'sale_price' => $p->sale_price !== null ? (float) $p->sale_price : null,
            'quantity' => $p->quantity,
            'status' => $p->status,
            'url' => route('products.show', $p->slug),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }
}
