<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Support\PlatformSettings;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    use AdminTools;

    public function index()
    {
        return view('admin.settings.index', ['registry' => PlatformSettings::registry()]);
    }

    /** One group at a time, so saving fees can never overwrite a switch someone just changed. */
    public function update(Request $request)
    {
        $registry = PlatformSettings::registry();
        $request->validate(['group' => 'required|in:'.implode(',', array_keys($registry))]);
        $fields = $registry[$request->input('group')]['fields'];

        $rules = [];
        foreach ($fields as $key => $field) {
            $rules['settings.'.self::inputName($key)] = match ($field['type']) {
                'bool' => 'nullable|boolean',
                'int' => "required|integer|min:{$field['min']}|max:{$field['max']}",
                'number' => "required|numeric|min:{$field['min']}|max:{$field['max']}",
                default => 'nullable|string|max:500',
            };
        }
        $labels = collect($fields)->mapWithKeys(fn ($f, $key) => ['settings.'.self::inputName($key) => $f['label']])->all();
        $input = $request->validate($rules, [], $labels)['settings'] ?? [];

        $values = [];
        foreach ($fields as $key => $field) {
            $values[$key] = $input[self::inputName($key)] ?? ($field['type'] === 'bool' ? false : '');
        }

        $changes = PlatformSettings::save($values, $request->user()->id);
        foreach ($changes as $key => $change) {
            $this->audit("Changed setting: {$fields[$key]['label']}", null, $change + ['key' => $key]);
        }

        return back()->with('success', $changes ? 'Saved. '.count($changes).' '.\Illuminate\Support\Str::plural('setting', count($changes)).' changed.' : 'Nothing changed.');
    }

    /** Laravel reads dots in field names as nesting, and settings keys are full of them. */
    public static function inputName(string $key): string
    {
        return str_replace('.', '__', $key);
    }
}
