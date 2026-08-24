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
            ->assertSee(__('ログイン'), false)
            ->assertSee(__('このサービスは招待制です。招待コードがあると誰でも登録できます。'), false)
            ->assertSee(__('ご利用の流れ'), false)
            ->assertSee(__('無料で触る'), false)
            ->assertSee(__('専用インスタンス'), false)
            ->assertSee(__('¥50,000〜'), false)
            ->assertSee(__('¥8,000〜／月'), false)
            ->assertSee(__('¥980／月'), false)
            ->assertSee(__('よくある質問'), false)
            ->assertSee('href="/terms"', false)
            ->assertSee('href="/privacy"', false);
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
