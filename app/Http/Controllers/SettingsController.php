<?php

namespace App\Http\Controllers;

use App\Models\TranslationApiKey;
use App\Support\FooterNav;
use App\Services\AiLlmConfigService;
use App\Services\CloudflareWorkersAiConfigService;
use App\Services\CalendarService;
use App\Services\DeeplUsageService;
use App\Services\EnhanceConfigService;
use App\Services\GoogleCalendarConfigService;
use App\Services\GoogleMapsConfigService;
use App\Services\LineMessagingService;
use App\Services\LiveKitConfigService;
use App\Services\MessengerMessagingService;
use App\Services\NavitimeConfigService;
use App\Services\Transit\RouteSearchService;
use App\Services\HolidayService;
use App\Services\IntegrationUsageService;
use App\Services\MediaStorageConfigService;
use App\Services\PhotoService;
use App\Services\TravelpayoutsConfigService;
use App\Services\WebPushConfigService;
use App\Services\WebPushService;
use App\Services\YoutubeVideoService;
use App\Http\Controllers\Concerns\ManagesHolidays;
use App\Http\Controllers\Concerns\RedirectsWithFlash;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    use RedirectsWithFlash;
    use ManagesHolidays;

    public function __construct(
        private HolidayService $holidays,
        private MediaStorageConfigService $mediaStorage,
        private AiLlmConfigService $aiLlm,
        private CloudflareWorkersAiConfigService $workersAi,
        private DeeplUsageService $deeplUsage,
        private YoutubeVideoService $youtube,
        private EnhanceConfigService $enhance,
        private TravelpayoutsConfigService $travelpayouts,
        private GoogleMapsConfigService $googleMaps,
        private NavitimeConfigService $navitime,
        private RouteSearchService $routeSearch,
        private GoogleCalendarConfigService $googleCalendarOauth,
        private LineMessagingService $lineMessaging,
        private MessengerMessagingService $messengerMessaging,
        private LiveKitConfigService $livekit,
        private WebPushService $webPush,
        private WebPushConfigService $webPushConfig,
        private IntegrationUsageService $integrationUsage,
        private PhotoService $photos,
    ) {}

    public function index(Request $request)
    {
        $year = (int) ($request->query('year') ?: date('Y'));
        $section = $this->parseSection($request->query('section'));
        $messagingSection = in_array($section, ['integration', 'notifications'], true);
        $isSuperAdmin = (bool) $request->user()?->isSuperAdmin();

        return view('settings.index', [
            'section' => $section,
            'settingsSection' => $section,
            'holidayYear' => $year,
            'holidays' => $this->holidays->listByYear($year),
            'weekdayRules' => $this->holidays->listWeekdayRules(),
            'weekdayLabels' => CalendarService::translatedWeekdayLabels(),
            'prevHolidayYear' => $year - 1,
            'nextHolidayYear' => $year + 1,
            'settingsPath' => fn (?string $sec = null, ?int $y = null) => $this->settingsPath($sec ?? $section, $y ?? $year),
            'lineConfigured' => $this->lineMessaging->isConfigured(),
            'pushConfigured' => $this->webPush->isConfigured(),
            'integrationUsage' => $section === 'usage'
                ? $this->integrationUsage->summary($isSuperAdmin)
                : null,
            'storageStats' => $section === 'usage'
                ? $this->photos->storageStats((int) $request->user()->id, $isSuperAdmin)
                : null,
            'translationKeys' => $section === 'ai'
                ? TranslationApiKey::queryForCurrentTenant()->orderBy('priority', 'desc')->orderBy('id')->get()
                : collect(),
            'deeplPricing' => $section === 'ai' ? $this->deeplUsage->pricingFormState() : null,
            'deeplUsageSummaries' => $section === 'ai'
                ? TranslationApiKey::queryForCurrentTenant()->orderBy('priority', 'desc')->orderBy('id')->get()
                    ->mapWithKeys(fn (TranslationApiKey $key) => [
                        $key->id => $this->deeplUsage->usageSummary($key),
                    ])->all()
                : [],
            'llmSettings' => $section === 'ai' ? $this->aiLlm->formState() : null,
            'workersAiSettings' => $section === 'ai' ? $this->workersAi->formState() : null,
            'youtubeSettings' => $section === 'ai' ? $this->youtube->formState() : null,
            'storageR2' => $section === 'storage' ? $this->safeStorageFormState('r2') : null,
            'storageCloudinary' => $section === 'storage' ? $this->safeStorageFormState('cloudinary') : null,
            'storageBackblaze' => $section === 'storage' ? $this->safeStorageFormState('backblaze') : null,
            'storagePipeline' => $section === 'storage' ? $this->safeStorageFormState('pipeline') : null,
            'enhanceSettings' => ($section === 'enhance' && $isSuperAdmin) ? $this->enhance->formState() : null,
            'travelpayoutsSettings' => $section === 'enhance' ? $this->travelpayouts->formState() : null,
            'googleMapsSettings' => $section === 'enhance' ? $this->googleMaps->formState() : null,
            'navitimeSettings' => $section === 'enhance' ? $this->navitime->formState() : null,
            'routeSearchSettings' => $section === 'enhance' ? $this->routeSearchFormState() : null,
            'googleCalendarOauthSettings' => $section === 'enhance' ? $this->googleCalendarOauth->formState() : null,
            'isSuperAdmin' => $isSuperAdmin,
            'isTenantAdmin' => (bool) $request->user()?->isTenantAdmin(),
            'lineMessaging' => $messagingSection
                ? $this->lineMessaging->formState($request->user())
                : null,
            'messengerMessaging' => $messagingSection
                ? $this->messengerMessaging->formState($request->user())
                : null,
            'livekitSettings' => $section === 'integration'
                ? $this->livekit->formState()
                : null,
            'webPushSettings' => $section === 'integration'
                ? $this->webPushConfig->formState()
                : null,
            'footerNavSelected' => $section === 'nav'
                ? FooterNav::normalizeFooterKeys($request->user()?->footer_nav, $request->user())
                : null,
            'headerNavSelected' => $section === 'nav'
                ? FooterNav::normalizeHeaderKeys($request->user()?->header_nav, $request->user())
                : null,
            'currentUserModel' => $section === 'nav' ? $request->user() : null,
            ...$this->flashFromQuery($request),
        ]);
    }

    /** @return array<string, mixed> */
    private function routeSearchFormState(): array
    {
        $ready = [];
        foreach ($this->routeSearch->all() as $key => $provider) {
            $ready[$key] = $provider->isReady();
        }

        return [
            'options' => $this->routeSearch->options(),
            'selected' => $this->routeSearch->selectedKey(),
            'ready' => $ready,
            'activeLabel' => $this->routeSearch->activeProvider()?->label(),
        ];
    }

    public function updateFooterNav(Request $request)
    {
        $user = $request->user();
        $returnTo = $this->settingsPath('nav');

        if ($request->boolean('reset')) {
            $user->footer_nav = null;
            $user->header_nav = null;
            $user->save();

            return $this->redirectWithMessage($returnTo, __('表示メニューを既定に戻しました'));
        }

        $footerKeys = $request->input('footer_nav', []);
        if (! is_array($footerKeys)) {
            $footerKeys = [];
        }
        $headerKeys = $request->input('header_nav', []);
        if (! is_array($headerKeys)) {
            $headerKeys = [];
        }

        $user->footer_nav = FooterNav::normalizeFooterKeys($footerKeys, $user);
        $user->header_nav = FooterNav::normalizeHeaderKeys($headerKeys, $user);
        $user->save();

        return $this->redirectWithMessage($returnTo, __('表示メニューを保存しました'));
    }

    protected function holidayReturnPath(Request $request, int $year): string
    {
        return $this->settingsPath('holidays', $year);
    }

    private function parseSection(?string $value): string
    {
        if ($value === 'translation') {
            return 'ai';
        }

        return in_array($value, ['integration', 'notifications', 'ai', 'holidays', 'storage', 'enhance', 'nav', 'usage'], true) ? $value : 'holidays';
    }

    private function settingsPath(string $section, ?int $year = null): string
    {
        $params = ['section' => $section];
        if ($section === 'ai') {
            $params['tab'] = 'translation';
        }
        if ($year) {
            $params['year'] = $year;
        }

        return '/settings?'.http_build_query($params);
    }

    /** @return array<string, mixed> */
    private function safeStorageFormState(string $provider): array
    {
        try {
            return $this->mediaStorage->formState($provider);
        } catch (\Throwable $e) {
            report($e);

            return [
                'provider' => $provider,
                'enabled' => false,
                'settings' => [],
                'envFallback' => [],
                'hasSecrets' => [
                    'access_key_id' => false,
                    'secret_access_key' => false,
                    'api_key' => false,
                    'api_secret' => false,
                    'key_id' => false,
                    'application_key' => false,
                ],
                'last_tested_at' => null,
                'last_test_status' => 'fail',
                'last_test_message' => __('ストレージ設定の読み込みに失敗しました。マイグレーションと APP_KEY を確認してください。'),
            ];
        }
    }
}
