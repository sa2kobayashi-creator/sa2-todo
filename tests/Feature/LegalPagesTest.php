<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokushoho_page_is_reachable_without_login(): void
    {
        $this->get('/tokushoho')
            ->assertOk()
            ->assertSee(__('特定商取引法に基づく表記'), false)
            ->assertSee(__('解約・返品・返金について'), false);
    }

    public function test_tokushoho_warns_while_the_operator_details_are_blank(): void
    {
        config([
            'legal.operator_name' => '',
            'legal.address' => '',
            'legal.contact_email' => '',
        ]);

        $this->get('/tokushoho')
            ->assertOk()
            ->assertSee(__('事業者情報が未設定です。有料販売を始める前に、設定 → 公開販売 で氏名・住所・電話・メールを保存してください。'), false)
            ->assertSee('legal-unset', false);
    }

    public function test_tokushoho_shows_the_configured_operator_and_prices(): void
    {
        config([
            'legal.operator_name' => '山田 太郎',
            'legal.address' => '東京都千代田区1-1-1',
            'legal.contact_email' => 'support@example.com',
            'legal.phone' => '03-0000-0000',
            'commercial.standard_yen_monthly' => 980,
            'commercial.standard_yen_yearly' => 9800,
        ]);

        $this->get('/tokushoho')
            ->assertOk()
            ->assertDontSee('legal-unset', false)
            ->assertSee('山田 太郎', false)
            ->assertSee('東京都千代田区1-1-1', false)
            ->assertSee('support@example.com', false)
            ->assertSee('¥980', false)
            ->assertSee('¥9,800', false);
    }

    public function test_terms_cover_the_paid_plan_clauses(): void
    {
        $response = $this->get('/terms')->assertOk();

        foreach ([
            '有料プランと契約の成立',
            '料金・支払方法・自動更新',
            '解約・返金',
            '反社会的勢力の排除',
            '本規約の変更',
        ] as $heading) {
            $response->assertSee($heading, false);
        }
    }

    public function test_privacy_lists_processors_and_the_contact_window(): void
    {
        config(['legal.privacy_contact_email' => 'privacy@example.com']);

        $this->get('/privacy')
            ->assertOk()
            ->assertSee('Stripe, Inc.', false)
            ->assertSee('Cloudflare, Inc.', false)
            ->assertSee('privacy@example.com', false)
            ->assertSee(__('外国にある第三者への提供'), false)
            ->assertSee(__('開示等の請求'), false);
    }

    public function test_privacy_contact_falls_back_to_the_general_contact(): void
    {
        config([
            'legal.privacy_contact_email' => '',
            'legal.contact_email' => 'support@example.com',
        ]);

        $this->get('/privacy')->assertOk()->assertSee('support@example.com', false);
    }

    public function test_public_pages_link_to_the_tokushoho_page(): void
    {
        foreach (['/', '/terms', '/privacy'] as $path) {
            $this->get($path)->assertOk()->assertSee('href="/tokushoho"', false);
        }
    }
}
