<?php

namespace App\Http\Controllers;

use App\Models\UsageLimitPolicy;
use App\Services\UsageLimitPolicyService;
use Illuminate\Http\Request;

class UsageLimitSettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(private UsageLimitPolicyService $policies) {}

    public function update(Request $request)
    {
        $templates = [];
        foreach (UsageLimitPolicy::templatePlans() as $plan) {
            $row = $request->input('templates.'.$plan, []);
            $templates[$plan] = is_array($row) ? $row : [];
        }

        $platform = $request->input('platform', []);
        if (! is_array($platform)) {
            $platform = [];
        }
        $platform['hard_stop_all'] = $request->boolean('platform.hard_stop_all');

        $this->policies->save($templates, $platform);

        return $this->redirectWithMessage(
            '/settings?section=limits',
            __('利用制限を保存しました')
        );
    }
}
