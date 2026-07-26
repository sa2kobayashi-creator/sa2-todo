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
        $shrunkPath = $workDir.DIRECTORY_SEPARATOR.'in_small_'.$token.'.jpg';
        $outputPath = $workDir.DIRECTORY_SEPARATOR.'out_'.$token.'.'.$format;

        try {
            if (file_put_contents($inputPath, $binary) === false) {
                throw new \RuntimeException(__('一時ファイルを作成できません。'));
            }

            // 内蔵GPU向けに入力を縮小してからアップスケール（VRAM 節約）
            $processInput = $this->shrinkInputForLowVram($inputPath, $shrunkPath) ?: $inputPath;

            $scaleAttempts = $scale > 2 ? [$scale, 2] : [$scale];
            $tileAttempts = $tileSize > 0
                ? array_values(array_unique([max(32, $tileSize), 128, 64, 32]))
                : [128, 64, 32];

                    $result = null;
                    $lastDetail = '';
                    $succeeded = false;
                    $cancel = app(EnhanceCancelService::class);

                    foreach ($scaleAttempts as $tryScale) {
                        foreach ($tileAttempts as $tryTile) {
                            $cancel->throwIfCancelled();
                            @unlink($outputPath);
                            $attempt = [
                                $binaryPath,
                                '-i', $processInput,
                                '-o', $outputPath,
                                '-n', $model,
                                '-s', (string) $tryScale,
                                '-g', $gpuId !== '' ? $gpuId : '0',
                                '-f', $format,
                                '-t', (string) max(32, (int) $tryTile),
                                '-j', '1:1:1',
                            ];

                            $invoked = Process::timeout($timeout)
                                ->path(dirname($binaryPath))
                                ->start($attempt);

                            try {
                                while ($invoked->running()) {
                                    $cancel->throwIfCancelled();
                                    usleep(200_000);
                                }
                            } catch (\App\Exceptions\EnhanceCancelledException $e) {
                                $invoked->stop(1);
                                throw $e;
                            }

                            $result = $invoked->wait();

                            if ($result->successful() && is_file($outputPath) && filesize($outputPath) >= 32) {
                                $succeeded = true;
                                break 2;
                            }

                            $lastDetail = trim($result->errorOutput() ?: $result->output());
                            if ($lastDetail === '') {
                                $lastDetail = __('終了コード :code', ['code' => $result->exitCode()]);
                            }

                            if (! $this->isVramError($lastDetail)) {
                                throw new \RuntimeException(__('Real-ESRGAN エラー: :detail', [
                                    'detail' => mb_substr($this->friendlyError($lastDetail), 0, 400),
                                ]));
                            }
                        }
                    }

            if (! $succeeded) {
                throw new \RuntimeException(__('Real-ESRGAN エラー: :detail', [
                    'detail' => mb_substr(
                        $this->friendlyError($lastDetail !== '' ? $lastDetail : __('GPUメモリ不足の可能性があります。鮮明化設定でタイルサイズを 64、倍率を 2x にしてください。')),
                        0,
                        400
                    ),
                ]));
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
            @unlink($shrunkPath);
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

    private function shrinkInputForLowVram(string $inputPath, string $outPath): ?string
    {
        $maxEdge = max(512, (int) config('photos.realesrgan_max_input_edge', 1024));
        $info = @getimagesize($inputPath);
        if (! is_array($info)) {
            return null;
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if ($w < 1 || $h < 1) {
            return null;
        }
        $long = max($w, $h);
        if ($long <= $maxEdge) {
            return null;
        }

        $src = @imagecreatefromstring((string) file_get_contents($inputPath));
        if (! $src) {
            return null;
        }

        $ratio = $maxEdge / $long;
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));
        $dst = imagecreatetruecolor($nw, $nh);
        if (! $dst) {
            imagedestroy($src);

            return null;
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $ok = imagejpeg($dst, $outPath, 92);
        imagedestroy($src);
        imagedestroy($dst);

        return $ok && is_file($outPath) ? $outPath : null;
    }

    private function isVramError(string $detail): bool
    {
        $lower = strtolower($detail);

        return str_contains($lower, 'vkallocatememory')
            || str_contains($lower, 'out_of_device_memory')
            || str_contains($lower, 'out of device memory')
            || str_contains($detail, 'failed -2')
            || str_contains($lower, 'out of memory')
            || str_contains($lower, 'vk_error');
    }

    private function friendlyError(string $detail): string
    {
        if ($this->isVramError($detail)) {
            return __('GPUメモリ不足です。鮮明化設定でタイルサイズを 128 または 64 にするか、倍率を 2x にしてください。');
        }

        return $detail;
    }
}
