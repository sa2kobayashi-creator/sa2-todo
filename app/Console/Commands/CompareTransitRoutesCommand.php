<?php

namespace App\Console\Commands;

use App\Services\Transit\Contracts\RouteProvider;
use App\Services\Transit\RouteSearchService;
use Illuminate\Console\Command;

/**
 * 同じ区間を全プロバイダで検索して並べる。契約を決める前の実測比較用。
 *
 * 例: php artisan transit:compare 志賀島 博多駅 --at="2026-08-26 08:00"
 */
class CompareTransitRoutesCommand extends Command
{
    protected $signature = 'transit:compare
        {from : 出発地（駅名・住所・「緯度,経度」）}
        {to : 到着地}
        {--at= : 日時 YYYY-MM-DD HH:MM（既定は今）}
        {--arrival : 指定時刻を到着時刻として扱う}
        {--preference=fastest : fastest / cheapest / fewest_transfers}
        {--limit=3 : プロバイダごとの表示件数}
        {--legs : 区間の内訳も表示する}';

    protected $description = '同じ区間を各経路検索 API で検索し、結果を比較する';

    public function handle(RouteSearchService $routes): int
    {
        $from = (string) $this->argument('from');
        $to = (string) $this->argument('to');
        $at = trim((string) $this->option('at')) ?: now()->format('Y-m-d H:i');
        $limit = max(1, min(10, (int) $this->option('limit')));

        $query = [
            'from' => $from,
            'to' => $to,
            'departureAt' => str_replace(' ', 'T', $at),
            'timeType' => $this->option('arrival') ? 'arrival' : 'departure',
            'preference' => (string) $this->option('preference'),
            'limit' => $limit,
        ];

        $this->info($from.' → '.$to.'（'.$at.' '.($this->option('arrival') ? '到着' : '出発').'）');
        $this->newLine();

        $rows = [];
        foreach ($routes->all() as $provider) {
            if (! $provider->isReady()) {
                $rows[] = [$provider->label(), '未設定', '-', '-', '-', '-', '-', '-'];

                continue;
            }

            $started = microtime(true);
            $result = $this->runProvider($provider, $query);
            $elapsed = (int) round((microtime(true) - $started) * 1000);

            if (empty($result['ok'])) {
                $rows[] = [$provider->label(), '失敗', '-', '-', '-', '-', '-', mb_substr((string) ($result['message'] ?? ''), 0, 40)];

                continue;
            }

            $itineraries = array_slice($result['itineraries'] ?? [], 0, $limit);
            if ($itineraries === []) {
                $rows[] = [$provider->label(), '0件', '-', '-', '-', '-', '-', $elapsed.'ms'];

                continue;
            }

            foreach ($itineraries as $index => $itinerary) {
                $rows[] = [
                    $index === 0 ? $provider->label() : '',
                    $index === 0 ? $elapsed.'ms' : '',
                    (string) ($itinerary['departureTime'] ?? ''),
                    (string) ($itinerary['arrivalTime'] ?? ''),
                    (string) ($itinerary['durationLabel'] ?? ''),
                    (string) ($itinerary['transfers'] ?? 0),
                    (string) ($itinerary['fareLabel'] ?? ''),
                    mb_substr((string) ($itinerary['summary'] ?? ''), 0, 46),
                ];

                if ($this->option('legs')) {
                    foreach ($itinerary['legs'] ?? [] as $leg) {
                        $rows[] = ['', '', '', '', '', '', '', '  '.$this->legLine($leg)];
                    }
                }
            }
        }

        $this->table(
            ['API', '応答', '出発', '到着', '所要', '乗換', '運賃', '経路 / 備考'],
            $rows
        );

        $this->line('選択中: '.$routes->selectedKey().'（実際に使われるのは '.($routes->activeProvider()?->label() ?? 'なし').'）');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function runProvider(RouteProvider $provider, array $query): array
    {
        try {
            return $provider->search($query);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'itineraries' => []];
        }
    }

    /** @param array<string, mixed> $leg */
    private function legLine(array $leg): string
    {
        $from = (string) ($leg['from'] ?? '');
        $to = (string) ($leg['to'] ?? '');
        if (($leg['type'] ?? '') === 'walk') {
            return '徒歩 '.$from.' → '.$to.'（'.round(((int) ($leg['durationSec'] ?? 0)) / 60).'分）';
        }

        return (string) ($leg['routeName'] ?? '').' '
            .(string) ($leg['boardTime'] ?? '').' '.$from
            .' → '.(string) ($leg['alightTime'] ?? '').' '.$to;
    }
}
