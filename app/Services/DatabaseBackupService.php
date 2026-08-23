<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class DatabaseBackupService
{
    public function __construct(private MediaStorageConfigService $media) {}

    /**
     * @return array{ok: bool, key: ?string, bytes: int, reason: string}
     */
    public function run(): array
    {
        if (! $this->media->backblazeEnabled()) {
            return ['ok' => false, 'key' => null, 'bytes' => 0, 'reason' => 'b2_disabled'];
        }

        $this->media->applyRuntimeDisks();
        $disk = (string) config('storage_management.disk', 'backblaze');
        $tmp = $this->tempPath();

        try {
            $bytes = $this->writeGzipDump($tmp);
            $key = 'backup/db/'.now()->timezone(config('app.timezone'))->format('Y-m-d').'.sql.gz';

            // 数百MBを文字列で持つとロリポップのメモリ上限で落ちるため、必ずストリームで送る。
            $stream = fopen($tmp, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('dump open failed');
            }
            try {
                Storage::disk($disk)->writeStream($key, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (! Storage::disk($disk)->exists($key)) {
                throw new \RuntimeException('b2 put failed');
            }

            $this->prune((int) config('storage_management.backup_keep_days', 14));

            return ['ok' => true, 'key' => $key, 'bytes' => $bytes, 'reason' => ''];
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function tempPath(): string
    {
        $dir = storage_path('app/backup-tmp');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir.DIRECTORY_SEPARATOR.'db-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.sql.gz';
    }

    private function writeGzipDump(string $path): int
    {
        $driver = (string) config('storage_management.backup_driver', 'mysqldump');
        $usePhp = $driver === 'php' || DB::connection()->getDriverName() === 'sqlite';

        if (! $usePhp) {
            try {
                $this->streamTo($path, fn ($gz) => $this->mysqldump($gz));

                return $this->fileSize($path);
            } catch (\Throwable $e) {
                Log::warning('db.backup.mysqldump_failed', ['error' => $e->getMessage()]);
            }
        }

        $this->streamTo($path, fn ($gz) => $this->phpDump($gz));

        return $this->fileSize($path);
    }

    private function streamTo(string $path, callable $writer): void
    {
        $gz = gzopen($path, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('gzip open failed');
        }
        try {
            $writer($gz);
        } finally {
            gzclose($gz);
        }
    }

    private function fileSize(string $path): int
    {
        clearstatcache(true, $path);

        return (int) (filesize($path) ?: 0);
    }

    /**
     * @param  resource  $gz
     */
    private function mysqldump($gz): void
    {
        $cfg = config('database.connections.'.config('database.default'));
        $bin = (string) config('storage_management.mysqldump_path', 'mysqldump');
        $args = [
            $bin,
            '--host='.($cfg['host'] ?? '127.0.0.1'),
            '--port='.(string) ($cfg['port'] ?? 3306),
            '--user='.($cfg['username'] ?? ''),
            '--single-transaction',
            '--quick',
            '--routines',
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            (string) ($cfg['database'] ?? ''),
        ];

        // パスワードを argv に置くと共有サーバーでは ps から読めるため、環境変数で渡す。
        $env = [];
        $password = (string) ($cfg['password'] ?? '');
        if ($password !== '') {
            $env['MYSQL_PWD'] = $password;
        }

        $process = new Process($args, null, $env);
        $process->setTimeout((float) config('storage_management.backup_timeout', 900));

        $writeFailed = false;
        $process->run(function (string $type, string $buffer) use ($gz, $process, &$writeFailed): void {
            if ($type === Process::ERR || $writeFailed) {
                return;
            }
            if (gzwrite($gz, $buffer) === false) {
                $writeFailed = true;
                $process->stop(0);

                return;
            }
            // 標準出力を内部バッファに溜めない。
            $process->clearOutput();
        });

        if ($writeFailed) {
            throw new \RuntimeException('gzip write failed');
        }
        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'mysqldump failed');
        }
    }

    /**
     * @param  resource  $gz
     */
    private function phpDump($gz): void
    {
        $isMysql = DB::connection()->getDriverName() !== 'sqlite';
        $this->write($gz, "-- sa2todo php dump ".now()->toIso8601String()."\n");
        if ($isMysql) {
            $this->write($gz, "SET FOREIGN_KEY_CHECKS=0;\n");
        }

        foreach ($this->tableNames() as $table) {
            $create = $this->createStatement($table);
            if ($create !== null) {
                $this->write($gz, 'DROP TABLE IF EXISTS `'.$table."`;\n".rtrim($create, ";\n").";\n");
            }
            $this->dumpRows($gz, $table);
        }

        if ($isMysql) {
            $this->write($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        }
    }

    /**
     * @param  resource  $gz
     */
    private function dumpRows($gz, string $table): void
    {
        $chunk = 500;
        $hasId = Schema::hasColumn($table, 'id');
        $lastId = 0;
        $offset = 0;

        while (true) {
            $query = DB::table($table);
            if ($hasId) {
                $query->where('id', '>', $lastId)->orderBy('id');
            } else {
                $query->offset($offset);
            }
            $rows = $query->limit($chunk)->get();
            if ($rows->isEmpty()) {
                return;
            }

            foreach ($rows as $row) {
                $data = (array) $row;
                $cols = array_map(fn ($c) => '`'.str_replace('`', '', (string) $c).'`', array_keys($data));
                $vals = array_map(function ($v) {
                    if ($v === null) {
                        return 'NULL';
                    }

                    return DB::getPdo()->quote((string) $v);
                }, array_values($data));
                $this->write($gz, 'INSERT INTO `'.$table.'` ('.implode(',', $cols).') VALUES ('.implode(',', $vals).");\n");
                if ($hasId) {
                    $lastId = (int) $data['id'];
                }
            }

            $offset += $chunk;
            if ($rows->count() < $chunk) {
                return;
            }
        }
    }

    private function createStatement(string $table): ?string
    {
        try {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $sql = DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql');

                return filled($sql) ? (string) $sql : null;
            }
            $row = DB::select('SHOW CREATE TABLE `'.str_replace('`', '', $table).'`');
            $values = array_values((array) ($row[0] ?? []));

            return isset($values[1]) ? (string) $values[1] : null;
        } catch (\Throwable $e) {
            Log::warning('db.backup.create_statement_failed', ['table' => $table, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  resource  $gz
     */
    private function write($gz, string $text): void
    {
        if (gzwrite($gz, $text) === false) {
            throw new \RuntimeException('gzip write failed');
        }
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return DB::table('sqlite_master')
                ->where('type', 'table')
                ->where('name', 'not like', 'sqlite_%')
                ->pluck('name')
                ->all();
        }

        return array_map(fn ($r) => (string) array_values((array) $r)[0], DB::select('SHOW TABLES'));
    }

    private function prune(int $keepDays): void
    {
        $disk = (string) config('storage_management.disk', 'backblaze');
        $cutoff = now()->subDays(max(1, $keepDays))->startOfDay();
        try {
            foreach (Storage::disk($disk)->files('backup/db') as $file) {
                if (! str_ends_with($file, '.sql.gz')) {
                    continue;
                }
                $base = basename($file, '.sql.gz');
                try {
                    $day = \Carbon\Carbon::createFromFormat('Y-m-d', $base);
                } catch (\Throwable) {
                    continue;
                }
                if ($day->lt($cutoff)) {
                    Storage::disk($disk)->delete($file);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('db.backup.prune_failed', ['error' => $e->getMessage()]);
        }
    }
}
