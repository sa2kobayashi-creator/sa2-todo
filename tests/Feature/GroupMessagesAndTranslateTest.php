<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Enums\UserRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GroupMessagesAndTranslateTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email, string $name = 'Chat User', UserRole $role = UserRole::Standard): User
    {
        return User::create([
            'email' => $email,
            'display_name' => $name,
            'password' => Hash::make('password123'),
            'role' => $role,
        ]);
    }

    private function makeApprovedGroup(User $owner, User ...$members): Group
    {
        $group = Group::create([
            'name' => 'Family',
            'description' => null,
            'owner_user_id' => $owner->id,
            'status' => GroupStatus::Approved,
        ]);
        GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
        foreach ($members as $member) {
            GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $member->id,
                'role' => 'member',
            ]);
        }

        return $group;
    }

    public function test_messages_index_shows_inbox_list(): void
    {
        $user = $this->makeUser('chat@example.com');
        $group = $this->makeApprovedGroup($user);

        $this->actingAs($user)
            ->postJson('/messages/'.$group->id, ['body' => 'group hello'])
            ->assertOk();

        $this->actingAs($user)->get('/messages')
            ->assertOk()
            ->assertSee('メッセージ', false)
            ->assertSee($group->name, false)
            ->assertSee('/messages/'.$group->id, false)
            ->assertSee('最近のグループメッセージ', false)
            ->assertSee('group hello', false);
    }

    public function test_group_member_can_post_and_poll_messages(): void
    {
        $user = $this->makeUser('poster@example.com');
        $group = $this->makeApprovedGroup($user);

        $this->actingAs($user)
            ->postJson('/messages/'.$group->id, ['body' => 'hello group'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message.body', 'hello group');

        $this->assertDatabaseHas('group_messages', [
            'group_id' => $group->id,
            'user_id' => $user->id,
            'recipient_user_id' => null,
            'body' => 'hello group',
        ]);

        $id = (int) GroupMessage::query()->value('id');

        $this->actingAs($user)
            ->getJson('/messages/'.$group->id.'/poll?after=0')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonFragment(['id' => $id, 'body' => 'hello group']);
    }

    public function test_members_can_exchange_direct_messages(): void
    {
        $alice = $this->makeUser('alice@example.com', 'Alice');
        $bob = $this->makeUser('bob@example.com', 'Bob');
        $group = $this->makeApprovedGroup($alice, $bob);

        $this->actingAs($alice)
            ->postJson('/messages/'.$group->id, [
                'body' => 'hi bob',
                'peer_user_id' => $bob->id,
            ])
            ->assertOk()
            ->assertJsonPath('message.body', 'hi bob');

        $this->actingAs($bob)->get('/messages/'.$group->id.'/dm/'.$alice->id)
            ->assertOk()
            ->assertSee('hi bob')
            ->assertSee('Alice');

        $this->actingAs($bob)
            ->getJson('/messages/'.$group->id.'/poll?peer='.$alice->id.'&after=0')
            ->assertOk()
            ->assertJsonFragment(['body' => 'hi bob']);
    }

    public function test_non_member_cannot_open_group_chat(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $outsider = $this->makeUser('out@example.com');
        $group = $this->makeApprovedGroup($owner);

        $this->actingAs($outsider)->get('/messages/'.$group->id)
            ->assertRedirect();
    }

    public function test_sender_can_edit_and_delete_own_message(): void
    {
        $user = $this->makeUser('edit@example.com');
        $group = $this->makeApprovedGroup($user);

        $this->actingAs($user)
            ->postJson('/messages/'.$group->id, ['body' => 'original'])
            ->assertOk();

        $id = (int) GroupMessage::query()->value('id');

        $this->actingAs($user)
            ->postJson('/messages/items/'.$id.'/update', ['body' => 'updated'])
            ->assertOk()
            ->assertJsonPath('message.body', 'updated')
            ->assertJsonPath('message.threadType', 'channel');

        $this->assertNotNull(GroupMessage::query()->find($id)?->edited_at);

        $this->actingAs($user)
            ->postJson('/messages/items/'.$id.'/delete')
            ->assertOk()
            ->assertJsonPath('scope', 'everyone');

        $this->assertSoftDeleted('group_messages', ['id' => $id]);
    }

    public function test_receiver_can_hide_message_and_reply(): void
    {
        $alice = $this->makeUser('alice2@example.com', 'Alice');
        $bob = $this->makeUser('bob2@example.com', 'Bob');
        $group = $this->makeApprovedGroup($alice, $bob);

        $this->actingAs($alice)
            ->postJson('/messages/'.$group->id, ['body' => 'hello all'])
            ->assertOk();
        $id = (int) GroupMessage::query()->value('id');

        $this->actingAs($bob)
            ->postJson('/messages/'.$group->id, [
                'body' => 're: hello',
                'reply_to_id' => $id,
            ])
            ->assertOk()
            ->assertJsonPath('message.replyTo.id', $id);

        $this->actingAs($bob)
            ->postJson('/messages/items/'.$id.'/delete')
            ->assertOk()
            ->assertJsonPath('scope', 'me');

        $poll = $this->actingAs($bob)
            ->getJson('/messages/'.$group->id.'/poll?after=0')
            ->assertOk()
            ->json('messages');

        $this->assertFalse(collect($poll)->contains(fn ($m) => (int) $m['id'] === $id));
    }

    public function test_members_can_react_and_presence_updates_on_poll(): void
    {
        $alice = $this->makeUser('alice3@example.com', 'Alice');
        $bob = $this->makeUser('bob3@example.com', 'Bob');
        $group = $this->makeApprovedGroup($alice, $bob);

        $this->actingAs($alice)
            ->postJson('/messages/'.$group->id, ['body' => 'react me'])
            ->assertOk();
        $id = (int) GroupMessage::query()->value('id');

        $this->actingAs($bob)
            ->postJson('/messages/items/'.$id.'/react', ['emoji' => '👍'])
            ->assertOk()
            ->assertJsonPath('message.reactions.0.emoji', '👍');

        $this->actingAs($bob)
            ->getJson('/messages/'.$group->id.'/poll?after=0')
            ->assertOk()
            ->assertJsonPath('presence.'.$bob->id.'.online', true);

        $this->assertNotNull($bob->fresh()->last_seen_at);
    }

    public function test_emoji_only_ok_message_is_marked_as_sticker(): void
    {
        $user = $this->makeUser('sticker@example.com');
        $group = $this->makeApprovedGroup($user);

        $this->actingAs($user)
            ->postJson('/messages/'.$group->id, ['body' => '👍'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message.body', '👍')
            ->assertJsonPath('message.isSticker', true);

        $this->actingAs($user)
            ->postJson('/messages/'.$group->id, ['body' => 'hello'])
            ->assertOk()
            ->assertJsonPath('message.isSticker', false);
    }

    public function test_workspace_shows_group_and_dm_labels(): void
    {
        $alice = $this->makeUser('alice4@example.com', 'Alice');
        $bob = $this->makeUser('bob4@example.com', 'Bob');
        $group = $this->makeApprovedGroup($alice, $bob);

        $this->actingAs($bob)->get('/messages/'.$group->id)
            ->assertOk()
            ->assertSee('グループチャット', false)
            ->assertSee('個別チャット', false)
            ->assertSee('会話一覧へ', false)
            ->assertSee('全員に届くグループチャット', false);
    }

    public function test_group_wallpaper_is_shared_and_dm_poll_omits_it(): void
    {
        $alice = $this->makeUser('alice-bg@example.com', 'Alice');
        $bob = $this->makeUser('bob-bg@example.com', 'Bob');
        $group = $this->makeApprovedGroup($alice, $bob);

        $this->actingAs($alice)
            ->postJson('/messages/'.$group->id.'/wallpaper', ['theme' => 'aurora'])
            ->assertOk()
            ->assertJsonPath('wallpaper.value', 'aurora');

        $this->actingAs($alice)
            ->postJson('/messages/'.$group->id.'/wallpaper', ['theme' => 'mint'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('wallpaper.type', 'theme')
            ->assertJsonPath('wallpaper.value', 'mint');

        $this->assertDatabaseHas('groups', [
            'id' => $group->id,
            'chat_bg_type' => 'theme',
            'chat_bg_theme' => 'mint',
        ]);

        $this->actingAs($bob)
            ->getJson('/messages/'.$group->id.'/poll?after=0')
            ->assertOk()
            ->assertJsonPath('wallpaper.value', 'mint');

        $this->actingAs($bob)
            ->getJson('/messages/'.$group->id.'/poll?after=0&peer='.$alice->id)
            ->assertOk()
            ->assertJsonMissingPath('wallpaper');

        $this->actingAs($bob)->get('/messages/'.$group->id)
            ->assertOk()
            ->assertSee('グループチャットの背景はメンバー全員に共有されます', false);

        $this->actingAs($bob)->get('/messages/'.$group->id.'/dm/'.$alice->id)
            ->assertOk()
            ->assertSee('個別チャットの背景は自分だけに表示されます', false);
    }

    public function test_translate_page_requires_super_admin(): void
    {
        $user = $this->makeUser('tr@example.com');
        $admin = $this->makeUser('sa@example.com', 'SA', UserRole::SuperAdmin);

        $this->actingAs($user)->get('/translate')->assertForbidden();
        $this->actingAs($admin)->get('/translate')->assertOk();
    }

    public function test_member_can_download_and_save_message_image_to_photos(): void
    {
        Storage::fake('public');

        $alice = $this->makeUser('alice-photo@example.com', 'Alice');
        $bob = $this->makeUser('bob-photo@example.com', 'Bob');
        $group = $this->makeApprovedGroup($alice, $bob);

        $this->actingAs($alice)
            ->postJson('/messages/'.$group->id, [
                'body' => 'pic',
                'attachments' => [UploadedFile::fake()->image('chat.jpg', 48, 48)],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $attachmentId = (int) \App\Models\GroupMessageAttachment::query()->value('id');
        $this->assertGreaterThan(0, $attachmentId);

        $this->actingAs($bob)
            ->get('/messages/attachments/'.$attachmentId.'/download')
            ->assertOk();

        $this->actingAs($bob)
            ->postJson('/messages/attachments/'.$attachmentId.'/to-photos')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('skipped', false);

        $this->assertSame(1, Photo::query()->where('user_id', $bob->id)->count());

        $this->actingAs($bob)
            ->postJson('/messages/attachments/'.$attachmentId.'/to-photos')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('skipped', true);

        $this->assertSame(1, Photo::query()->where('user_id', $bob->id)->count());

        $outsider = $this->makeUser('out-photo@example.com');
        $this->actingAs($outsider)
            ->postJson('/messages/attachments/'.$attachmentId.'/to-photos')
            ->assertNotFound();
    }
}
