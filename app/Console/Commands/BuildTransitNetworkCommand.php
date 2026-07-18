<?php

namespace App\Console\Commands;

use App\Services\Transit\Raptor\FukuokaNetworkBuilder;
use Illuminate\Console\Command;

class BuildTransitNetworkCommand extends Command
{
    protected $signature = 'transit:build-network {--force : 既存ファイルを上書き}';

    protected $description = '福岡特化の RAPTOR 用路線ネットワーク JSON を生成する';

    public function handle(FukuokaNetworkBuilder $builder): int
    {
        $path = database_path('seed-data/transit/fukuoka_network.json');
        if (is_file($path) && ! $this->option('force')) {
            $this->warn('既に存在します。上書きする場合は --force を指定してください: '.$path);

            return self::SUCCESS;
        }

        $builder->write($path);
        $this->info('生成しました: '.$path);

        return self::SUCCESS;
    }
}
