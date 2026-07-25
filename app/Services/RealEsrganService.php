<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class RealEsrganService
{
    public function __construct(private EnhanceConfigService $enhance) {}

    public function isReady(): bool
    {
        return $this->enhance->isReady(EnhanceConfigService::PROVIDER_REALESRGAN);
    }

    /**
     * ローカル GPU の realesrgan-ncnn-vulkan でアップスケール。
     *
     * @return array{binary: string, mime: string, extension: string, width: ?int, height: ?int}
     */
    public function enhanceImage(string $binary, string $filename, string $mime = 'image/jpeg'): array
    {
        if (! $this->isReady()) {
            throw new \InvalidArgumentException(__('Real-ESRGAN が有効ではありません。鮮明化設定を確認してください。'));
        }

        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '1024M');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(900);
        }

        $row = $this->enhance->providerRow(EnhanceConfigService::PROVIDER_REALESRGAN);
        $binaryPath = $this->resolveBinaryPath((string) $row->setting('binary_path', ''));
        $model = (string) $row->setting('model', 'realesrgan-x4plus');
        $scale = max(2, min(4, (int) $row->setting('scale', 4)));
        $gpuId = (string) $row->setting('gpu_id', '0');
        $tileSize = max(0, (int) $row->setting('tile_size', 0));
        $timeout = max(30, (int) $row->setting('timeout_seconds', config('photos.realesrgan_timeout', 600)));
        $format = (string) $row->setting('output_format', 'png');
        if (! in_array($format, ['jpg', 'png', 'webp'], true)) {
            $format = 'png';
        }

        $allowedModels = [
            'realesrgan-x4plus',
            'realesrgan-x4plus-anime',
            'realesrnet-x4plus',
            'realesr-animevideov3',
        ];
        if (! in_array($model, $allowedModels, true)) {
            $model = 'realesrgan-x4plus';
        }

        $extIn = $this->extensionFromMime($mime, $filename);
        $workDir = storage_path('app/tmp/realesrgan');
        if (! is_dir($workDir) && ! mkdir($workDir, 0755, true) && ! is_dir($workDir)) {
            throw new \RuntimeException(__('一時ディレクトリを作成できません。'));
        }

        $token = Str::lower(Str::random(12));
        $inputPath = $workDir.DIRECTORY_SEPARATOR.'in_'.$token.'.'.$extIn;
        $outputPath = $workDir.DIRECTORY_SEPARATOR.'out_'.$token.'.'.$format;

        try {
            if (file_put_contents($inputPath, $binary) === false) {
                throw new \RuntimeException(__('一時ファイルを作成できません。'));
            }

            $command = [
                $binaryPath,
                '-i', $inputPath,
                '-o', $outputPath,
                '-n', $model,
                '-s', (string) $scale,
                '-g', $gpuId !== '' ? $gpuId : '0',
                '-f', $format,
            ];
            if ($tileSize > 0) {
                $command[] = '-t';
                $command[] = (string) $tileSize;
            }

            $result = Process::timeout($timeout)
                ->path(dirname($binaryPath))
                ->run($command);

            if (! $result->successful()) {
                $detail = trim($result->errorOutput() ?: $result->output());
                if ($detail === '') {
                    $detail = __('終了コード :code', ['code' => $result->exitCode()]);
                }
                throw new \RuntimeException(__('Real-ESRGAN エラー: :detail', ['detail' => mb_substr($detail, 0, 400)]));
            }

            if (! is_file($outputPath) || filesize($outputPath) < 32) {
                throw new \RuntimeException(__('Real-ESRGAN から空の画像が返りました。'));
            }

            $outBinary = file_get_contents($outputPath);
            if ($outBinary === false || $outBinary === '') {
                throw new \RuntimeException(__('Real-ESRGAN から空の画像が返りました。'));
            }

            $size = @getimagesize($outputPath);
            $extension = $format === 'jpg' ? 'jpg' : $format;
            $outMime = match ($extension) {
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                default => 'image/png',
            };

            return [
                'binary' => $outBinary,
                'mime' => $outMime,
                'extension' => $extension === 'jpg' ? 'jpeg' : $extension,
                'width' => is_array($size) ? (int) $size[0] : null,
                'height' => is_array($size) ? (int) $size[1] : null,
            ];
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        $row = $this->enhance->providerRow(EnhanceConfigService::PROVIDER_REALESRGAN);
        if (! $row->enabled) {
            return ['ok' => false, 'message' => __('Real-ESRGAN が有効ではありません。')];
        }

        $configured = trim((string) $row->setting('binary_path', ''));
        try {
            $binaryPath = $this->resolveBinaryPath($configured);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $modelsDir = dirname($binaryPath).DIRECTORY_SEPARATOR.'models';
        if (! is_dir($modelsDir)) {
            return [
                'ok' => false,
                'message' => __('バイナリは見つかりましたが models フォルダがありません（:path）', ['path' => $modelsDir]),
            ];
        }

        $result = Process::timeout(20)
            ->path(dirname($binaryPath))
            ->run([$binaryPath, '-h']);

        // -h は実装によって非0終了することがあるため、ファイル存在＋ヘルプ出力を優先判定
        $help = trim($result->output().' '.$result->errorOutput());
        if ($help !== '' && (str_contains($help, 'Usage:') || str_contains($help, '-i ') || str_contains($help, 'realesrgan'))) {
            $gpu = (string) $row->setting('gpu_id', '0');

            return [
                'ok' => true,
                'message' => __('Real-ESRGAN バイナリを確認しました（GPU id=:gpu）: :path', [
                    'gpu' => $gpu !== '' ? $gpu : '0',
                    'path' => $binaryPath,
                ]),
            ];
        }

        if ($result->successful()) {
            return [
                'ok' => true,
                'message' => __('Real-ESRGAN バイナリを確認しました: :path', ['path' => $binaryPath]),
            ];
        }

        $detail = trim($result->errorOutput() ?: $result->output());

        return [
            'ok' => false,
            'message' => __('Real-ESRGAN の起動に失敗しました: :detail', [
                'detail' => $detail !== '' ? mb_substr($detail, 0, 300) : __('不明なエラー'),
            ]),
        ];
    }

    public function resolveBinaryPath(string $configured): string
    {
        $candidates = [];
        if ($configured !== '') {
            $candidates[] = $configured;
        }
        $default = (string) config('photos.realesrgan_binary', '');
        if ($default !== '') {
            $candidates[] = $default;
        }
        $candidates[] = storage_path('app/bin/realesrgan-ncnn-vulkan.exe');
        $candidates[] = storage_path('app/bin/realesrgan-ncnn-vulkan');

        foreach ($candidates as $path) {
            $resolved = $this->normalizePath($path);
            if ($resolved !== '' && is_file($resolved)) {
                return $resolved;
            }
        }

        throw new \InvalidArgumentException(__('Real-ESRGAN の実行ファイルが見つかりません。鮮明化設定でパスを指定してください。'));
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        // 相対パスはプロジェクトルート基準
        if (! preg_match('/^(?:[a-zA-Z]:[\\\\\\/]|\\\\\\\\|\\/)/', $path)) {
            $path = base_path($path);
        }

        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function extensionFromMime(string $mime, string $filename): string
    {
        $mime = strtolower(trim($mime));
        $fromMime = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            default => null,
        };
        if ($fromMime) {
            return $fromMime;
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)
            ? ($ext === 'jpeg' ? 'jpg' : $ext)
            : 'jpg';
    }
}
