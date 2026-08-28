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
        $lightGb = max(1, (int) round(((int) config('photos.user_free_quota_bytes', 20 * 1024 * 1024 * 1024)) / (1024 * 1024 * 1024)));
        $weeklyCap = (int) config('registration.light_weekly_cap', 50);
        $warnDays = (int) config('registration.light_inactive_warn_days', 90);
        $graceDays = (int) config('registration.light_inactive_delete_grace_days', 14);

        $this->get('/')
            ->assertOk()
            ->assertSee(config('app.name'), false)
            ->assertSee(__('Todo・予定・メモ・写真をまとめて管理｜:app', ['app' => config('app.name')]), false)
            ->assertSee(__('Sa2 Plusは、Todo・予定・メモ・写真・メール・メッセージ・地図・路線検索・AI相談をひとつにまとめたオールインワンサービスです。個人利用から家族・小規模組織まで、毎日の情報を便利に管理できます。'), false)
            ->assertSee(__('予定・メモ・写真・メッセージを、ひとつの場所に。'), false)
            ->assertSee(__('Todo・予定・メモ・写真をひとつに。毎日の管理をもっとシンプルに。'), false)
            ->assertSee(__('本格利用はスタンダード、家族・小組織はテナント契約が中心です。ライトは短期間のお試し（約:gbGB）です。', ['gb' => $lightGb]), false)
            ->assertSee(__('ログイン'), false)
            ->assertSee(__('スタンダード／テナントは運営確認後に登録メールが届きます。ライト（お試し）は自動審査のうえメールが届きます。'), false)
            ->assertSee(__('Sa2 Plusとは？'), false)
            ->assertSee(__('Sa2 Plusでできること'), false)
            ->assertSee(__('Todo・タスク管理'), false)
            ->assertSee(__('Sa2 Plusが選ばれる理由'), false)
            ->assertSee(__('Sa2 Plusはこんな方におすすめ'), false)
            ->assertSee(__('料金プラン'), false)
            ->assertSee(__('Sa2 Plusの利用方法'), false)
            ->assertSee(__('プランを選択'), false)
            ->assertSee(__('利用申請'), false)
            ->assertSee(__('登録メールを確認'), false)
            ->assertSee(__('Sa2 Plusを利用開始'), false)
            ->assertSee(__('スタンダードを申請する'), false)
            ->assertSee(__('テナント契約を申請する'), false)
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
            ->assertSee(__('Sa2 Plusとは何ですか？'), false)
            ->assertSee(__('Todo管理はできますか？'), false)
            ->assertSee(__('Sa2 Plusを始めてみませんか？'), false)
            ->assertSee(__('運営がお客様専用のサーバーへ、同じアプリを設置します。sa2-plus.com の共有環境とは別です。'), false)
            ->assertSee(__('管理者権限・ストレージ鍵の設定はありません'), false)
            ->assertSee(__('約 :gbGB。短期間のお試し枠です（週:cap人まで）。', ['gb' => $lightGb, 'cap' => $weeklyCap]), false)
            ->assertSee(__('約:warn日ログインがない場合は警告。さらに:grace日応答がなければ削除', ['warn' => $warnDays, 'grace' => $graceDays]), false)
            ->assertSee(__('約:gbGB・利用目的の記入必須・週:cap人まで。一時メールや目的不明は自動でお断りします。約:warn日ログインがない場合は警告メールを送り、さらに:grace日応答がなければ削除します。本格利用はスタンダードをご利用ください。', [
                'gb' => $lightGb,
                'cap' => $weeklyCap,
                'warn' => $warnDays,
                'grace' => $graceDays,
            ]), false)
            ->assertSee(__('はい。テナント契約として、管理者は代表1名・ユーザーは月5名まで（代表を含む）・@sa2-plus.com メールは各1アドレス込みです。最初の:days日は無料、その後 ¥:yen／月（税込）です。個人のライト／スタンダードには管理者は付けません。サーバーを分けたい場合は専用インスタンスです。', [
                'days' => (int) config('commercial.tenant_trial_days', 30),
                'yen' => number_format((int) config('commercial.tenant_monthly_yen', 3980)),
            ]), false)
            ->assertSee('href="/terms"', false)
            ->assertSee('href="/privacy"', false)
            ->assertSee(__('Workers AI で、これからもっと便利に'), false)
            ->assertSee(__('音楽再生・動画再生のほか、生活ガイド(AI)や家計簿も利用できます（機能は今後アップデートで拡張予定です）。'), false)
            ->assertSee(__('生活ガイド(AI)'), false)
            ->assertSee(__('家計簿'), false)
            ->assertSee(__('いま使える'), false)
            ->assertSee(__('生活の知恵・話し相手と、料理レシピ、今日のカレンダー案内はもう使えます。路線検索は NAVITIME の経路検索に対応済みです。'), false)
            ->assertSee('Cloudflare R2', false)
            ->assertSee('Backblaze B2', false)
            ->assertSee('NAVITIME', false)
            ->assertDontSee(__('航空運賃（Travelpayouts）'), false)
            ->assertDontSee('50GB', false);
    }

    public function test_apply_cta_is_always_visible_and_invite_register_appears_when_open(): void
    {
        Registration::setInviteCode('');
        $this->get('/')
            ->assertOk()
            ->assertSee(__('スタンダードを申請する'), false)
            ->assertSee(__('テナント契約を申請する'), false)
            ->assertDontSee(__('招待コードで登録'), false);

        Registration::setInviteCode('invite-top');
        $this->get('/')
            ->assertOk()
            ->assertSee(__('スタンダードを申請する'), false)
            ->assertSee(__('招待コードで登録'), false);
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
