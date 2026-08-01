<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Enums\UserRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MyPageProfileTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => 'Tester',
            'password' => Hash::make('password123'),
            'role' => UserRole::Standard,
        ]);
    }

    public function test_email_is_normalized_before_verification_starts(): void
    {
        Mail::fake();
        $user = $this->makeUser('mypage-normalize@example.com');

        $this->actingAs($user)->post('/mypage', [
            'displayName' => 'Tester',
            'email' => '  MyPage-Normalized@Example.COM  ',
        ])->assertRedirect('/mypage/email/verify?notice='.urlencode('確認コードをmypage-normalized@example.comに送信しました。コードを入力すると変更が完了します。'));

        $user->refresh();
        $this->assertSame('mypage-normalize@example.com', $user->email);
        $this->assertSame('mypage-normalized@example.com', $user->pending_email);
    }

    public function test_display_name_is_saved_even_while_the_email_waits_for_verification(): void
    {
        Mail::fake();
        $user = $this->makeUser('mypage-name@example.com');

        $this->actingAs($user)->post('/mypage', [
            'displayName' => '新しい名前',
            'email' => 'mypage-name-new@example.com',
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame('新しい名前', $user->display_name);
        $this->assertSame('mypage-name@example.com', $user->email);
    }

    public function test_duplicate_email_shows_error_on_mypage(): void
    {
        $user = $this->makeUser('mypage-dup-a@example.com');
        $this->makeUser('mypage-dup-b@example.com');

        $response = $this->actingAs($user)->followingRedirects()->post('/mypage', [
            'displayName' => 'Tester',
            'email' => 'mypage-dup-b@example.com',
        ]);

        $response->assertOk()->assertSee('このメールアドレスはすでに使用されています。');
        $this->assertSame('mypage-dup-a@example.com', $user->fresh()->email);
    }

    public function test_mypage_no_longer_changes_the_password_directly(): void
    {
        $user = $this->makeUser('mypage-pass@example.com');

        $this->actingAs($user)->post('/mypage', [
            'displayName' => 'Tester',
            'email' => 'mypage-pass@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('password123', $user->fresh()->password));
    }

    public function test_mypage_lists_the_groups_the_user_belongs_to(): void
    {
        $user = $this->makeUser('mypage-group@example.com');
        $group = Group::create([
            'name' => 'デザイン班',
            'owner_user_id' => $user->id,
            'status' => GroupStatus::Approved->value,
        ]);
        GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $this->actingAs($user)->get('/mypage')
            ->assertOk()
            ->assertSee('所属グループ')
            ->assertSee('デザイン班');
    }
}
