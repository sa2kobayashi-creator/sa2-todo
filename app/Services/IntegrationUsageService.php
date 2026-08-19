<?php

namespace App\Services;

use App\Models\IntegrationUsageDaily;
use App\Models\PushSubscription;
use App\Models\UserDailyUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IntegrationUsageService
{
    public function increment(string $provider, string $metric = 'requests', int $amount = 1): void
    {
        if ($amount < 1 || ! $this->tableReady()) {
            return;
        }

        $date = now()->toDateString();
        DB::table('integration_usage_dailies')->upsert([
            [
                'provider' => $provider,
                'metric' => $metric,
                'usage_date' => $date,
                'amount' => $amount,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['provider', 'metric', 'usage_date'], [
            'amount' => DB::raw('amount + '.$amount),
            'updated_at' => now(),
        ]);
    }

    /** @return array{groups: list<array{id: string, label: string, items: array<string, array{today: int, month: int, metric: string, external: string, label: string, description: string}>}>} */
    public function summary(): array
    {
        $start = now()->startOfMonth()->toDateString();
        $today = now()->toDateString();
        $rows = collect();
        if ($this->tableReady()) {
            $rows = IntegrationUsageDaily::query()
                ->where('usage_date', '>=', $start)
                ->get()
                ->groupBy(fn (IntegrationUsageDaily $row) => $row->provider.'|'.$row->metric);
        }

        $value = function (string $provider, string $metric = 'requests') use ($rows, $today): array {
            $items = $rows->get($provider.'|'.$metric, collect());

            return [
                'today' => (int) $items
                    ->filter(fn (IntegrationUsageDaily $row) => $row->usage_date?->toDateString() === $today)
                    ->sum('amount'),
                'month' => (int) $items->sum('amount'),
            ];
        };

        $userFeature = fn (string $feature) => $this->userFeatureTotals($feature, $today, $start);

        $enhanceToday = (int) UserDailyUsage::query()->where('feature', 'enhance')->where('usage_date', $today)->sum('amount');
        $enhanceMonth = (int) UserDailyUsage::query()->where('feature', 'enhance')->where('usage_date', '>=', $start)->sum('amount');

        $voiceItems = [
            'llm_voice_finance' => $this->usageItem(
                __('LLM（入出金音声入力）'),
                $userFeature('llm_voice_finance'),
                __('音声AIリクエスト'),
                'https://platform.openai.com/usage',
                __('入出金画面で話した内容を AI が取引候補に変換するときに使用します。'),
            ),
            'llm_voice_todo' => $this->usageItem(
                __('LLM（Todo音声入力）'),
                $userFeature('llm_voice_todo'),
                __('音声AIリクエスト'),
                'https://platform.openai.com/usage',
                __('Todo 画面で話した内容を AI がタスク候補に変換するときに使用します。'),
            ),
            'llm_voice_note' => $this->usageItem(
                __('LLM（メモ音声入力）'),
                $userFeature('llm_voice_note'),
                __('音声AIリクエスト'),
                'https://platform.openai.com/usage',
                __('メモ画面で話した内容を AI がメモ候補に変換するときに使用します。'),
            ),
        ];
        $legacyVoice = $userFeature('llm_voice');
        if ($legacyVoice['today'] > 0 || $legacyVoice['month'] > 0) {
            $voiceItems['llm_voice'] = $this->usageItem(
                __('LLM（音声入力・従来分）'),
                $legacyVoice,
                __('音声AIリクエスト'),
                'https://platform.openai.com/usage',
                __('用途別集計導入前の音声入力利用です。'),
            );
        }

        $groups = [
            [
                'id' => 'voice',
                'label' => __('音声入力'),
                'items' => $voiceItems,
            ],
            [
                'id' => 'image',
                'label' => __('画像処理'),
                'items' => [
                    'enhance' => $this->usageItem(
                        __('Photos AI鮮明化'),
                        ['today' => $enhanceToday, 'month' => $enhanceMonth],
                        __('鮮明化リクエスト'),
                        'https://platform.stability.ai/',
                        __('Photos の画像を AI で鮮明化した回数です。利用中のエンジン（Stability AI など）とクレジット残高は下の「ストレージ使用状況」を参照してください。'),
                    ),
                ],
            ],
            [
                'id' => 'media_travel',
                'label' => __('動画・旅行'),
                'items' => [
                    'youtube' => $this->usageItem(
                        __('YouTube検索（Data API）'),
                        $value('youtube'),
                        __('検索リクエスト'),
                        'https://console.cloud.google.com/apis/api/youtube.googleapis.com/quotas',
                        __('動画検索画面で YouTube から動画を探すときに使用します。'),
                    ),
                    'travelpayouts' => $this->usageItem(
                        __('Travelpayouts（航空運賃）'),
                        $value('travelpayouts'),
                        __('運賃取得'),
                        'https://www.travelpayouts.com/',
                        __('Travel の航空運賃見積もりを取得するときに使用します。'),
                    ),
                ],
            ],
            [
                'id' => 'notify',
                'label' => __('通知・メッセージ'),
                'items' => [
                    'line' => $this->usageItem(
                        __('LINE連携設定'),
                        $value('line', 'messages'),
                        __('配信成功'),
                        'https://developers.line.biz/console/',
                        __('Todo リマインダーなどを LINE に配信した回数です。'),
                    ),
                    'facebook' => $this->usageItem(
                        __('Facebook Messenger 通知連携'),
                        $value('facebook', 'messages'),
                        __('配信成功'),
                        'https://developers.facebook.com/',
                        __('Todo リマインダーなどを Messenger に配信した回数です。'),
                    ),
                    'web_push' => $this->usageItem(
                        __('Web Push 着信通知'),
                        $value('web_push', 'deliveries'),
                        __('配信成功'),
                        '',
                        __('通話着信などをブラウザ通知で配信した回数です。'),
                    ),
                    'web_push_subscriptions' => $this->usageItem(
                        __('Web Push 登録端末'),
                        [
                            'today' => Schema::hasTable('push_subscriptions') ? PushSubscription::query()->count() : 0,
                            'month' => 0,
                        ],
                        __('登録端末'),
                        '',
                        __('Web Push 通知を受け取れる登録端末の数です。'),
                    ),
                ],
            ],
            [
                'id' => 'calendar_call',
                'label' => __('カレンダー・通話'),
                'items' => [
                    'google_calendar' => $this->usageItem(
                        __('Googleカレンダー'),
                        $value('google_calendar'),
                        __('Calendar API成功'),
                        'https://console.cloud.google.com/apis/api/calendar-json.googleapis.com/quotas',
                        __('ダッシュボードやカレンダーに Google カレンダーの予定を表示・同期するときに使用します。'),
                    ),
                    'livekit' => $this->usageItem(
                        __('LiveKit 通話（試作）'),
                        $value('livekit', 'calls'),
                        __('発信'),
                        'https://cloud.livekit.io/',
                        __('メッセージ画面の音声・ビデオ通話（試作機能）の発信回数です。'),
                    ),
                ],
            ],
        ];

        return ['groups' => $groups];
    }

    /** @return array{today: int, month: int} */
    private function userFeatureTotals(string $feature, string $today, string $start): array
    {
        return [
            'today' => (int) UserDailyUsage::query()->where('feature', $feature)->where('usage_date', $today)->sum('amount'),
            'month' => (int) UserDailyUsage::query()->where('feature', $feature)->where('usage_date', '>=', $start)->sum('amount'),
        ];
    }

    /**
     * @param  array{today: int, month: int}  $counts
     * @return array{today: int, month: int, metric: string, external: string, label: string, description: string}
     */
    private function usageItem(string $label, array $counts, string $metric, string $external, string $description = ''): array
    {
        return [
            'label' => $label,
            'today' => $counts['today'],
            'month' => $counts['month'],
            'metric' => $metric,
            'external' => $external,
            'description' => $description,
        ];
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('integration_usage_dailies');
        } catch (\Throwable) {
            return false;
        }
    }
}
