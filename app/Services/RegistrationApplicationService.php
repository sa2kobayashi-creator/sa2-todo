<?php

namespace App\Services;

use App\Enums\RegistrationApplicationPlan;
use App\Enums\RegistrationApplicationStatus;
use App\Enums\UserRole;
use App\Mail\RegistrationApplicationApprovedMail;
use App\Mail\RegistrationApplicationReceivedAdminMail;
use App\Mail\RegistrationApplicationRejectedMail;
use App\Models\RegistrationApplication;
use App\Models\User;
use App\Support\DisposableEmail;
use App\Support\SiteStatEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * TOP からの利用申請。
 * ライト: 自動審査（目的・捨てアド・週次上限）通過で登録メール。
 * スタンダード／テナント: 運営が手動承認。
 */
class RegistrationApplicationService
{
    public function __construct(private readonly SiteStatsService $stats) {}

    public function tokenTtlDays(): int
    {
        return max(1, (int) config('registration.application_token_ttl_days', 7));
    }

    public function purposeMinLength(): int
    {
        return max(1, (int) config('registration.purpose_min_length', 20));
    }

    /**
     * ライトお試し（利用申請・招待登録共通）の拒否理由。
     * 目的・捨てアドは常に検査。週次上限は enforceWeeklyCap のときだけ。
     */
    public function lightTrialBlockReason(string $email, string $purpose, bool $enforceWeeklyCap = true): ?string
    {
        $purpose = trim($purpose);
        if (mb_strlen($purpose) < $this->purposeMinLength()) {
            $this->stats->increment(SiteStatEvent::APPLY_REJECT_PURPOSE);

            return __('利用目的を:min文字以上で記入してください。お試しの範囲や使いたい機能を書いてください。', [
                'min' => $this->purposeMinLength(),
            ]);
        }

        if (DisposableEmail::isDisposable($email)) {
            $this->stats->increment(SiteStatEvent::APPLY_REJECT_DISPOSABLE);

            return __('一時的なメールアドレスでは申し込めません。普段お使いのメールアドレスをご利用ください。');
        }

        if ($enforceWeeklyCap && ! $this->lightSlotsAvailable()) {
            $this->stats->increment(SiteStatEvent::APPLY_REJECT_WEEKLY_CAP);

            return __('ただいまライト（お試し）の受付上限に達しています。スタンダードまたはテナント契約をご検討ください。');
        }

        return null;
    }

    public function lightWeeklyCap(): int
    {
        return max(0, (int) config('registration.light_weekly_cap', 50));
    }

    /**
     * @param  array{
     *   plan: string,
     *   email: string,
     *   display_name: string,
     *   organization_name?: string|null,
     *   phone?: string|null,
     *   message?: string|null,
     *   ip_address?: string|null,
     *   user_agent?: string|null
     * }  $data
     * @return array{ok: bool, message: string, application?: RegistrationApplication}
     */
    public function submit(array $data): array
    {
        $email = strtolower(trim((string) $data['email']));
        $plan = RegistrationApplicationPlan::tryFrom((string) $data['plan']);
        if ($plan === null) {
            return ['ok' => false, 'message' => __('選択されたプランは申し込めません。')];
        }

        $purpose = trim((string) ($data['message'] ?? ''));
        $block = $this->lightTrialBlockReason(
            email: $email,
            purpose: $purpose,
            enforceWeeklyCap: $plan === RegistrationApplicationPlan::Light,
        );
        if ($block !== null) {
            return ['ok' => false, 'message' => $block];
        }

        if (User::query()->where('email', $email)->exists()) {
            // 既存ユーザーへの列挙を避ける
            return [
                'ok' => true,
                'message' => $plan === RegistrationApplicationPlan::Light
                    ? __('条件を確認しました。登録用のメールをお送りできる場合は、まもなく届きます。')
                    : __('申請を受け付けました。運営の確認後、登録用のメールをお送りします。'),
            ];
        }

        $attrs = [
            'plan' => $plan,
            'display_name' => trim((string) $data['display_name']),
            'organization_name' => $plan === RegistrationApplicationPlan::Tenant
                ? trim((string) ($data['organization_name'] ?? ''))
                : null,
            'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
            'message' => $purpose,
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => isset($data['user_agent']) ? mb_substr((string) $data['user_agent'], 0, 500) : null,
        ];

        $pending = RegistrationApplication::query()
            ->where('email', $email)
            ->where('status', RegistrationApplicationStatus::Pending)
            ->first();

        if ($pending) {
            $pending->fill($attrs)->save();
            $application = $pending->fresh() ?? $pending;
        } else {
            $application = RegistrationApplication::create($attrs + [
                'email' => $email,
                'status' => RegistrationApplicationStatus::Pending,
            ]);
        }

        if ($plan === RegistrationApplicationPlan::Light) {
            $this->autoApproveLight($application);

            return [
                'ok' => true,
                'message' => __('お試し（ライト）の登録用メールを送信しました。メール内のリンクからパスワードを設定してください。'),
                'application' => $application->fresh() ?? $application,
            ];
        }

        $this->notifyAdmins($application);

        return [
            'ok' => true,
            'message' => __('申請を受け付けました。運営の確認後、登録用のメールをお送りします。'),
            'application' => $application,
        ];
    }

