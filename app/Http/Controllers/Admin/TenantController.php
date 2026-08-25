<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantContractService;
use App\Support\CommercialOffer;
use Illuminate\Http\Request;
use InvalidArgumentException;

class TenantController extends Controller
{
    use RedirectsWithFlash;

    public function __construct(private TenantContractService $contracts) {}

    public function index(Request $request)
    {
        $tenants = Tenant::query()
            ->with('owner')
            ->withCount('users')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Tenant $tenant) => $tenant->toPublicArray());

        return view('admin.tenants.index', array_merge($this->flashFromQuery($request), [
            'tenants' => $tenants,
            'includedUsers' => CommercialOffer::includedUsers(),
            'extraUserYen' => CommercialOffer::extraUserYen(),
            'tenantMonthlyYen' => CommercialOffer::tenantMonthlyYen(),
            'tenantYearlyYen' => CommercialOffer::tenantYearlyYen(),
            'tenantTrialDays' => CommercialOffer::tenantTrialDays(),
            'defaultTrialEndsAt' => CommercialOffer::defaultTrialEndsAt(),
        ]));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'max_users' => ['required', 'integer', 'min:1', 'max:200'],
            'allow_own_keys' => ['nullable', 'boolean'],
            'trial_ends_at' => ['nullable', 'date'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_display_name' => ['required', 'string', 'max:100'],
            'owner_password' => ['required', 'string', 'min:8'],
        ]);

        try {
            $this->contracts->createWithOwner([
                'name' => $data['name'],
                'notes' => $data['notes'] ?? null,
                'max_users' => (int) $data['max_users'],
                'allow_own_keys' => $request->boolean('allow_own_keys', true),
                'trial_ends_at' => $request->filled('trial_ends_at') ? $data['trial_ends_at'] : ($request->has('trial_ends_at') ? null : false),
                'owner_email' => $data['owner_email'],
                'owner_display_name' => $data['owner_display_name'],
                'owner_password' => $data['owner_password'],
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->redirectWithMessage('/admin/tenants', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/admin/tenants', __('テナント契約を作成しました。'));
    }

    public function show(Request $request, int $id)
    {
        $tenant = Tenant::query()->with(['owner', 'users'])->findOrFail($id);

        return view('admin.tenants.show', array_merge($this->flashFromQuery($request), [
            'tenant' => $tenant->toPublicArray(),
            'members' => $tenant->users->map->toPublicArray(),
            'includedUsers' => CommercialOffer::includedUsers(),
            'extraUserYen' => CommercialOffer::extraUserYen(),
            'tenantMonthlyYen' => CommercialOffer::tenantMonthlyYen(),
            'tenantTrialDays' => CommercialOffer::tenantTrialDays(),
        ]));
    }

    public function update(Request $request, int $id)
    {
        $tenant = Tenant::query()->findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'max_users' => ['required', 'integer', 'min:1', 'max:200'],
            'allow_own_keys' => ['nullable', 'boolean'],
            'status' => ['required', 'in:active,suspended'],
            'trial_ends_at' => ['nullable', 'date'],
        ]);

        try {
            $this->contracts->update($tenant, [
                'name' => $data['name'],
                'notes' => $data['notes'] ?? null,
                'max_users' => (int) $data['max_users'],
                'allow_own_keys' => $request->boolean('allow_own_keys'),
                'status' => $data['status'],
                'trial_ends_at' => $data['trial_ends_at'] ?? null,
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->redirectWithMessage("/admin/tenants/{$id}", $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage("/admin/tenants/{$id}", __('テナント契約を更新しました。'));
    }
}
