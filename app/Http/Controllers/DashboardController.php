<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\ProjectStatus;
use App\Models\Asset;
use App\Models\Building;
use App\Models\Employee;
use App\Models\MaintenancePlan;
use App\Models\OutboxMessage;
use App\Models\Project;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('dashboard', [
            'buildings' => Building::real()->count(),
            'projects' => Project::where('status', ProjectStatus::Open)->count(),
            'byStatus' => Asset::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'unplaced' => Asset::where('status', '!=', AssetStatus::WrittenOff)->whereNull('floor_id')->count(),
            'overdue' => MaintenancePlan::where('is_active', true)->where('next_due_at', '<', today())->count(),
            'dueSoon' => MaintenancePlan::dueWithin(config('services.alerts.maintenance_days'))->where('next_due_at', '>=', today())->count(),
            'outboxFailed' => OutboxMessage::where('status', 'failed')->count(),
            'lastSync' => Employee::max('synced_at'),
        ]);
    }
}
