<?php

namespace Tests\Unit;

use App\Services\MailHtmlSanitizer;
use Tests\TestCase;

class MailHtmlSanitizerTest extends TestCase
{
    private MailHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new MailHtmlSanitizer;
    }

    public function test_script_tags_are_removed(): void
    {
        $out = $this->sanitizer->sanitize('<p>hello</p><script>alert(document.cookie)</script>');

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert(', $out);
        $this->assertStringContainsString('hello', $out);
    }

    public function test_inline_event_handlers_are_removed(): void
    {
        $out = $this->sanitizer->sanitize('<img src="https://example.test/a.png" onerror="alert(1)">');

        $this->assertStringNotContainsString('onerror', $out);
    }

    public function test_javascript_urls_are_removed(): void
    {
        $out = $this->sanitizer->sanitize('<a href="javascript:alert(1)">click</a>');

        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringContainsString('click', $out);
    }

    public function test_iframes_and_forms_are_removed(): void
    {
        $out = $this->sanitizer->sanitize(
            '<iframe src="https://evil.test"></iframe><form action="https://evil.test"><input name="pw"></form>'
        );

        $this->assertStringNotContainsString('<iframe', $out);
        $this->assertStringNotContainsString('<form', $out);
        $this->assertStringNotContainsString('<input', $out);
    }

    public function test_style_blocks_are_removed_but_inline_style_is_kept(): void
    {
        $out = $this->sanitizer->sanitize(
            '<style>body{display:none}</style><p style="color:#ff0000">赤い文字</p>'
        );

        $this->assertStringNotContainsString('<style', $out);
        $this->assertStringContainsString('color:#ff0000', $out);
        $this->assertStringContainsString('赤い文字', $out);
    }

    public function test_table_layout_and_images_survive(): void
    {
        $out = $this->sanitizer->sanitize(
            '<table cellpadding="0"><tr><td align="center"><img src="https://example.test/logo.png" width="120"></td></tr></table>'
        );

        $this->assertStringContainsString('<table', $out);
        $this->assertStringContainsString('<td', $out);
        $this->assertStringContainsString('https://example.test/logo.png', $out);
    }

    public function test_links_open_in_a_new_tab_without_referrer_leak(): void
    {
        $out = $this->sanitizer->sanitize('<a href="https://example.test/x">link</a>');

        $this->assertStringContainsString('target="_blank"', $out);
        $this->assertStringContainsString('noopener', $out);
    }

    public function test_empty_input_returns_empty_string(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize('   '));
    }
}
