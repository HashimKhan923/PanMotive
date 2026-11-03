{!! '<?xml version="1.0" encoding="UTF-8"?>'."\n" !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>{{ route('home') }}</loc>
    </url>
    @foreach ($categories as $cat)
        <url>
            <loc>{{ route('products.index') }}?category={{ $cat->slug }}</loc>
            <lastmod>{{ $cat->updated_at->toAtomString() }}</lastmod>
        </url>
    @endforeach
    @foreach ($pages ?? [] as $page)
        <url>
            <loc>{{ route('pages.show', $page->slug) }}</loc>
            <lastmod>{{ $page->updated_at->toAtomString() }}</lastmod>
        </url>
    @endforeach
    @foreach ($products as $product)
        <url>
            <loc>{{ route('products.show', $product->slug) }}</loc>
            <lastmod>{{ $product->updated_at->toAtomString() }}</lastmod>
        </url>
    @endforeach
</urlset>
