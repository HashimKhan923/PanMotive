<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Support\Html\DescriptionSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** About, Terms, Privacy, Returns policy and so on, edited without a code change. */
class PageController extends Controller
{
    use AdminTools;

    public function index()
    {
        return view('admin.pages.index', ['pages' => Page::orderBy('title')->paginate(30)]);
    }

    public function create()
    {
        return view('admin.pages.form', ['page' => new Page(['is_published' => false])]);
    }

    public function store(Request $request, DescriptionSanitizer $sanitizer)
    {
        $data = $this->validated($request);
        $data['slug'] = $this->slugFor($data['slug'] ?? '', $data['title']);
        $data['body'] = $sanitizer->sanitize($data['body'] ?? '', 200000);

        $page = Page::create($data + ['updated_by' => $request->user()->id]);
        Cache::forget('footer.pages');
        $this->audit('Created page', $page);

        return redirect()->route('admin.pages.edit', $page)->with('success', 'Page created.');
    }

    public function edit(Page $page)
    {
        return view('admin.pages.form', compact('page'));
    }

    public function update(Request $request, Page $page, DescriptionSanitizer $sanitizer)
    {
        $data = $this->validated($request, $page);
        $data['slug'] = $this->slugFor($data['slug'] ?? '', $data['title'], $page);
        $data['body'] = $sanitizer->sanitize($data['body'] ?? '', 200000);

        $page->update($data + ['updated_by' => $request->user()->id]);
        Cache::forget('footer.pages');
        $this->audit('Edited page', $page);

        return redirect()->route('admin.pages.edit', $page)->with('success', 'Page saved.');
    }

    public function destroy(Page $page)
    {
        $this->audit('Deleted page', null, ['title' => $page->title]);
        $page->delete();
        Cache::forget('footer.pages');

        return redirect()->route('admin.pages.index')->with('success', 'Page deleted.');
    }

    private function validated(Request $request, ?Page $page = null): array
    {
        return $request->validate([
            'title' => 'required|string|max:160',
            'slug' => ['nullable', 'string', 'max:160', 'alpha_dash', Rule::unique('pages', 'slug')->ignore($page?->id)],
            'body' => 'nullable|string|max:200000',
            'meta_description' => 'nullable|string|max:255',
        ]) + [
            'is_published' => $request->boolean('is_published'),
            'show_in_footer' => $request->boolean('show_in_footer'),
        ];
    }

    private function slugFor(string $wanted, string $title, ?Page $page = null): string
    {
        $base = Str::slug($wanted ?: $title) ?: 'page';
        $slug = $base;
        for ($i = 2; Page::where('slug', $slug)->when($page, fn ($q) => $q->whereKeyNot($page->id))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
