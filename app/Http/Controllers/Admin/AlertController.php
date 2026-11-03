<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\AdminAlert;

class AlertController extends Controller
{
    use AdminTools;

    public function index()
    {
        $alerts = AdminAlert::whereNull('resolved_at')->latest()->paginate(20);

        return view('admin.alerts.index', compact('alerts'));
    }

    public function resolve(AdminAlert $alert)
    {
        $alert->update(['resolved_at' => now()]);
        $this->audit('Resolved alert', $alert);

        return back()->with('success', 'Alert resolved.');
    }
}
