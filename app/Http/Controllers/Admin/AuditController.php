<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/** Who did what, and when. Read-only by design: nothing here can be edited or deleted. */
class AuditController extends Controller
{
    use AdminTools;

    public function index(Request $request)
    {
        return view('admin.audit.index', [
            'entries' => $this->filtered($request)->with('causer', 'subject')->latest()->paginate(30)->withQueryString(),
            'staff' => User::whereIn('id', Activity::whereNotNull('causer_id')->distinct()->select('causer_id'))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function export(Request $request)
    {
        $entries = $this->filtered($request)->with('causer')->latest()->limit(50000)->get();

        return response()->streamDownload(function () use ($entries) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['When', 'Who', 'What', 'On', 'Details']);
            foreach ($entries as $e) {
                fputcsv($out, array_map(fn ($v) => $this->csvCell($v), [
                    $e->created_at->toDateTimeString(), $e->causer?->email ?? 'System', $e->description,
                    $e->subject_type ? class_basename($e->subject_type).' #'.$e->subject_id : '',
                    $e->properties->isEmpty() ? '' : $e->properties->toJson(),
                ]));
            }
            fclose($out);
        }, 'audit-log-'.now()->format('Y-m-d').'.csv');
    }

    private function filtered(Request $request)
    {
        return Activity::query()
            ->when($request->filled('q'), fn ($q) => $q->where('description', 'like', $this->like($request->q)))
            ->when($request->filled('causer'), fn ($q) => $q->where('causer_id', $request->integer('causer')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')));
    }
}
