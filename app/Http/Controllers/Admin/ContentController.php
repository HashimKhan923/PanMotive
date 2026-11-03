<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Support\PlatformSettings;
use Illuminate\Http\Request;

class ContentController extends Controller
{
    use AdminTools;

    public function index()
    {
        return view('admin.content.index');
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'announcement' => 'nullable|string|max:200',
            'announcement_link' => ['nullable', 'string', 'max:255', 'regex:#^(https?://|/)#'],
            'announcement_tone' => 'required|in:accent,info,warning,danger',
            'announcement_until' => 'nullable|date',
            'support_email' => 'nullable|email|max:255',
        ], ['announcement_link.regex' => 'The link must start with https:// or /.']);

        $values = [];
        foreach ($data as $key => $value) {
            $values["marketplace.platform.{$key}"] = $value ?? '';
        }

        $changes = PlatformSettings::save($values, $request->user()->id);
        if ($changes) {
            $this->audit('Changed site announcement', null, $changes);
        }

        return back()->with('success', 'Saved. It is live on the site now.');
    }

    public function updateHome(Request $request)
    {
        $data = $request->validate([
            'hero_badge' => 'nullable|string|max:80',
            'hero_line_1' => 'required|string|max:40',
            'hero_line_2' => 'required|string|max:40',
            'hero_accent' => 'nullable|string|max:30',
            'hero_text' => 'required|string|max:300',
            'hero_point_1' => 'nullable|string|max:60',
            'hero_point_2' => 'nullable|string|max:60',
            'hero_point_3' => 'nullable|string|max:60',
        ]);

        $values = [];
        foreach ($data as $key => $value) {
            $values["marketplace.platform.{$key}"] = $value ?? '';
        }

        $changes = PlatformSettings::save($values, $request->user()->id);
        if ($changes) {
            $this->audit('Changed homepage text', null, $changes);
        }

        return back()->with('success', 'Homepage updated.');
    }
}
