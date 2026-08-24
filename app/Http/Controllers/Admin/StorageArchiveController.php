<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StorageManagementLog;
use App\Services\StorageUsageService;
use Illuminate\View\View;

class StorageArchiveController extends Controller
{
    public function show(StorageUsageService $usage): View
    {
        if (request()->user()?->isTenantAdmin()) {
            abort(403, __('ストレージ監視は運営のみ利用できます。'));
        }
        $snap = $usage->snapshot();
        $logs = StorageManagementLog::query()->orderByDesc('id')->limit(20)->get();

        return view('admin.storage-archive', [
            'usage' => $snap,
            'logs' => $logs,
        ]);
    }
}
