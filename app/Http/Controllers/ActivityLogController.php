<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\ActivityLog;

class ActivityLogController extends Controller
{
    public function index()
    {
        // Only Admin can view activity logs
        if (!auth()->user()->isAdmin()) {
            abort(403, 'Vous n\'avez pas les droits pour accéder au journal des événements.');
        }

        $logs = ActivityLog::with('user:id,name,email,role')
            ->latest()
            ->paginate(50);

        return Inertia::render('ActivityLogs/Index', [
            'logs' => $logs
        ]);
    }
}
