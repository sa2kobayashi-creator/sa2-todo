<?php

namespace App\Support;

/**
 * 添付ファイル配信時の Content-Type / Disposition。
 * 保存済み MIME をそのまま inline すると、拡張子 .txt でも text/html 扱いになり XSS になる。
 */
final class SafeAttachmentResponse
{
    /** @var list<string> */
    private const INLINE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/heic',
        'image/heif',
        'application/pdf',
        'audio/mpeg',
        'audio/mp4',
        'audio/x-m4a',
        'video/mp4',
        'text/plain',
        'text/csv',
        'text/markdown',
    ];

    /**
     * @return array{Content-Type: string, Content-Disposition: string, X-Content-Type-Options: string}
     */
    public static function headers(?string $storedMime, string $originalName, bool $download): array
    {
        $filename = str_replace(['"', "\r", "\n"], '', $originalName);
        $mime = strtolower(trim((string) $storedMime));
        if ($mime !== '' && str_contains($mime, ';')) {
            $mime = trim(explode(';', $mime, 2)[0]);
        }

        if ($mime === '' || self::isUnsafeMime($mime)) {
            $mime = 'application/octet-stream';
        }

        $canInline = in_array($mime, self::INLINE_MIME_TYPES, true);
        $asAttachment = $download || ! $canInline;

        return [
            'Content-Type' => $asAttachment && ! $canInline ? 'application/octet-stream' : $mime,
            'Content-Disposition' => ($asAttachment ? 'attachment' : 'inline').'; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    private static function isUnsafeMime(string $mime): bool
    {
        return str_contains($mime, 'html')
            || str_contains($mime, 'javascript')
            || str_contains($mime, 'ecmascript')
            || $mime === 'image/svg+xml'
            || $mime === 'application/xhtml+xml'
            || $mime === 'text/xml'
            || $mime === 'application/xml';
    }
}
