<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Homepage banners: what they say, how they look, where they sit and when they show. */
class BannerController extends Controller
{
    use AdminTools;

    public function index()
    {
        return view('admin.banners.index', [
            'banners' => Banner::orderBy('placement')->orderBy('sort_order')->orderByDesc('id')->get(),
        ]);
    }

    public function create()
    {
        return view('admin.banners.form', ['banner' => new Banner(['style' => 'charcoal', 'placement' => 'below_categories', 'is_active' => true, 'sort_order' => 0])]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('banners', 'public');
        }

        $banner = Banner::create($data + ['updated_by' => $request->user()->id]);
        $this->audit('Created homepage banner', $banner, ['title' => $banner->title]);

        return redirect()->route('admin.banners.edit', $banner)->with('success', 'Banner created.');
    }

    public function edit(Banner $banner)
    {
        return view('admin.banners.form', compact('banner'));
    }

    public function update(Request $request, Banner $banner)
    {
        $data = $this->validated($request);
        $old = $banner->image_path;

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('banners', 'public');
        } elseif ($request->boolean('remove_image')) {
            $data['image_path'] = null;
        }

        $banner->update($data + ['updated_by' => $request->user()->id]);

        if ($old && $old !== $banner->image_path) {
            Storage::disk('public')->delete($old);
        }

        $this->audit('Edited homepage banner', $banner, ['title' => $banner->title]);

        return redirect()->route('admin.banners.edit', $banner)->with('success', 'Banner saved.');
    }

    public function destroy(Banner $banner)
    {
        $this->audit('Deleted homepage banner', null, ['title' => $banner->title]);

        if ($banner->image_path) {
            Storage::disk('public')->delete($banner->image_path);
        }
        $banner->delete();

        return redirect()->route('admin.banners.index')->with('success', 'Banner deleted.');
    }

    private function validated(Request $request): array
    {
        // A page on this site ("/deals") or a full web address. Never javascript: or other schemes.
        $link = ['nullable', 'string', 'max:500', 'regex:~^(/(?!/)\S*|https?://[^\s/]+\.\S*)$~i'];

        $data = $request->validate([
            'eyebrow' => 'nullable|string|max:40',
            'title' => 'required|string|max:120',
            'subtitle' => 'nullable|string|max:280',
            'button_label' => 'nullable|string|max:30|required_with:button_url',
            'button_url' => [...$link, 'required_with:button_label'],
            'second_label' => 'nullable|string|max:30|required_with:second_url',
            'second_url' => [...$link, 'required_with:second_label'],
            // The form field is "look": a form control named "style" hides the form's own .style in the browser.
            'look' => ['required', Rule::in(array_keys(Banner::STYLES))],
            'placement' => ['required', Rule::in(array_keys(Banner::PLACEMENTS))],
            'sort_order' => 'nullable|integer|min:0|max:999',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:min_width=600,min_height=300',
        ], [
            'button_url.regex' => 'Use a page on this site, like /deals, or a full address starting with https://.',
            'second_url.regex' => 'Use a page on this site, like /deals, or a full address starting with https://.',
            'ends_at.after' => 'The end must be after the start.',
            'image.dimensions' => 'The photo must be at least 600 × 300 pixels.',
        ]);

        $data['style'] = $data['look'];
        unset($data['image'], $data['look']);

        return $data + [
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
