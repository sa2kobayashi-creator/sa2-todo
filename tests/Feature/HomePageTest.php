<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_the_public_top_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(config('app.name'), false)
            ->assertSee(__('予定・メモ・写真・メッセージを、ひとつの場所に。'), false)
            ->assertSee(__('個人の招待利用と、家族・小組織向けの専用環境（管理者契約）があります。Todo、メモ、Photos、メッセージ、メール、マップ、路線をまとめて使えます。'), false)
            ->assertSee(__('ログイン'), false)
            ->assertSee(__('このサービスは招待制です。招待コードがあると誰でも登録できます。'), false)
            ->assertSee(__('ご利用の流れ'), false)
            ->assertSee(__('無料で触る'), false)
            ->assertSee(__('専用インスタンス'), false)
            ->assertSee(__('テナント契約'), false)
            ->assertSee(__('管理者は契約代表1名だけ'), false)
            ->assertSee(__('月5ユーザーまで（代表を含む）'), false)
            ->assertSee(__('@sa2-plus.com メールは各1アドレス込み'), false)
            ->assertSee(__('最初の:days日間は無料。年額 ¥:yearly（:monthsか月分）。', [
                'days' => (int) config('commercial.tenant_trial_days', 30),
                'yearly' => number_format((int) config('commercial.tenant_yearly_yen', 0) ?: ((int) config('commercial.tenant_monthly_yen', 3980) * (int) config('commercial.yearly_maintenance_months_charged', 11))),
                'months' => (int) config('commercial.yearly_maintenance_months_charged', 11),
            ]), false)
            ->assertSee(__('¥:yen／月', ['yen' => number_format((int) config('commercial.tenant_monthly_yen', 3980))]), false)
            ->assertDontSee(__('料金はお問い合わせ'), false)
            ->assertSee(__('¥50,000〜'), false)
            ->assertSee(__('¥8,000〜／月'), false)
            ->assertSee(__('¥980／月'), false)
            ->assertSee(__('よくある質問'), false)
            ->assertSee(__('運営がお客様専用のサーバーへ、同じアプリを設置します。sa2-plus.com の共有環境とは別です。'), false)
            ->assertSee(__('管理者権限・ストレージ鍵の設定はありません'), false)
            ->assertSee(__('はい。テナント契約として、管理者は代表1名・ユーザーは月5名まで（代表を含む）・@sa2-plus.com メールは各1アドレス込みです。最初の:days日は無料、その後 ¥:yen／月（税別）です。個人のライト／スタンダードには管理者は付けません。サーバーを分けたい場合は専用インスタンスです。', [
                'days' => (int) config('commercial.tenant_trial_days', 30),
                'yen' => number_format((int) config('commercial.tenant_monthly_yen', 3980)),
            ]), false)
            ->assertSee('href="/terms"', false)
            ->assertSee('href="/privacy"', false)
            ->assertSee(__('Workers AI で、これからもっと便利に'), false)
            ->assertSee(__('音楽再生'), false)
            ->assertSee(__('動画再生'), false)
            ->assertSee(__('生活ガイド(AI)'), false)
            ->assertSee(__('家計簿'), false)
            ->assertSee(__('生活の知恵・話し相手'), false)
            ->assertSee(__('いま使える'), false)
            ->assertSee(__('生活の知恵・話し相手と、料理レシピ、今日のカレンダー案内はもう使えます。路線検索は NAVITIME の経路検索に対応済みです。'), false)
            ->assertDontSee(__('航空運賃（Travelpayouts）'), false);
    }

    public function test_register_cta_appears_only_when_registration_is_open(): void
    {
        Registration::setInviteCode('');
        $this->get('/')->assertOk()->assertDontSee(__('招待コードで登録'), false);

        Registration::setInviteCode('invite-top');
        $this->get('/')->assertOk()->assertSee(__('招待コードで登録'), false);
    }

    public function test_signed_in_users_are_sent_to_the_dashboard(): void
    {
        $user = User::create([
            'email' => 'home-user@example.com',
            'display_name' => 'Home User',
            'password' => Hash::make('password'),
            'role' => UserRole::Standard,
        ]);

        $this->actingAs($user)->get('/')->assertRedirect('/dashboard');
    }
}
