<?php

namespace App\Services;

use App\Support\PublicUrlGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ZipArchive;

class TranslateContentExtractor
{
    /**
     * アップロードファイルからテキストを抽出する。
     */
    public function fromUpload(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        $path = $file->getRealPath();
        if (! $path || ! is_readable($path)) {
            throw new \InvalidArgumentException(__('ファイルを読み取れませんでした。'));
        }

        return match ($ext) {
            'txt', 'md', 'csv', 'json', 'log' => $this->limit((string) file_get_contents($path)),
            'html', 'htm' => $this->fromHtml((string) file_get_contents($path)),
            'docx' => $this->fromDocx($path),
            'pdf' => throw new \InvalidArgumentException(__('PDF はこの画面では未対応です。テキストや Word（.docx）をご利用ください。')),
            'pptx', 'xlsx' => throw new \InvalidArgumentException(__('この形式はまだ未対応です。.txt / .md / .docx / .html をご利用ください。')),
            default => throw new \InvalidArgumentException(__('未対応のファイル形式です。.txt / .md / .docx / .html をご利用ください。')),
        };
    }

    /**
     * URL のページ本文を取得する。
     *
     * @return array{title: string, text: string, url: string}
     */
    public function fromUrl(string $url): array
    {
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException(__('URL の形式が正しくありません。'));
        }

        PublicUrlGuard::assertFetchable($url);

        $response = Http::timeout(20)
            // リダイレクトを追うと内部アドレスへ誘導されるため、あえて追わない
            ->withoutRedirecting()
            ->withHeaders([
                'User-Agent' => 'Sa2PlusTranslateBot/1.0',
                'Accept' => 'text/html,application/xhtml+xml',
            ])
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException(__('ウェブサイトを取得できませんでした。'));
        }

        $html = $response->body();
        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        }

        return [
            'title' => $title !== '' ? $title : Str::limit($url, 80),
            'text' => $this->fromHtml($html),
            'url' => $url,
        ];
    }

    public function fromHtml(string $html): string
    {
        $html = preg_replace('/<(script|style|noscript|svg|iframe)[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<!--.*?-->/s', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return $this->limit(trim($text));
    }

    private function fromDocx(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \InvalidArgumentException(__('Word ファイルを開けませんでした。'));
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            throw new \InvalidArgumentException(__('Word の本文を取得できませんでした。'));
        }

        $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? $xml;
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return $this->limit(trim($text));
    }

    private function limit(string $text): string
    {
        if (mb_strlen($text) > 50000) {
            return mb_substr($text, 0, 50000);
        }

        return $text;
    }
}