    /** 直近7日のライト新規＋登録待ちが上限未満か */
    public function lightSlotsAvailable(): bool
    {
        $cap = $this->lightWeeklyCap();
        if ($cap <= 0) {
            return false;
        }

        return $this->lightWeeklyUsage() < $cap;
    }

    public function lightWeeklyUsage(?int $excludeApplicationId = null): int
    {
        $since = Carbon::now()->subDays(7);

        $newLights = User::query()
            ->where('role', UserRole::Light->value)
            ->whereNull('tenant_id')
            ->where('created_at', '>=', $since)
            ->count();

        $awaiting = RegistrationApplication::query()
            ->where('plan', RegistrationApplicationPlan::Light)
            ->where('status', RegistrationApplicationStatus::Approved)
            ->where('created_at', '>=', $since)
            ->when($excludeApplicationId, fn ($q) => $q->whereKeyNot($excludeApplicationId))
            ->count();

        return $newLights + $awaiting;
    }

    public function approve(RegistrationApplication $application, User $reviewer, ?string $adminNote = null): RegistrationApplication
    {
        if (! $application->isPending()) {
            throw new \InvalidArgumentException(__('申請中の案件だけ承認できます。'));
        }

        if (User::query()->where('email', $application->email)->exists()) {
            throw new \InvalidArgumentException(__('このメールアドレスはすでに登録済みです。'));
        }

        return $this->issueActivation($application, $reviewer->id, $adminNote);
    }

    public function reject(RegistrationApplication $application, User $reviewer, ?string $adminNote = null): RegistrationApplication
    {
        if (! $application->isPending()) {
            throw new \InvalidArgumentException(__('申請中の案件だけ却下できます。'));
        }

        $application->fill([
            'status' => RegistrationApplicationStatus::Rejected,
            'approval_token_hash' => null,
            'approval_token_expires_at' => null,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'admin_note' => $adminNote !== null && trim($adminNote) !== '' ? trim($adminNote) : $application->admin_note,
        ])->save();

        Mail::to($application->email)->send(new RegistrationApplicationRejectedMail(
            displayName: $application->display_name,
            planLabel: __($application->plan->label()),
            adminNote: (string) ($application->admin_note ?? ''),
        ));

        return $application->fresh() ?? $application;
    }

