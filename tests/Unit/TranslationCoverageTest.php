<?php

namespace Tests\Unit;

use Tests\TestCase;

class TranslationCoverageTest extends TestCase
{
    public function test_japanese_underscore_keys_exist_in_en_json(): void
    {
        $enPath = lang_path('en.json');
        $en = json_decode((string) file_get_contents($enPath), true);
        $this->assertIsArray($en);

        $missing = [];
        $pattern = "/(?:__|@lang)\\(\\s*(['\"])((?:\\\\.|(?!\\1).)*)\\1/u";
        $roots = [app_path(), resource_path('views'), base_path('routes')];

        foreach ($roots as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $name = $file->getFilename();
                if (! str_ends_with($name, '.php') && ! str_ends_with($name, '.blade.php')) {
                    continue;
                }
                $src = (string) file_get_contents($file->getPathname());
                if (! preg_match_all($pattern, $src, $m)) {
                    continue;
                }
                foreach ($m[2] as $raw) {
                    // PHP リテラルのエスケープを実行時のキーへ戻す（\\ を含めて1パスで処理する）
                    $key = preg_replace('/\\\\(["\'\\\\])/', '$1', $raw);
                    if ($key === '' || ! preg_match('/\p{Hiragana}|\p{Katakana}|\p{Han}/u', $key)) {
                        continue;
                    }
                    if (! array_key_exists($key, $en)) {
                        $missing[$key] = true;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            array_keys($missing),
            'Add English entries in lang/en.json for these __() keys'
        );
    }
}
