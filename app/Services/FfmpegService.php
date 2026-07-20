<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class FfmpegService
{
    public function binary(): string
    {
        return (string) config('photos.ffmpeg_path', env('FFMPEG_PATH', 'ffmpeg'));
    }

    public function isAvailable(): bool
    {
        $process = new Process([$this->binary(), '-version']);
        $process->setTimeout(10);
        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful();
    }

    /**
     * Trim video from $startSec to $endSec into $outputPath.
     */
    public function trim(string $inputPath, string $outputPath, float $startSec, float $endSec): void
    {
        if (! $this->isAvailable()) {
            throw new \RuntimeException(__('ffmpeg が見つかりません。サーバーにインストールしてください。'));
        }
        if ($endSec <= $startSec) {
            throw new \InvalidArgumentException(__('終了時間は開始時間より後にしてください。'));
        }

        $duration = $endSec - $startSec;
        $process = new Process([
            $this->binary(),
            '-y',
            '-ss', (string) $startSec,
            '-i', $inputPath,
            '-t', (string) $duration,
            '-c', 'copy',
            $outputPath,
        ]);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($outputPath)) {
            // Fallback without stream copy (re-encode) when keyframes prevent clean cuts.
            $process = new Process([
                $this->binary(),
                '-y',
                '-ss', (string) $startSec,
                '-i', $inputPath,
                '-t', (string) $duration,
                '-c:v', 'libx264',
                '-c:a', 'aac',
                '-movflags', '+faststart',
                $outputPath,
            ]);
            $process->setTimeout(600);
            $process->run();
        }

        if (! $process->isSuccessful() || ! is_file($outputPath)) {
            throw new \RuntimeException(
                __('動画の切り出しに失敗しました。').' '.trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }
}