    public function findByPlainToken(string $plainToken): ?RegistrationApplication
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '') {
            return null;
        }

        $candidates = RegistrationApplication::query()
            ->where('status', RegistrationApplicationStatus::Approved)
            ->whereNotNull('approval_token_hash')
            ->where('approval_token_expires_at', '>', now())
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        foreach ($candidates as $application) {
            if (Hash::check($plainToken, (string) $application->approval_token_hash)) {
                return $application;
            }
        }

        return null;
    }

    /**
     * @return array{user: User, application: RegistrationApplication, redirect: string}
     */
    public function activate(RegistrationApplication $application, string $password): array
    {
        if (! $application->isAwaitingActivation()) {
            throw new \InvalidArgumentException(__('この登録リンクは無効か、期限が切れています。'));
        }

        if (User::query()->where('email', $application->email)->exists()) {
            throw new \InvalidArgumentException(__('このメールアドレスはすでに登録済みです。'));
        }

        if ($application->plan === RegistrationApplicationPlan::Light
            && $this->lightWeeklyUsage((int) $application->id) >= $this->lightWeeklyCap()) {
            throw new \InvalidArgumentException(__('ただいまライト（お試し）の受付上限に達しています。スタンダードをご検討ください。'));
        }

        $user = User::create([
            'email' => $application->email,
            'display_name' => $application->display_name,
            'password' => Hash::make($password),
            'role' => UserRole::Light,
            'must_change_password' => false,
            'last_seen_at' => now(),
        ]);

        $application->fill([
            'status' => RegistrationApplicationStatus::Completed,
            'user_id' => $user->id,
            'approval_token_hash' => null,
            'approval_token_expires_at' => null,
        ])->save();

        $redirect = match ($application->plan) {
            RegistrationApplicationPlan::Standard => '/mypage/plan',
            RegistrationApplicationPlan::Tenant => '/dashboard',
            default => '/dashboard',
        };

        return [
            'user' => $user,
            'application' => $application->fresh() ?? $application,
            'redirect' => $redirect,
        ];
    }

    private function autoApproveLight(RegistrationApplication $application): void
    {
        $this->issueActivation(
            $application,
            reviewerId: null,
            adminNote: __('自動承認（ライトお試し）'),
        );
    }

    private function issueActivation(
        RegistrationApplication $application,
        ?int $reviewerId,
        ?string $adminNote = null,
    ): RegistrationApplication {
        $plainToken = Str::random(48);

        $application->fill([
            'status' => RegistrationApplicationStatus::Approved,
            'approval_token_hash' => Hash::make($plainToken),
            'approval_token_expires_at' => Carbon::now()->addDays($this->tokenTtlDays()),
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'admin_note' => $adminNote !== null && trim($adminNote) !== ''
                ? trim($adminNote)
                : $application->admin_note,
        ])->save();

        Mail::to($application->email)->send(new RegistrationApplicationApprovedMail(
            displayName: $application->display_name,
            planLabel: __($application->plan->label()),
            activateUrl: url('/apply/activate/'.$plainToken),
            expiresAt: $application->approval_token_expires_at?->format('Y-m-d H:i') ?? '',
            isStandard: $application->plan === RegistrationApplicationPlan::Standard,
            isTenant: $application->plan === RegistrationApplicationPlan::Tenant,
        ));

        return $application->fresh() ?? $application;
    }

    private function notifyAdmins(RegistrationApplication $application): void
    {
        $recipients = User::query()
            ->whereIn('role', [UserRole::SuperAdmin->value, UserRole::Admin->value])
            ->whereNull('tenant_id')
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $fallback = trim((string) app(LegalConfigService::class)->get('contact_email'));
        if ($fallback !== '' && ! in_array($fallback, $recipients, true)) {
            $recipients[] = $fallback;
        }

        if ($recipients === []) {
            return;
        }

        $mailable = new RegistrationApplicationReceivedAdminMail(
            planLabel: __($application->plan->label()),
            displayName: $application->display_name,
            email: $application->email,
            organizationName: (string) ($application->organization_name ?? ''),
            phone: (string) ($application->phone ?? ''),
            message: (string) ($application->message ?? ''),
            adminUrl: url('/admin/applications'),
        );

        foreach ($recipients as $to) {
            Mail::to($to)->send(clone $mailable);
        }
    }
}
