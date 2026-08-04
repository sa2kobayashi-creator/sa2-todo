<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Enums\UserRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_messages_index_redirects_into_first_group_workspace(): void
    {
        $user = $this->makeUser('chat@example.com');
        $group = $this->makeApprovedGroup($user);

        $this->actingAs($user)->get('/messages')
            ->assertRedirect('/messages/'.$group->id);
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

    public function test_translate_page_is_available(): void
    {
        $user = $this->makeUser('tr@example.com');

        $this->actingAs($user)->get('/translate')
            ->assertOk()
            ->assertSee('翻訳')
            ->assertSee('tr-board', false);
    }
}
