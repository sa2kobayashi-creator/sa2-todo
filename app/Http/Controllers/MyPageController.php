<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Jobs\DeleteUserAccountJob;
use App\Models\User;
use App\Services\BillingEntitlementService;
use App\Services\DashboardAiUsageService;
use App\Services\EmailChangeService;
use App\Services\GoogleCalendarService;
use App\Services\GroupService;
use App\Services\LineMessagingService;
use App\Services\MessengerMessagingService;
use App\Services\PhotoService;
use App\Services\Sa2PlusMailboxService;
use App\Services\StripeBillingService;
use App\Services\UsageLimitPolicyService;
use App\Services\UserDataExportService;
use App\Services\UserUsageLimitService;
use App\Services\WebPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MyPageController extends Controller
{
    use RedirectsWithFlash;

    public function __construct(
        private GroupService $groups,
        private EmailChangeService $emailChange,
        private GoogleCalendarService $googleCalendar,
        private LineMessagingService $lineMessaging,
        private MessengerMessagingService $messengerMessaging,
        private UserDataExportService $dataExport,
        private BillingEntitlementService $billing,
        private PhotoService $photos,
        private Sa2PlusMailboxService $domainMail,
        private WebPushService $webPush,
        private UsageLimitPolicyService $usageLimits,
        private UserUsageLimitService $userUsageLimits,
        private DashboardAiUsageService $aiUsage,
        private StripeBillingService $stripeBilling,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();

        $featureKeys = [
            'dashboard', 'todos', 'notes', 'photos', 'finance', 'transit', 'map',
            'music', 'video', 'mail', 'messages', 'translate', 'guide', 'groups', 'settings', 'admin',
        ];

        $storage = $user->canAccess('photos')
            ? $this->photos->storageStats((int) $user->id, $user->isSuperAdmin())
            : null;

        return view('mypage.index', array_merge($this->flashFromQuery($request), $this->stripeBilling->planPageData($user), [
            'user' => $user->toPublicArray(),
            'role' => $user->roleEnum(),
            'features' => array_values(array_filter(
                $featureKeys,
                fn (string $feature) => $user->canAccess($feature)
            )),
            'planSummary' => [
                'subscriptionStatus' => $user->subscriptionStatusEnum()->value,
                'subscriptionStatusLabel' => __($user->subscriptionStatusEnum()->label()),
                'subscriptionActive' => $this->billing->hasActiveSubscription($user),
                'isStaff' => $user->roleEnum()->isStaff(),
                'trialEndsAt' => optional($user->trial_ends_at)?->format('Y-m-d'),
                'storageOverageActive' => (bool) $user->storage_overage_active,
                'mailboxAddonActive' => $this->domainMail->userHasMailboxEntitlement($user),
                'mailboxIncludedInPlan' => $this->domainMail->mailboxIncludedInPlan($user),
                'mailboxAddonPriceMonthly' => $this->domainMail->addonPriceYenMonthly(),
                'mailboxDomain' => $this->domainMail->domain(),
                'storageQuotaLabel' => $storage['formattedCombinedQuota'] ?? null,
                'storageUsedLabel' => $storage['formattedTotalUsed'] ?? null,
                'storageUploadsBlocked' => (bool) ($storage['uploadsBlocked'] ?? false),
                'canPhotos' => $user->canAccess('photos'),
                'canMail' => $user->canAccess('mail'),
                'standardMonthlyYen' => (int) config('commercial.standard_yen_monthly', 980),
                'standardTrialDays' => (int) config('commercial.standard_trial_days', 14),
            ],
            'timezoneOptions' => \App\Support\LocaleFormat::timezoneOptions(),
            'groups' => $this->groups->listForUser($user->id)->all(),
            'hasPendingEmail' => $this->emailChange->hasPendingChange($user),
            'googleCalendar' => $this->googleCalendar->formState($user),
            'googleCalendarActionBase' => '/mypage/google-calendar',
            'googleCalendarTitle' => 'Googleカレンダー設定',
            'usageRemaining' => $this->usageLimits->remainingSummary($user, $this->userUsageLimits),
            'aiUsage' => $user->isAdmin()
                ? $this->aiUsage->summary((int) $user->id, $user->isSuperAdmin())
                : null,
            'lineMessaging' => $this->lineMessaging->formState($user),
            'lineMessagingActionBase' => '/mypage/messaging/line',
            'messengerMessaging' => $this->messengerMessaging->formState($user),
            'messengerMessagingActionBase' => '/mypage/messaging/messenger',
            'pushConfigured' => $this->webPush->isConfigured(),
        ]));
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $validator = Validator::make($request->all(), [
            'displayName' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'timezone' => ['nullable', 'string', Rule::in(\App\Support\LocaleFormat::TIMEZONES)],
        ]);

        if ($validator->fails()) {
            return redirect('/mypage')->withErrors($validator)->withInput();
        }

        $data = $validator->validated();

        $user->display_name = trim($data['displayName']);
        $tz = trim((string) ($data['timezone'] ?? ''));
        $user->timezone = $tz !== '' ? $tz : null;
        $user->save();

        // メールアドレスはログインIDなので、新しい宛先で受信できることを確認してから反映する
        if ($data['email'] !== $user->email) {
            $this->emailChange->startChange($user, $data['email']);

            return $this->redirectWithMessage(
                '/mypage/email/verify',
                __('確認コードを:emailに送信しました。コードを入力すると変更が完了します。', ['email' => $data['email']])
            );
        }

        return $this->redirectWithMessage('/mypage', __('プロフィールを更新しました。'));
    }

    public function export(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $relative = $this->dataExport->createZip($user);
        } catch (\Throwable $e) {
            report($e);

            return $this->redirectWithMessage('/mypage#account-delete', __('データエクスポートに失敗しました。'), 'error');
        }

        $absolute = Storage::disk('local')->path($relative);

        return response()->download($absolute, basename($relative), [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    public function destroy(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'password' => ['required', 'string'],
            'confirm' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return redirect('/mypage#account-delete')->withErrors($validator);
        }

        if (! Hash::check((string) $request->input('password'), (string) $user->password)) {
            return $this->redirectWithMessage('/mypage#account-delete', __('パスワードが正しくありません。'), 'error');
        }

        $confirm = trim((string) $request->input('confirm'));
        if ($confirm !== '退会' && strcasecmp($confirm, 'DELETE') !== 0) {
            return $this->redirectWithMessage(
                '/mypage#account-delete',
                __('確認のため「退会」または DELETE と入力してください。'),
                'error'
            );
        }

        if ($user->isSuperAdmin() && User::query()->where('role', 'super_admin')->count() <= 1) {
            return $this->redirectWithMessage(
                '/mypage#account-delete',
                __('最後のスーパー管理者は退会できません。先に別のスーパー管理者を任命してください。'),
                'error'
            );
        }

        try {
            $this->stripeBilling->cancelAllSubscriptionsForDeletion($user);
        } catch (\Throwable $e) {
            report($e);

            return $this->redirectWithMessage(
                '/mypage#account-delete',
                $e->getMessage() !== ''
                    ? $e->getMessage()
                    : __('有料契約の解約に失敗しました。プラン・お支払いから解約してから、もう一度退会してください。'),
                'error'
            );
        }

        $userId = (int) $user->id;

        // パスワード無効化は削除ジョブ成功直前のみ。ここで潰すと削除失敗時にログイン不能のまま残る。
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        DeleteUserAccountJob::dispatchAfterHttp($userId);

        return $this->redirectWithMessage('/login', __('アカウント削除を開始しました。ご利用ありがとうございました。'));
    }
}
