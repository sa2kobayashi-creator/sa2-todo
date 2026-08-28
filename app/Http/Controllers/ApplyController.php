<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationApplicationPlan;
use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Services\RegistrationApplicationService;
use App\Services\SiteStatsService;
use App\Support\SiteStatEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ApplyController extends Controller
{
    use RedirectsWithFlash;

    public function __construct(
        private readonly RegistrationApplicationService $applications,
        private readonly SiteStatsService $stats,
    ) {}

    public function show(Request $request)
    {
        if ($request->user()) {
            return redirect('/dashboard');
        }

        $plan = RegistrationApplicationPlan::tryFrom((string) $request->query('plan', 'standard'))
            ?? RegistrationApplicationPlan::Standard;

        if (! $this->stats->shouldSkipRequest($request)) {
            $this->stats->increment(SiteStatEvent::APPLY_VIEW);
            $this->stats->increment(match ($plan) {
                RegistrationApplicationPlan::Light => SiteStatEvent::APPLY_VIEW_LIGHT,
                RegistrationApplicationPlan::Tenant => SiteStatEvent::APPLY_VIEW_TENANT,
                default => SiteStatEvent::APPLY_VIEW_STANDARD,
            });
        }

        return view('apply.index', array_merge($this->flashFromQuery($request), [
            'plans' => RegistrationApplicationPlan::applyable(),
            'selectedPlan' => $plan,
            'standardMonthlyYen' => (int) config('commercial.standard_yen_monthly', 980),
            'standardTrialDays' => (int) config('commercial.standard_trial_days', 14),
            'tenantMonthlyYen' => (int) config('commercial.tenant_monthly_yen', 3980),
        ]));
    }

    public function store(Request $request)
    {
        if ($request->user()) {
            return redirect('/dashboard');
        }

        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
            'display_name' => trim((string) $request->input('display_name')),
            'organization_name' => trim((string) $request->input('organization_name')),
            'phone' => trim((string) $request->input('phone')),
            'message' => trim((string) $request->input('message')),
        ]);

        $plan = (string) $request->input('plan', '');

        $validator = Validator::make($request->all(), [
            'plan' => ['required', 'in:light,standard,tenant'],
            'email' => ['required', 'email', 'max:255'],
            'display_name' => ['required', 'string', 'max:100'],
            'organization_name' => [$plan === 'tenant' ? 'required' : 'nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'message' => ['required', 'string', 'min:'.$this->applications->purposeMinLength(), 'max:2000'],
            'agreeTerms' => ['accepted'],
        ], [
            'agreeTerms.accepted' => __('利用規約とプライバシーポリシーへの同意が必要です。'),
            'organization_name.required' => __('テナント契約では組織・家族名が必要です。'),
            'message.required' => __('利用目的を記入してください。'),
            'message.min' => __('利用目的を:min文字以上で記入してください。お試しの範囲や使いたい機能を書いてください。', [
                'min' => $this->applications->purposeMinLength(),
            ]),
        ]);

        if ($validator->fails()) {
            if ($validator->errors()->has('message')) {
                $this->stats->increment(SiteStatEvent::APPLY_REJECT_PURPOSE);
            }

            return redirect('/apply?plan='.urlencode($plan))
                ->withErrors($validator)
                ->withInput();
        }

        $result = $this->applications->submit([
            ...$validator->validated(),
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        if (! empty($result['ok']) && ! empty($result['application'])) {
            $this->stats->increment(match ($plan) {
                'light' => SiteStatEvent::APPLY_SUBMIT_LIGHT,
                'tenant' => SiteStatEvent::APPLY_SUBMIT_TENANT,
                default => SiteStatEvent::APPLY_SUBMIT_STANDARD,
            });
        }

        return $this->redirectWithMessage('/apply', $result['message'], $result['ok'] ? 'notice' : 'error');
    }

    public function showActivate(Request $request, string $token)
    {
        if ($request->user()) {
            return redirect('/dashboard');
        }

        $application = $this->applications->findByPlainToken($token);
        if ($application === null) {
            return view('apply.activate-invalid', $this->flashFromQuery($request));
        }

        return view('apply.activate', array_merge($this->flashFromQuery($request), [
            'application' => $application,
            'token' => $token,
        ]));
    }

    public function storeActivate(Request $request, string $token)
    {
        if ($request->user()) {
            return redirect('/dashboard');
        }

        $application = $this->applications->findByPlainToken($token);
        if ($application === null) {
            return $this->redirectWithMessage('/apply', __('この登録リンクは無効か、期限が切れています。'), 'error');
        }

        $validator = Validator::make($request->all(), [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return redirect('/apply/activate/'.$token)->withErrors($validator);
        }

        try {
            $result = $this->applications->activate($application, (string) $request->input('password'));
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage('/apply', $e->getMessage(), 'error');
        }

        $this->stats->increment(match ($application->plan) {
            RegistrationApplicationPlan::Standard => SiteStatEvent::ACTIVATE_COMPLETE_STANDARD,
            RegistrationApplicationPlan::Tenant => SiteStatEvent::ACTIVATE_COMPLETE_TENANT,
            default => SiteStatEvent::ACTIVATE_COMPLETE_LIGHT,
        });

        Auth::login($result['user']);
        $request->session()->regenerate();

        $notice = match ($application->plan->value) {
            'standard' => __('アカウントを作成しました。スタンダードのお申し込み（カード登録）へ進んでください。最初の:days日間は無料です。', [
                'days' => (int) config('commercial.standard_trial_days', 14),
            ]),
            'tenant' => __('アカウントを作成しました。テナント環境の準備は運営が進めています。準備ができ次第ご連絡します。'),
            default => __('アカウントを作成しました。ご利用を開始できます。'),
        };

        return $this->redirectWithMessage($result['redirect'], $notice);
    }
}
