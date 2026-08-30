<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\UsageLimitPolicy;
use App\Models\User;
use App\Models\UserDailyUsage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class UsageLimitPolicyService
{
    public const FEATURE_TRANSLATE = 'translate';

    public const FEATURE_LLM_VOICE = 'llm_voice';

    public const FEATURE_WORKERS_AI = 'workers_ai';

    public const FEATURE_ROUTE_SEARCH = 'route_search';

    public const FEATURE_YOUTUBE = 'youtube';

    public const FEATURE_CLOUDINARY = 'cloudinary';

    public const FEATURE_LIVEKIT = 'livekit';

    public const FEATURE_MAPS = 'maps';

    public const FEATURE_NOTIFY = 'notify';

    public const FEATURE_VIDEO_PLAY = 'video_play';

    public const FEATURE_ATTACHMENT = 'attachment';

    /** @return list<string> */
    public static function meterFeatures(): array
    {
        return [
            self::FEATURE_TRANSLATE,
            self::FEATURE_LLM_VOICE,
            self::FEATURE_WORKERS_AI,
            self::FEATURE_ROUTE_SEARCH,
            self::FEATURE_YOUTUBE,
            self::FEATURE_CLOUDINARY,
            self::FEATURE_LIVEKIT,
            self::FEATURE_MAPS,
            self::FEATURE_NOTIFY,
            self::FEATURE_VIDEO_PLAY,
            self::FEATURE_ATTACHMENT,
        ];
    }

    public function suggestedTemplates(): array
    {
        $translateDay = max(0, (int) config('usage_limits.translate_chars_per_day', 50_000));
        $voiceDay = max(0, (int) config('usage_limits.llm_voice_requests_per_day', 30));
        $guideDay = max(0, (int) config('usage_limits.workers_ai_requests_per_day', 20));
        $routeDay = max(0, (int) config('usage_limits.route_search_requests_per_day', 30));
        $youtubeDay = max(0, (int) config('usage_limits.youtube_requests_per_day', 20));
        $cloudinaryDay = max(0, (int) config('usage_limits.cloudinary_requests_per_day', 10));
        $livekitDay = max(0, (int) config('usage_limits.livekit_requests_per_day', 10));
        $mapsDay = max(0, (int) config('usage_limits.maps_requests_per_day', 200));
        $notifyDay = max(0, (int) config('usage_limits.notify_requests_per_day', 200));
        $videoDay = max(0, (int) config('usage_limits.video_play_requests_per_day', 300));
        $attachmentDay = max(0, (int) config('usage_limits.attachment_requests_per_day', 100));
        $lightGb = $this->bytesToGb((int) config('photos.user_free_quota_bytes', 20 * 1024 * 1024 * 1024));
        $standardGb = $this->bytesToGb((int) config('photos.standard_quota_bytes', 200 * 1024 * 1024 * 1024));

        // 緩めの次段枠（Maps・通知・動画再生・添付）
        $lightExtras = [
            'route_search_requests_per_day' => 15,
            'route_search_requests_per_month' => 100,
            'youtube_requests_per_day' => 10,
            'youtube_requests_per_month' => 50,
            'cloudinary_requests_per_day' => 5,
            'cloudinary_requests_per_month' => 30,
            'livekit_requests_per_day' => 5,
            'livekit_requests_per_month' => 20,
            'maps_requests_per_day' => 100,
            'maps_requests_per_month' => 2000,
            'notify_requests_per_day' => 100,
            'notify_requests_per_month' => 2000,
            'video_play_requests_per_day' => 200,
            'video_play_requests_per_month' => 5000,
            'attachment_requests_per_day' => 50,
            'attachment_requests_per_month' => 500,
        ];
        $standardExtras = [
            'route_search_requests_per_day' => $routeDay,
            'route_search_requests_per_month' => $routeDay * 10,
            'youtube_requests_per_day' => $youtubeDay,
            'youtube_requests_per_month' => $youtubeDay * 10,
            'cloudinary_requests_per_day' => $cloudinaryDay,
            'cloudinary_requests_per_month' => $cloudinaryDay * 10,
            'livekit_requests_per_day' => $livekitDay,
            'livekit_requests_per_month' => $livekitDay * 10,
            'maps_requests_per_day' => $mapsDay,
            'maps_requests_per_month' => $mapsDay * 15,
            'notify_requests_per_day' => $notifyDay,
            'notify_requests_per_month' => $notifyDay * 15,
            'video_play_requests_per_day' => $videoDay,
            'video_play_requests_per_month' => $videoDay * 20,
            'attachment_requests_per_day' => $attachmentDay,
            'attachment_requests_per_month' => $attachmentDay * 15,
        ];

        return [
            UsageLimitPolicy::PLAN_LIGHT => [
                'storage_quota_gb' => max(1, (int) round($lightGb)),
                'translate_chars_per_day' => 20_000,
                'translate_chars_per_month' => 200_000,
                'llm_voice_requests_per_day' => 10,
                'llm_voice_requests_per_month' => 100,
                'workers_ai_requests_per_day' => 8,
                'workers_ai_requests_per_month' => 80,
                ...$lightExtras,
            ],
            UsageLimitPolicy::PLAN_STANDARD => [
                'storage_quota_gb' => max(1, (int) round($standardGb)),
                'translate_chars_per_day' => $translateDay,
                'translate_chars_per_month' => $translateDay * 10,
                'llm_voice_requests_per_day' => $voiceDay,
                'llm_voice_requests_per_month' => $voiceDay * 10,
                'workers_ai_requests_per_day' => $guideDay,
                'workers_ai_requests_per_month' => $guideDay * 10,
                ...$standardExtras,
            ],
            UsageLimitPolicy::PLAN_SPECIAL => [
                'storage_quota_gb' => max(1, (int) round($lightGb)),
                'translate_chars_per_day' => 20_000,
                'translate_chars_per_month' => 200_000,
                'llm_voice_requests_per_day' => 10,
                'llm_voice_requests_per_month' => 100,
                'workers_ai_requests_per_day' => 8,
                'workers_ai_requests_per_month' => 80,
                ...$lightExtras,
            ],
            UsageLimitPolicy::PLAN_TENANT => [
                'storage_quota_gb' => max(1, (int) round($standardGb)),
                'translate_chars_per_day' => $translateDay,
                'translate_chars_per_month' => $translateDay * 10,
                'llm_voice_requests_per_day' => $voiceDay,
                'llm_voice_requests_per_month' => $voiceDay * 10,
                'workers_ai_requests_per_day' => max($guideDay, 40),
                'workers_ai_requests_per_month' => max($guideDay, 40) * 10,
                'route_search_requests_per_day' => max($routeDay, 60),
                'route_search_requests_per_month' => max($routeDay, 60) * 10,
                'youtube_requests_per_day' => max($youtubeDay, 40),
                'youtube_requests_per_month' => max($youtubeDay, 40) * 10,
                'cloudinary_requests_per_day' => max($cloudinaryDay, 20),
                'cloudinary_requests_per_month' => max($cloudinaryDay, 20) * 10,
                'livekit_requests_per_day' => max($livekitDay, 20),
                'livekit_requests_per_month' => max($livekitDay, 20) * 10,
                'maps_requests_per_day' => max($mapsDay, 400),
                'maps_requests_per_month' => max($mapsDay, 400) * 15,
                'notify_requests_per_day' => max($notifyDay, 400),
                'notify_requests_per_month' => max($notifyDay, 400) * 15,
                'video_play_requests_per_day' => max($videoDay, 600),
                'video_play_requests_per_month' => max($videoDay, 600) * 20,
                'attachment_requests_per_day' => max($attachmentDay, 200),
                'attachment_requests_per_month' => max($attachmentDay, 200) * 15,
            ],
        ];
    }

    public function suggestedPlatform(): array
    {
        return [
            'estimated_monthly_yen_cap' => 0,
            'hard_stop_all' => false,
            'yen_per_llm_voice' => max(0, (int) config('usage_limits.yen_per_llm_voice', 5)),
            'yen_per_workers_ai' => max(0, (int) config('usage_limits.yen_per_workers_ai', 3)),
            'yen_per_translate_1000' => max(0, (int) config('usage_limits.yen_per_translate_1000', 2)),
            'yen_per_route_search' => max(0, (int) config('usage_limits.yen_per_route_search', 4)),
            'yen_per_youtube' => max(0, (int) config('usage_limits.yen_per_youtube', 2)),
            'yen_per_cloudinary' => max(0, (int) config('usage_limits.yen_per_cloudinary', 5)),
            'yen_per_livekit' => max(0, (int) config('usage_limits.yen_per_livekit', 8)),
            'yen_per_maps' => max(0, (int) config('usage_limits.yen_per_maps', 1)),
            'yen_per_notify' => max(0, (int) config('usage_limits.yen_per_notify', 1)),
            'yen_per_video_play' => max(0, (int) config('usage_limits.yen_per_video_play', 1)),
            'yen_per_attachment' => max(0, (int) config('usage_limits.yen_per_attachment', 1)),
        ];
    }

    public function formState(): array
    {
        $templates = $this->suggestedTemplates();
        foreach (UsageLimitPolicy::templatePlans() as $plan) {
            $templates[$plan] = array_merge($templates[$plan] ?? [], $this->storedLimits($plan));
        }

        return [
            'templates' => $templates,
            'platform' => array_merge($this->suggestedPlatform(), $this->storedLimits(UsageLimitPolicy::PLAN_PLATFORM)),
            'saved' => $this->tableReady() && UsageLimitPolicy::query()->exists(),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $templates
     * @param  array<string, mixed>  $platform
     */
    public function save(array $templates, array $platform): void
    {
        foreach (UsageLimitPolicy::templatePlans() as $plan) {
            $row = is_array($templates[$plan] ?? null) ? $templates[$plan] : [];
            UsageLimitPolicy::query()->updateOrCreate(
                ['plan' => $plan],
                ['limits' => $this->sanitizeTemplate($row)]
            );
        }

        UsageLimitPolicy::query()->updateOrCreate(
            ['plan' => UsageLimitPolicy::PLAN_PLATFORM],
            ['limits' => $this->sanitizePlatform($platform)]
        );
    }

    public function planForUser(?User $user): string
    {
        if ($user === null) {
            return UsageLimitPolicy::PLAN_LIGHT;
        }
        if ($user->tenant_id) {
            return UsageLimitPolicy::PLAN_TENANT;
        }
        if ($user->hasSpecialQuota()) {
            return UsageLimitPolicy::PLAN_SPECIAL;
        }

        $role = $user->roleEnum();
        if ($role === UserRole::Standard || $role->isStaff()) {
            return UsageLimitPolicy::PLAN_STANDARD;
        }

        return $this->hasActiveSubscription($user)
            ? UsageLimitPolicy::PLAN_STANDARD
            : UsageLimitPolicy::PLAN_LIGHT;
    }

    public function usesTenantPool(?User $user): bool
    {
        return $user !== null && $user->tenant_id !== null && ! $user->isSuperAdmin();
    }

    /** @return list<int> */
    public function poolUserIds(?User $user): array
    {
        if (! $this->usesTenantPool($user)) {
            return $user ? [(int) $user->id] : [];
        }

        return User::query()
            ->where('tenant_id', $user->tenant_id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function dailyLimit(?User $user, string $meter): int
    {
        if ($user?->isSuperAdmin()) {
            return 0;
        }

        $key = $this->dailyKey($meter);
        if ($key === '') {
            return $this->configDaily($meter);
        }

        $saved = $this->storedLimits($this->planForUser($user));
        if (array_key_exists($key, $saved)) {
            return max(0, (int) $saved[$key]);
        }

        return $this->configDaily($meter);
    }

    public function monthlyLimit(?User $user, string $meter): int
    {
        if ($user?->isSuperAdmin()) {
            return 0;
        }

        $key = $this->monthlyKey($meter);
        if ($key === '') {
            return 0;
        }

        $plan = $this->planForUser($user);
        $saved = $this->storedLimits($plan);
        if (array_key_exists($key, $saved)) {
            return max(0, (int) $saved[$key]);
        }

        // 未保存時は制限管理の提案テンプレート（月次）。0 は無制限扱いなので保存済みの 0 はそのまま。
        return $this->suggestedLimit($plan, $key, 0);
    }

    /** 保存済みなら GB。なければ null（photos.php の既存ロジックへ）。 */
    public function storageQuotaGb(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }

        $saved = $this->storedLimits($this->planForUser($user));
        if (! array_key_exists('storage_quota_gb', $saved)) {
            return null;
        }

        $gb = (int) $saved['storage_quota_gb'];

        return $gb > 0 ? $gb : null;
    }

    public function storageQuotaBytes(?User $user): ?int
    {
        $gb = $this->storageQuotaGb($user);

        return $gb === null ? null : $gb * 1024 * 1024 * 1024;
    }

    public function platformLimits(): array
    {
        return array_merge($this->suggestedPlatform(), $this->storedLimits(UsageLimitPolicy::PLAN_PLATFORM));
    }

    public function isHardStopped(): bool
    {
        return (bool) ($this->platformLimits()['hard_stop_all'] ?? false);
    }

    public function estimatedMonthlyYenCap(): int
    {
        return max(0, (int) ($this->platformLimits()['estimated_monthly_yen_cap'] ?? 0));
    }

    public function estimatedMonthlyYen(): int
    {
        if (! $this->usageTableReady()) {
            return 0;
        }

        $start = now()->startOfMonth()->toDateString();
        $superIds = User::query()->where('role', UserRole::SuperAdmin->value)->pluck('id')->all();

        $rows = UserDailyUsage::query()
            ->where('usage_date', '>=', $start)
            ->when($superIds !== [], fn ($q) => $q->whereNotIn('user_id', $superIds))
            ->selectRaw('feature, SUM(amount) as amount')
            ->groupBy('feature')
            ->pluck('amount', 'feature');

        $voice = 0;
        foreach (['llm_voice', 'llm_voice_finance', 'llm_voice_todo', 'llm_voice_note'] as $feature) {
            $voice += (int) ($rows[$feature] ?? 0);
        }

        $yen = $this->estimateYenForAmount(self::FEATURE_LLM_VOICE, $voice);
        $yen += $this->estimateYenForAmount(self::FEATURE_WORKERS_AI, (int) ($rows['workers_ai'] ?? 0));
        $yen += $this->estimateYenForAmount(self::FEATURE_TRANSLATE, (int) ($rows['translate'] ?? 0));
        $yen += $this->estimateYenForAmount(self::FEATURE_ROUTE_SEARCH, (int) ($rows['route_search'] ?? 0));
        $yen += $this->estimateYenForAmount(self::FEATURE_YOUTUBE, (int) ($rows['youtube'] ?? 0));
        $yen += $this->estimateYenForAmount(self::FEATURE_CLOUDINARY, (int) ($rows['cloudinary'] ?? 0));
        $yen += $this->estimateYenForAmount(self::FEATURE_LIVEKIT, (int) ($rows['livekit'] ?? 0));
        $yen += $this->estimateYenForAmount(self::FEATURE_MAPS, (int) ($rows['maps'] ?? 0));
        $yen += $this->estimateYenForAmount(self::FEATURE_NOTIFY, (int) ($rows['notify'] ?? 0));
        $yen += $this->estimateYenForAmount(self::FEATURE_VIDEO_PLAY, (int) ($rows['video_play'] ?? 0));
        $yen += $this->estimateYenForAmount(self::FEATURE_ATTACHMENT, (int) ($rows['attachment'] ?? 0));

        return $yen;
    }

    /**
     * 制限管理の見積単価で、使用量から円を見積もる。
     */
    public function estimateYenForAmount(string $meter, int $amount): int
    {
        $amount = max(0, $amount);
        if ($amount === 0) {
            return 0;
        }

        $platform = $this->platformLimits();

        return match ($meter) {
            self::FEATURE_TRANSLATE => (int) ceil($amount / 1000) * max(0, (int) ($platform['yen_per_translate_1000'] ?? 0)),
            self::FEATURE_LLM_VOICE => $amount * max(0, (int) ($platform['yen_per_llm_voice'] ?? 0)),
            self::FEATURE_WORKERS_AI => $amount * max(0, (int) ($platform['yen_per_workers_ai'] ?? 0)),
            self::FEATURE_ROUTE_SEARCH => $amount * max(0, (int) ($platform['yen_per_route_search'] ?? 0)),
            self::FEATURE_YOUTUBE => $amount * max(0, (int) ($platform['yen_per_youtube'] ?? 0)),
            self::FEATURE_CLOUDINARY => $amount * max(0, (int) ($platform['yen_per_cloudinary'] ?? 0)),
            self::FEATURE_LIVEKIT => $amount * max(0, (int) ($platform['yen_per_livekit'] ?? 0)),
            self::FEATURE_MAPS => $amount * max(0, (int) ($platform['yen_per_maps'] ?? 0)),
            self::FEATURE_NOTIFY => $amount * max(0, (int) ($platform['yen_per_notify'] ?? 0)),
            self::FEATURE_VIDEO_PLAY => $amount * max(0, (int) ($platform['yen_per_video_play'] ?? 0)),
            self::FEATURE_ATTACHMENT => $amount * max(0, (int) ($platform['yen_per_attachment'] ?? 0)),
            default => 0,
        };
    }

    /**
     * @return array{unit_yen: int, unit_label: string}
     */
    public function meterUnitInfo(string $meter): array
    {
        $platform = $this->platformLimits();

        return match ($meter) {
            self::FEATURE_TRANSLATE => [
                'unit_yen' => max(0, (int) ($platform['yen_per_translate_1000'] ?? 0)),
                'unit_label' => __('1000文字'),
            ],
            self::FEATURE_LLM_VOICE => [
                'unit_yen' => max(0, (int) ($platform['yen_per_llm_voice'] ?? 0)),
                'unit_label' => __('1回'),
            ],
            self::FEATURE_WORKERS_AI => [
                'unit_yen' => max(0, (int) ($platform['yen_per_workers_ai'] ?? 0)),
                'unit_label' => __('1回'),
            ],
            self::FEATURE_ROUTE_SEARCH => [
                'unit_yen' => max(0, (int) ($platform['yen_per_route_search'] ?? 0)),
                'unit_label' => __('1回'),
            ],
            self::FEATURE_YOUTUBE => [
                'unit_yen' => max(0, (int) ($platform['yen_per_youtube'] ?? 0)),
                'unit_label' => __('1回'),
            ],
            self::FEATURE_CLOUDINARY => [
                'unit_yen' => max(0, (int) ($platform['yen_per_cloudinary'] ?? 0)),
                'unit_label' => __('1回'),
            ],
            self::FEATURE_LIVEKIT => [
                'unit_yen' => max(0, (int) ($platform['yen_per_livekit'] ?? 0)),
                'unit_label' => __('1回'),
            ],
            self::FEATURE_MAPS => [
                'unit_yen' => max(0, (int) ($platform['yen_per_maps'] ?? 0)),
                'unit_label' => __('1回'),
            ],
            self::FEATURE_NOTIFY => [
                'unit_yen' => max(0, (int) ($platform['yen_per_notify'] ?? 0)),
                'unit_label' => __('1通'),
            ],
            self::FEATURE_VIDEO_PLAY => [
                'unit_yen' => max(0, (int) ($platform['yen_per_video_play'] ?? 0)),
                'unit_label' => __('1再生'),
            ],
            self::FEATURE_ATTACHMENT => [
                'unit_yen' => max(0, (int) ($platform['yen_per_attachment'] ?? 0)),
                'unit_label' => __('1件'),
            ],
            default => [
                'unit_yen' => 0,
                'unit_label' => __('1回'),
            ],
        };
    }

    /**
     * 販売価格・運営見積単価の一覧（目安）。
     *
     * @return array{
     *   disclaimer: string,
     *   tax_label: string,
     *   groups: list<array{id: string, label: string, hint: string, items: list<array{name: string, price: string, note: string}>}>
     * }
     */
    public function pricingCatalog(): array
    {
        $taxLabel = config('commercial.prices_include_tax', true) ? __('税込') : __('税別');
        $included = max(1, (int) config('commercial.included_users', 5));
        $tenantMonthly = max(0, (int) config('commercial.tenant_monthly_yen', 3980));
        $tenantYearly = max(0, (int) config('commercial.tenant_yearly_yen', 0));
        if ($tenantYearly <= 0) {
            $tenantYearly = $tenantMonthly * max(1, (int) config('commercial.yearly_maintenance_months_charged', 11));
        }
        $lightGb = max(1, (int) round(((int) config('photos.user_free_quota_bytes', 20 * 1024 * 1024 * 1024)) / (1024 ** 3)));
        $standardGb = max(1, (int) round(((int) config('photos.standard_quota_bytes', 200 * 1024 * 1024 * 1024)) / (1024 ** 3)));
        $platform = $this->platformLimits();
        $labels = UserUsageLimitService::featureShortLabels();

        $opsItems = [];
        foreach (self::meterFeatures() as $meter) {
            $info = $this->meterUnitInfo($meter);
            $opsItems[] = [
                'name' => (string) ($labels[$meter] ?? $meter),
                'price' => '¥'.number_format((int) $info['unit_yen']).'／'.$info['unit_label'],
                'note' => __('制限管理の見積単価'),
            ];
        }

        return [
            'disclaimer' => __('公式の請求額ではなく目安です。販売価格は公開料金設定、運営原価は制限管理の見積単価に基づきます。為替・プロバイダ料金改定で実際とずれることがあります。'),
            'tax_label' => $taxLabel,
            'groups' => [
                [
                    'id' => 'customer',
                    'label' => __('販売価格（お客様向け）'),
                    'hint' => __('TOP・特商法・Stripe で使う公開料金です。'),
                    'items' => [
                        [
                            'name' => __('ライト（お試し）'),
                            'price' => '¥0',
                            'note' => __('約:gbGB・自動審査', ['gb' => $lightGb]),
                        ],
                        [
                            'name' => __('スタンダード（月額）'),
                            'price' => '¥'.number_format((int) config('commercial.standard_yen_monthly', 980)).'／'.__('月'),
                            'note' => __('最初の:days日無料・約:gbGB', [
                                'days' => (int) config('commercial.standard_trial_days', 14),
                                'gb' => $standardGb,
                            ]),
                        ],
                        [
                            'name' => __('スタンダード（年額）'),
                            'price' => '¥'.number_format((int) config('commercial.standard_yen_yearly', 9800)).'／'.__('年'),
                            'note' => __('約:gbGB', ['gb' => $standardGb]),
                        ],
                        [
                            'name' => __('テナント契約（月額）'),
                            'price' => '¥'.number_format($tenantMonthly).'／'.__('月'),
                            'note' => __('最初の:days日無料・:users名まで', [
                                'days' => (int) config('commercial.tenant_trial_days', 30),
                                'users' => $included,
                            ]),
                        ],
                        [
                            'name' => __('テナント契約（年額）'),
                            'price' => '¥'.number_format($tenantYearly).'／'.__('年'),
                            'note' => __(':users名まで', ['users' => $included]),
                        ],
                        [
                            'name' => __('テナント／専用の追加ユーザー'),
                            'price' => '¥'.number_format((int) config('commercial.extra_user_yen_monthly', 1000)).'／'.__('人／月'),
                            'note' => __('含む人数を超えた場合'),
                        ],
                        [
                            'name' => __('メールボックス（月額）'),
                            'price' => '¥'.number_format((int) config('commercial.mailbox_yen_monthly', 300)).'／'.__('月'),
                            'note' => __('@sa2-plus.com 1アドレス'),
                        ],
                        [
                            'name' => __('メールボックス（年額）'),
                            'price' => '¥'.number_format((int) config('commercial.mailbox_yen_yearly', 3000)).'／'.__('年'),
                            'note' => __('@sa2-plus.com 1アドレス'),
                        ],
                        [
                            'name' => __('ストレージ超過'),
                            'price' => '¥'.number_format((int) config('commercial.storage_overage_yen_per_100gb', 300)).'／100GB／'.__('月'),
                            'note' => __('見込表示。実課金は今後'),
                        ],
                        [
                            'name' => __('専用インスタンス（初期構築）'),
                            'price' => '¥'.number_format((int) config('commercial.setup_fee_yen', 50000)).'〜',
                            'note' => __('別サーバー設置'),
                        ],
                        [
                            'name' => __('専用インスタンス（月額保守）'),
                            'price' => '¥'.number_format((int) config('commercial.monthly_base_yen', 8000)).'〜／'.__('月'),
                            'note' => __(':users名まで', ['users' => $included]),
                        ],
                    ],
                ],
                [
                    'id' => 'ops',
                    'label' => __('運営原価の見積単価'),
                    'hint' => __('使用量×この単価で今月の見積円を計算します。制限管理で変更できます。'),
                    'items' => $opsItems,
                ],
            ],
        ];
    }

    public function circuitBreakerTripped(): bool
    {
        if ($this->isHardStopped()) {
            return true;
        }

        $cap = $this->estimatedMonthlyYenCap();
        if ($cap <= 0) {
            return false;
        }

        return $this->estimatedMonthlyYen() >= $cap;
    }

    public function circuitBreakerRatio(): float
    {
        $cap = $this->estimatedMonthlyYenCap();
        if ($cap <= 0) {
            return 0.0;
        }

        return $this->estimatedMonthlyYen() / $cap;
    }

    /**
     * @return array{
     *   plan: string,
     *   pool: bool,
     *   meters: array<string, array{
     *     daily_limit: int,
     *     monthly_limit: int,
     *     used_today: int,
     *     used_month: int,
     *     monthly_ratio: float,
     *     warn_level: ?string,
     *     warn_message: ?string
     *   }>,
     *   warnings: list<array{meter: string, label: string, level: string, message: string, ratio: float}>
     * }
     */
    public function remainingSummary(User $user, UserUsageLimitService $usage): array
    {
        $labels = UserUsageLimitService::featureShortLabels();
        $meters = [];
        $warnings = [];

        foreach (self::meterFeatures() as $meter) {
            $feature = $meter === self::FEATURE_LLM_VOICE ? 'llm_voice' : $meter;
            $dailyLimit = $this->dailyLimit($user, $meter);
            $monthlyLimit = $this->monthlyLimit($user, $meter);
            $usedToday = $usage->usedToday($user, $feature);
            $usedMonth = $usage->usedThisMonth($user, $feature);
            $ratio = $monthlyLimit > 0 ? min(1.0, $usedMonth / $monthlyLimit) : 0.0;
            $label = (string) ($labels[$meter] ?? $meter);
            [$level, $message] = $this->monthlyWarn($label, $ratio, $monthlyLimit);

            $unit = $this->meterUnitInfo($meter);
            $estimatedYen = $this->estimateYenForAmount($meter, $usedMonth);

            $meters[$meter] = [
                'daily_limit' => $dailyLimit,
                'monthly_limit' => $monthlyLimit,
                'used_today' => $usedToday,
                'used_month' => $usedMonth,
                'monthly_ratio' => $ratio,
                'warn_level' => $level,
                'warn_message' => $message,
                'unit_yen' => $unit['unit_yen'],
                'unit_label' => $unit['unit_label'],
                'estimated_yen' => $estimatedYen,
            ];

            if ($level !== null && $message !== null) {
                $warnings[] = [
                    'meter' => $meter,
                    'label' => $label,
                    'level' => $level,
                    'message' => $message,
                    'ratio' => $ratio,
                ];
            }
        }

        usort($warnings, static fn (array $a, array $b) => $b['ratio'] <=> $a['ratio']);

        $estimatedTotal = 0;
        foreach ($meters as $row) {
            $estimatedTotal += (int) ($row['estimated_yen'] ?? 0);
        }

        return [
            'plan' => $this->planForUser($user),
            'pool' => $this->usesTenantPool($user),
            'meters' => $meters,
            'warnings' => $warnings,
            'estimated_yen_total' => $estimatedTotal,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string} [level, message]
     */
    private function monthlyWarn(string $label, float $ratio, int $monthlyLimit): array
    {
        if ($monthlyLimit <= 0) {
            return [null, null];
        }

        $pct = (int) floor(min(100, $ratio * 100));
        $remain = max(0, 100 - $pct);

        if ($ratio >= 1.0) {
            return ['stopped', __(':name停止', ['name' => $label])];
        }
        if ($ratio >= 0.9) {
            return ['critical', __('残り:pct%です', ['pct' => max(1, $remain)])];
        }
        if ($ratio >= 0.8) {
            return ['warn', __('今月の:name利用量が:pct%です', ['name' => $label, 'pct' => max(80, $pct)])];
        }

        return [null, null];
    }

    /** @return array<string, mixed> */
    public function storedLimits(string $plan): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        $row = UsageLimitPolicy::query()->where('plan', $plan)->first();
        $limits = $row?->limits;

        return is_array($limits) ? $limits : [];
    }

    /** @param  array<string, mixed>  $row */
    private function sanitizeTemplate(array $row): array
    {
        $keys = [
            'storage_quota_gb',
            'translate_chars_per_day',
            'translate_chars_per_month',
            'llm_voice_requests_per_day',
            'llm_voice_requests_per_month',
            'workers_ai_requests_per_day',
            'workers_ai_requests_per_month',
            'route_search_requests_per_day',
            'route_search_requests_per_month',
            'youtube_requests_per_day',
            'youtube_requests_per_month',
            'cloudinary_requests_per_day',
            'cloudinary_requests_per_month',
            'livekit_requests_per_day',
            'livekit_requests_per_month',
            'maps_requests_per_day',
            'maps_requests_per_month',
            'notify_requests_per_day',
            'notify_requests_per_month',
            'video_play_requests_per_day',
            'video_play_requests_per_month',
            'attachment_requests_per_day',
            'attachment_requests_per_month',
        ];
        $clean = [];
        foreach ($keys as $key) {
            $clean[$key] = max(0, (int) ($row[$key] ?? 0));
        }

        return $clean;
    }

    /** @param  array<string, mixed>  $row */
    private function sanitizePlatform(array $row): array
    {
        return [
            'estimated_monthly_yen_cap' => max(0, (int) ($row['estimated_monthly_yen_cap'] ?? 0)),
            'hard_stop_all' => filter_var($row['hard_stop_all'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'yen_per_llm_voice' => max(0, (int) ($row['yen_per_llm_voice'] ?? config('usage_limits.yen_per_llm_voice', 5))),
            'yen_per_workers_ai' => max(0, (int) ($row['yen_per_workers_ai'] ?? config('usage_limits.yen_per_workers_ai', 3))),
            'yen_per_translate_1000' => max(0, (int) ($row['yen_per_translate_1000'] ?? config('usage_limits.yen_per_translate_1000', 2))),
            'yen_per_route_search' => max(0, (int) ($row['yen_per_route_search'] ?? config('usage_limits.yen_per_route_search', 4))),
            'yen_per_youtube' => max(0, (int) ($row['yen_per_youtube'] ?? config('usage_limits.yen_per_youtube', 2))),
            'yen_per_cloudinary' => max(0, (int) ($row['yen_per_cloudinary'] ?? config('usage_limits.yen_per_cloudinary', 5))),
            'yen_per_livekit' => max(0, (int) ($row['yen_per_livekit'] ?? config('usage_limits.yen_per_livekit', 8))),
            'yen_per_maps' => max(0, (int) ($row['yen_per_maps'] ?? config('usage_limits.yen_per_maps', 1))),
            'yen_per_notify' => max(0, (int) ($row['yen_per_notify'] ?? config('usage_limits.yen_per_notify', 1))),
            'yen_per_video_play' => max(0, (int) ($row['yen_per_video_play'] ?? config('usage_limits.yen_per_video_play', 1))),
            'yen_per_attachment' => max(0, (int) ($row['yen_per_attachment'] ?? config('usage_limits.yen_per_attachment', 1))),
        ];
    }

    private function dailyKey(string $meter): string
    {
        return match ($meter) {
            self::FEATURE_TRANSLATE => 'translate_chars_per_day',
            self::FEATURE_LLM_VOICE => 'llm_voice_requests_per_day',
            self::FEATURE_WORKERS_AI => 'workers_ai_requests_per_day',
            self::FEATURE_ROUTE_SEARCH => 'route_search_requests_per_day',
            self::FEATURE_YOUTUBE => 'youtube_requests_per_day',
            self::FEATURE_CLOUDINARY => 'cloudinary_requests_per_day',
            self::FEATURE_LIVEKIT => 'livekit_requests_per_day',
            self::FEATURE_MAPS => 'maps_requests_per_day',
            self::FEATURE_NOTIFY => 'notify_requests_per_day',
            self::FEATURE_VIDEO_PLAY => 'video_play_requests_per_day',
            self::FEATURE_ATTACHMENT => 'attachment_requests_per_day',
            default => '',
        };
    }

    private function monthlyKey(string $meter): string
    {
        return match ($meter) {
            self::FEATURE_TRANSLATE => 'translate_chars_per_month',
            self::FEATURE_LLM_VOICE => 'llm_voice_requests_per_month',
            self::FEATURE_WORKERS_AI => 'workers_ai_requests_per_month',
            self::FEATURE_ROUTE_SEARCH => 'route_search_requests_per_month',
            self::FEATURE_YOUTUBE => 'youtube_requests_per_month',
            self::FEATURE_CLOUDINARY => 'cloudinary_requests_per_month',
            self::FEATURE_LIVEKIT => 'livekit_requests_per_month',
            self::FEATURE_MAPS => 'maps_requests_per_month',
            self::FEATURE_NOTIFY => 'notify_requests_per_month',
            self::FEATURE_VIDEO_PLAY => 'video_play_requests_per_month',
            self::FEATURE_ATTACHMENT => 'attachment_requests_per_month',
            default => '',
        };
    }

    private function configDaily(string $meter): int
    {
        return match ($meter) {
            self::FEATURE_TRANSLATE => max(0, (int) config('usage_limits.translate_chars_per_day', 50_000)),
            self::FEATURE_LLM_VOICE => max(0, (int) config('usage_limits.llm_voice_requests_per_day', 30)),
            self::FEATURE_WORKERS_AI => max(0, (int) config('usage_limits.workers_ai_requests_per_day', 20)),
            self::FEATURE_ROUTE_SEARCH => max(0, (int) config('usage_limits.route_search_requests_per_day', 30)),
            self::FEATURE_YOUTUBE => max(0, (int) config('usage_limits.youtube_requests_per_day', 20)),
            self::FEATURE_CLOUDINARY => max(0, (int) config('usage_limits.cloudinary_requests_per_day', 10)),
            self::FEATURE_LIVEKIT => max(0, (int) config('usage_limits.livekit_requests_per_day', 10)),
            self::FEATURE_MAPS => max(0, (int) config('usage_limits.maps_requests_per_day', 200)),
            self::FEATURE_NOTIFY => max(0, (int) config('usage_limits.notify_requests_per_day', 200)),
            self::FEATURE_VIDEO_PLAY => max(0, (int) config('usage_limits.video_play_requests_per_day', 300)),
            self::FEATURE_ATTACHMENT => max(0, (int) config('usage_limits.attachment_requests_per_day', 100)),
            default => 0,
        };
    }

    private function suggestedLimit(string $plan, string $key, int $fallback): int
    {
        $defaults = $this->suggestedTemplates()[$plan] ?? [];
        if (array_key_exists($key, $defaults)) {
            return max(0, (int) $defaults[$key]);
        }

        return max(0, $fallback);
    }

    private function hasActiveSubscription(User $user): bool
    {
        $raw = $user->subscription_status;
        $status = $raw instanceof SubscriptionStatus
            ? $raw
            : (SubscriptionStatus::tryFrom((string) $raw) ?? SubscriptionStatus::None);

        if ($status === SubscriptionStatus::Active) {
            return true;
        }
        if ($status !== SubscriptionStatus::Trial) {
            return false;
        }
        $ends = $user->trial_ends_at;
        if ($ends === null) {
            return true;
        }

        return Carbon::parse($ends)->isFuture();
    }

    private function bytesToGb(int $bytes): float
    {
        if ($bytes <= 0) {
            return 50;
        }

        return $bytes / (1024 * 1024 * 1024);
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('usage_limit_policies');
        } catch (\Throwable) {
            return false;
        }
    }

    private function usageTableReady(): bool
    {
        try {
            return Schema::hasTable('user_daily_usages');
        } catch (\Throwable) {
            return false;
        }
    }
}
