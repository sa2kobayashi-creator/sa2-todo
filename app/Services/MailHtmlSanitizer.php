<?php

namespace App\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * 受信メールの HTML を表示前に無害化する。
 *
 * 送信者は任意の HTML を送れるため、素の本文をそのまま DOM に入れると
 * スクリプトが実行される（保存型 XSS）。許可リスト方式で組み立て直す。
 */
class MailHtmlSanitizer
{
    /** 巨大な HTML メールでパーサが詰まらないようにする上限 */
    private const MAX_INPUT_LENGTH = 2_000_000;

    private ?HtmlSanitizer $sanitizer = null;

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        return $this->sanitizer()->sanitize($html);
    }

    private function sanitizer(): HtmlSanitizer
    {
        if ($this->sanitizer instanceof HtmlSanitizer) {
            return $this->sanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            // script / style / iframe / object / embed / form などは許可リストに含まれない
            ->allowSafeElements()
            ->withMaxInputLength(self::MAX_INPUT_LENGTH)
            // メール本文はテーブルレイアウト＋インライン style が前提なので体裁のために残す。
            // <style> ブロックはアプリ全体に効いてしまうため許可しない。
            ->allowAttribute('style', '*')
            ->allowAttribute('align', '*')
            ->allowAttribute('bgcolor', '*')
            ->allowAttribute('color', '*')
            ->allowAttribute('width', '*')
            ->allowAttribute('height', '*')
            ->allowAttribute('cellpadding', ['table'])
            ->allowAttribute('cellspacing', ['table'])
            ->allowAttribute('border', ['table', 'img'])
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowMediaSchemes(['http', 'https', 'data', 'cid'])
            ->forceAttribute('a', 'target', '_blank')
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow');

        return $this->sanitizer = new HtmlSanitizer($config);
    }
}
