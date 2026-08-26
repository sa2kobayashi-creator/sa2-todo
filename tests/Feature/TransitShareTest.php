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

class TransitShareTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email, UserRole $role = UserRole::Standard): User
    {
        return User::create([
            'email' => $email,
            'display_name' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    private function approvedGroup(User $owner, User ...$members): Group
    {
        $group = Group::create([
            'name' => '通勤組',
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

    /** @return array<string, mixed> */
    private function itinerary(): array
    {
        return [
            'summary' => '地下鉄空港線',
            'departureTime' => '09:18',
            'arrivalTime' => '09:27',
            'durationLabel' => '9分',
            'waitLabel' => '2分',
            'fareLabel' => '¥260',
            'transfers' => 0,
            'legs' => [
                [
                    'type' => 'ride',
                    'routeName' => '地下鉄空港線',
                    'boardTime' => '09:18',
                    'alightTime' => '09:27',
                    'from' => '天神',
                    'to' => '博多',
                ],
            ],
        ];
    }

    public function test_transit_page_drops_operator_tabs_and_offers_sharing(): void
    {
        $user = $this->user('transit-share-page@example.com', UserRole::SuperAdmin);

        $this->actingAs($user)->get('/transit')
            ->assertOk()
            ->assertSee(__('グループやメンバーに共有'), false)
            ->assertSee(__('出発と到着を入れると'), false)
            ->assertDontSee(__('すべての登録路線'), false)
            ->assertDontSee('role="tablist"', false);
    }

    public function test_search_result_can_be_shared_to_the_group_chat(): void
    {
        $owner = $this->user('transit-share-owner@example.com');
        $member = $this->user('transit-share-member@example.com');
        $group = $this->approvedGroup($owner, $member);

        $response = $this->actingAs($owner)->postJson('/transit/share', [
            'groupId' => $group->id,
            'from' => '天神',
            'to' => '博多駅',
            'note' => '明日の集合',
            'itinerary' => $this->itinerary(),
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true);
        $this->assertStringContainsString('/messages/'.$group->id, (string) $response->json('href'));

        $message = GroupMessage::query()->where('group_id', $group->id)->first();
        $this->assertNotNull($message);
        $this->assertNull($message->recipient_user_id);
        $this->assertStringContainsString('天神 → 博多駅', (string) $message->body);
        $this->assertStringContainsString('地下鉄空港線', (string) $message->body);
        $this->assertStringContainsString('明日の集合', (string) $message->body);
        $this->assertStringContainsString('google.com/maps', (string) $message->body);
    }

    public function test_search_result_can_be_shared_to_a_group_member(): void
    {
        $owner = $this->user('transit-dm-owner@example.com');
        $member = $this->user('transit-dm-member@example.com');
        $group = $this->approvedGroup($owner, $member);

        $response = $this->actingAs($owner)->postJson('/transit/share', [
            'groupId' => $group->id,
            'peerUserId' => $member->id,
            'from' => '天神',
            'to' => '博多',
            'itinerary' => $this->itinerary(),
        ]);

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertStringContainsString('/messages/'.$group->id.'/dm/'.$member->id, (string) $response->json('href'));

        $message = GroupMessage::query()->where('group_id', $group->id)->first();
        $this->assertSame((int) $member->id, (int) $message->recipient_user_id);
    }

    public function test_outsider_cannot_share_into_a_group(): void
    {
        $owner = $this->user('transit-out-owner@example.com');
        $outsider = $this->user('transit-out-stranger@example.com');
        $group = $this->approvedGroup($owner);

        $this->actingAs($outsider)->postJson('/transit/share', [
            'groupId' => $group->id,
            'from' => '天神',
            'to' => '博多',
            'itinerary' => $this->itinerary(),
        ])->assertStatus(422)->assertJsonPath('ok', false);

        $this->assertSame(0, GroupMessage::query()->count());
    }
}
