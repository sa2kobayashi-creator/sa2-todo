<?php

/**
 * TranslationCoverageTest と同じ走査で、en.json に無い __() キーを一覧に出す。
 * 端末の文字化けを避けるため結果はファイルへ書く。
 *
 * 使い方: php tools/dump-missing-translations.php
 */
$base = dirname(__DIR__);
$en = json_decode((string) file_get_contents($base.'/lang/en.json'), true) ?: [];

$missing = [];
$pattern = "/(?:__|@lang)\\(\\s*(['\"])((?:\\\\.|(?!\\1).)*)\\1/u";

foreach ([$base.'/app', $base.'/resources/views', $base.'/routes'] as $root) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($it as $file) {
        if (! $file->isFile()) {
            continue;
        }
        if (! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        $src = (string) file_get_contents($file->getPathname());
        if (! preg_match_all($pattern, $src, $m)) {
            continue;
        }
        foreach ($m[2] as $raw) {
            $key = preg_replace('/\\\\(["\'\\\\])/', '$1', $raw);
            if ($key === '' || ! preg_match('/\p{Hiragana}|\p{Katakana}|\p{Han}/u', $key)) {
                continue;
            }
            if (! array_key_exists($key, $en)) {
                $missing[$key] = '';
            }
        }
    }
}

file_put_contents(
    $base.'/storage/app/missing-translations.json',
    json_encode($missing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo count($missing)." missing keys written to storage/app/missing-translations.json\n";
