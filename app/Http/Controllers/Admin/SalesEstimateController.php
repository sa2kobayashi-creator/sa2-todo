<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DedicatedInstanceEstimateService;
use Illuminate\Http\Request;

class SalesEstimateController extends Controller
{
    public function __construct(
        private DedicatedInstanceEstimateService $estimates,
    ) {}

    public function show(Request $request)
    {
        $data = $request->validate([
            'client_name' => ['nullable', 'string', 'max:120'],
            'users' => ['nullable', 'integer', 'min:1', 'max:200'],
            'include_mailbox' => ['nullable', 'boolean'],
            'maintenance_cycle' => ['nullable', 'in:monthly,yearly'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $estimate = $this->estimates->build([
            'client_name' => $data['client_name'] ?? '',
            'users' => (int) ($data['users'] ?? config('commercial.included_users', 5)),
            'include_mailbox' => $request->boolean('include_mailbox'),
            'maintenance_cycle' => $data['maintenance_cycle'] ?? 'monthly',
            'notes' => $data['notes'] ?? '',
        ]);

        return view('admin.sales.estimate', [
            'estimate' => $estimate,
            'form' => [
                'client_name' => $estimate['clientName'],
                'users' => $estimate['users'],
                'include_mailbox' => $estimate['includeMailbox'],
                'maintenance_cycle' => $estimate['maintenanceCycle'],
                'notes' => $estimate['notes'],
            ],
        ]);
    }
}
