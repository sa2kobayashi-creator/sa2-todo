<?php

namespace App\Services;

use App\Models\IntegrationUsageDaily;
use App\Models\PushSubscription;
use App\Models\UserDailyUsage;
use Illuminate\Support\Facades\Cache;
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

    /** @return array<string, array{today: int, month: int, metric: string, external: string}> */
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

        $llmToday = (int) UserDailyUsage::query()->where('feature', 'llm_voice')->where('usage_date', $today)->sum('amount');
        $llmMonth = (int) UserDailyUsage::query()->where('feature', 'llm_voice')->where('usage_date', '>=', $start)->sum('amount');
        $enhanceToday = (int) UserDailyUsage::query()->where('feature', 'enhance')->where('usage_date', $today)->sum('amount');
        $enhanceMonth = (int) UserDailyUsage::query()->where('feature', 'enhance')->where('usage_date', '>=', $start)->sum('amount');
        $stabilityCredits = Cache::remember('settings_stability_credits', now()->addMinutes(10), fn () => app(StabilityAiService::class)->creditBalance());

        return [
            'llm' => ['today' => $llmToday, 'month' => $llmMonth, 'metric' => '音声AIリクエスト', 'external' => 'https://platform.openai.com/usage'],
            'youtube' => [...$value('youtube'), 'metric' => '検索リクエスト', 'external' => 'https://console.cloud.google.com/apis/api/youtube.googleapis.com/quotas'],
            'travelpayouts' => [...$value('travelpayouts'), 'metric' => '運賃取得', 'external' => 'https://www.travelpayouts.com/'],
            'stability' => ['today' => $enhanceToday, 'month' => $enhanceMonth, 'metric' => $stabilityCredits === null ? '鮮明化リクエスト' : '残高 '.rtrim(rtrim(number_format($stabilityCredits, 4, '.', ''), '0'), '.').' credits', 'external' => 'https://platform.stability.ai/'],
            'realesrgan' => [...$value('realesrgan'), 'metric' => 'ローカルGPU処理', 'external' => ''],
            'swinir' => [...$value('swinir'), 'metric' => 'GPU VPS処理', 'external' => ''],
            'line' => [...$value('line', 'messages'), 'metric' => '配信成功', 'external' => 'https://developers.line.biz/console/'],
            'facebook' => [...$value('facebook', 'messages'), 'metric' => '配信成功', 'external' => 'https://developers.facebook.com/'],
            'livekit' => [...$value('livekit', 'calls'), 'metric' => '発信', 'external' => 'https://cloud.livekit.io/'],
            'web_push' => [...$value('web_push', 'deliveries'), 'metric' => '配信成功', 'external' => ''],
            'google_calendar' => [...$value('google_calendar'), 'metric' => 'Calendar API成功', 'external' => 'https://console.cloud.google.com/apis/api/calendar-json.googleapis.com/quotas'],
            'web_push_subscriptions' => [
                'today' => Schema::hasTable('push_subscriptions') ? PushSubscription::query()->count() : 0,
                'month' => 0,
                'metric' => '登録端末',
                'external' => '',
            ],
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
