<?php

namespace App\Services;

use App\Models\SiteStatDaily;
use App\Support\SiteStatEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class SiteStatsService
{
    private ?bool $tableReady = null;

    public function tableReady(): bool
    {
        if ($this->tableReady !== null) {
            return $this->tableReady;
        }

        try {
            return $this->tableReady = Schema::hasTable('site_stats_daily');
        } catch (\Throwable) {
            return $this->tableReady = false;
        }
    }

    public function increment(string $eventKey, int $by = 1, ?Carbon $on = null): void
    {
        if ($by < 1 || ! $this->tableReady()) {
            return;
        }

        $eventKey = mb_substr(trim($eventKey), 0, 80);
        if ($eventKey === '') {
            return;
        }

        $date = ($on ?? now())->toDateString();

        try {
            if ($this->addCount($date, $eventKey, $by)) {
                return;
            }

            $now = now();
            SiteStatDaily::query()->insert([
                'stat_date' => $date,
                'event_key' => $eventKey,
                'count' => $by,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            // 同時アクセスで他のリクエストが先にその日の行を作ったケース
            $this->addCount($date, $eventKey, $by);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** 既存行への UPDATE 一発。行が無ければ false。 */
    private function addCount(string $date, string $eventKey, int $by): bool
    {
        return SiteStatDaily::query()
            ->where('stat_date', $date)
            ->where('event_key', $eventKey)
            ->increment('count', $by) > 0;
    }

    public function shouldSkipRequest(?Request $request): bool
    {
        if ($request === null) {
            return false;
        }

        $ua = strtolower((string) $request->userAgent());
        if ($ua === '') {
            return false;
        }

        foreach (['bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit', 'preview', 'wget', 'curl/'] as $needle) {
            if (str_contains($ua, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function trackTopView(Request $request): void
    {
        if ($this->shouldSkipRequest($request)) {
            return;
        }

        $this->increment(SiteStatEvent::TOP_VIEW);

        $locale = app()->getLocale() === 'en' ? SiteStatEvent::TOP_LOCALE_EN : SiteStatEvent::TOP_LOCALE_JA;
        $this->increment($locale);
        $this->increment($this->referrerBucket($request));
    }

    public function referrerBucket(Request $request): string
    {
        $ref = trim((string) $request->headers->get('referer', ''));
        if ($ref === '') {
            return SiteStatEvent::TOP_REFERRER_DIRECT;
        }

        $host = strtolower((string) (parse_url($ref, PHP_URL_HOST) ?? ''));
        $appHost = strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?? ''));
        if ($host === '' || ($appHost !== '' && $host === $appHost)) {
            return SiteStatEvent::TOP_REFERRER_DIRECT;
        }

        foreach (['google.', 'bing.', 'yahoo.', 'duckduckgo.', 'baidu.', 'yandex.'] as $search) {
            if (str_contains($host, $search)) {
                return SiteStatEvent::TOP_REFERRER_SEARCH;
            }
        }

        return SiteStatEvent::TOP_REFERRER_OTHER;
    }

    /**
     * @return array{
     *   days: int,
     *   from: string,
     *   to: string,
     *   totals: array<string, int>,
     *   series: list<array{date: string, values: array<string, int>}>,
     *   sections: list<array{title: string, rows: list<array{label: string, key: string, total: int}>}>
     * }
     */
    public function dashboard(int $days = 30): array
    {
        $days = max(1, min(90, $days));
        $to = now()->startOfDay();
        $from = $to->copy()->subDays($days - 1);

        $keys = $this->dashboardKeys();
        $totals = array_fill_keys($keys, 0);
        $byDate = [];

        if ($this->tableReady()) {
            $rows = SiteStatDaily::query()
                ->whereBetween('stat_date', [$from->toDateString(), $to->toDateString()])
                ->whereIn('event_key', $keys)
                ->get(['stat_date', 'event_key', 'count']);

            foreach ($rows as $row) {
                $key = (string) $row->event_key;
                $count = (int) $row->count;
                $totals[$key] = ($totals[$key] ?? 0) + $count;
                $date = $row->stat_date?->format('Y-m-d') ?? '';
                if ($date === '') {
                    continue;
                }
                $byDate[$date][$key] = ($byDate[$date][$key] ?? 0) + $count;
            }
        }

        $series = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $date = $d->toDateString();
            $values = [];
            foreach ($keys as $key) {
                $values[$key] = (int) ($byDate[$date][$key] ?? 0);
            }
            $series[] = ['date' => $date, 'values' => $values];
        }

        return [
            'days' => $days,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $totals,
            'series' => $series,
            'sections' => $this->sections($totals),
        ];
    }

    /** @return list<string> */
    private function dashboardKeys(): array
    {
        return [
            SiteStatEvent::TOP_VIEW,
            SiteStatEvent::TOP_LOCALE_JA,
            SiteStatEvent::TOP_LOCALE_EN,
            SiteStatEvent::TOP_REFERRER_DIRECT,
            SiteStatEvent::TOP_REFERRER_SEARCH,
            SiteStatEvent::TOP_REFERRER_OTHER,
            SiteStatEvent::CTA_PLAN_STANDARD,
            SiteStatEvent::CTA_PLAN_TENANT,
            SiteStatEvent::CTA_PLAN_LIGHT,
            SiteStatEvent::CTA_APPLY,
            SiteStatEvent::CTA_REGISTER_INVITE,
            SiteStatEvent::APPLY_VIEW,
            SiteStatEvent::APPLY_VIEW_LIGHT,
            SiteStatEvent::APPLY_VIEW_STANDARD,
            SiteStatEvent::APPLY_VIEW_TENANT,
            SiteStatEvent::APPLY_SUBMIT_LIGHT,
            SiteStatEvent::APPLY_SUBMIT_STANDARD,
            SiteStatEvent::APPLY_SUBMIT_TENANT,
            SiteStatEvent::APPLY_REJECT_PURPOSE,
            SiteStatEvent::APPLY_REJECT_DISPOSABLE,
            SiteStatEvent::APPLY_REJECT_WEEKLY_CAP,
            SiteStatEvent::ACTIVATE_COMPLETE_LIGHT,
            SiteStatEvent::ACTIVATE_COMPLETE_STANDARD,
            SiteStatEvent::ACTIVATE_COMPLETE_TENANT,
            SiteStatEvent::LOGIN,
            SiteStatEvent::CHECKOUT_START,
            SiteStatEvent::CHECKOUT_COMPLETE,
            SiteStatEvent::REGISTER_INVITE,
        ];
    }

    /**
     * @param  array<string, int>  $totals
     * @return list<array{title: string, rows: list<array{label: string, key: string, total: int}>}>
     */
    private function sections(array $totals): array
    {
        $row = fn (string $label, string $key) => [
            'label' => $label,
            'key' => $key,
            'total' => (int) ($totals[$key] ?? 0),
        ];

        return [
            [
                'title' => 'TOPページ',
                'rows' => [
                    $row('アクセス数（PV）', SiteStatEvent::TOP_VIEW),
                    $row('言語: 日本語', SiteStatEvent::TOP_LOCALE_JA),
                    $row('言語: English', SiteStatEvent::TOP_LOCALE_EN),
                    $row('参照: 直接', SiteStatEvent::TOP_REFERRER_DIRECT),
                    $row('参照: 検索', SiteStatEvent::TOP_REFERRER_SEARCH),
                    $row('参照: その他', SiteStatEvent::TOP_REFERRER_OTHER),
                ],
            ],
            [
                'title' => 'プラン・導線クリック',
                'rows' => [
                    $row('スタンダード申請', SiteStatEvent::CTA_PLAN_STANDARD),
                    $row('テナント契約申請', SiteStatEvent::CTA_PLAN_TENANT),
                    $row('ライト申請', SiteStatEvent::CTA_PLAN_LIGHT),
                    $row('ヘッダー「利用申請」', SiteStatEvent::CTA_APPLY),
                    $row('招待コードで登録', SiteStatEvent::CTA_REGISTER_INVITE),
                ],
            ],
            [
                'title' => '利用申請ファネル',
                'rows' => [
                    $row('申請ページ表示', SiteStatEvent::APPLY_VIEW),
                    $row('表示: ライト', SiteStatEvent::APPLY_VIEW_LIGHT),
                    $row('表示: スタンダード', SiteStatEvent::APPLY_VIEW_STANDARD),
                    $row('表示: テナント', SiteStatEvent::APPLY_VIEW_TENANT),
                    $row('送信: ライト', SiteStatEvent::APPLY_SUBMIT_LIGHT),
                    $row('送信: スタンダード', SiteStatEvent::APPLY_SUBMIT_STANDARD),
                    $row('送信: テナント', SiteStatEvent::APPLY_SUBMIT_TENANT),
                    $row('登録完了: ライト', SiteStatEvent::ACTIVATE_COMPLETE_LIGHT),
                    $row('登録完了: スタンダード', SiteStatEvent::ACTIVATE_COMPLETE_STANDARD),
                    $row('登録完了: テナント', SiteStatEvent::ACTIVATE_COMPLETE_TENANT),
                ],
            ],
            [
                'title' => 'ライト自動拒否',
                'rows' => [
                    $row('目的不足', SiteStatEvent::APPLY_REJECT_PURPOSE),
                    $row('捨てアド', SiteStatEvent::APPLY_REJECT_DISPOSABLE),
                    $row('週上限', SiteStatEvent::APPLY_REJECT_WEEKLY_CAP),
                ],
            ],
            [
                'title' => 'ログイン・招待・課金',
                'rows' => [
                    $row('ログイン成功', SiteStatEvent::LOGIN),
                    $row('招待コード登録完了', SiteStatEvent::REGISTER_INVITE),
                    $row('Checkout 開始', SiteStatEvent::CHECKOUT_START),
                    $row('Checkout 完了（Webhook）', SiteStatEvent::CHECKOUT_COMPLETE),
                ],
            ],
        ];
    }
}
