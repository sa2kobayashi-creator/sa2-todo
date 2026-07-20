<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Enums\UserRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Photo;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GroupsSharingTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email, UserRole $role = UserRole::Standard): User
    {
        return User::create([
            'email' => $email,
            'display_name' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    public function test_create_group_is_pending_until_admin_approves(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $admin = $this->makeUser('admin@example.com', UserRole::Admin);

        $this->actingAs($owner)->post('/groups', [
            'name' => 'Family',
            'description' => 'shared',
        ])->assertRedirect();

        $group = Group::query()->where('name', 'Family')->first();
        $this->assertNotNull($group);
        $this->assertSame(GroupStatus::Pending, $group->status);
        $this->assertTrue(
            GroupMember::query()->where('group_id', $group->id)->where('user_id', $owner->id)->exists()
        );

        $this->actingAs($admin)->post('/admin/groups/'.$group->id.'/approve')->assertRedirect();
        $group->refresh();
        $this->assertSame(GroupStatus::Approved, $group->status);
    }

    public function test_todo_group_share_is_visible_to_members_only_after_approval(): void
    {
        $owner = $this->makeUser('todo-owner@example.com');
        $member = $this->makeUser('todo-member@example.com');
        $outsider = $this->makeUser('todo-outsider@example.com');
        $admin = $this->makeUser('todo-admin@example.com', UserRole::Admin);

        $this->actingAs($owner)->post('/groups', ['name' => 'Todo Share'])->assertRedirect();
        $group = Group::query()->where('name', 'Todo Share')->firstOrFail();

        $this->actingAs($owner)->post('/todos', [
            'titles' => "Shared task\n",
            'groupId' => $group->id,
            'returnTo' => '/todos',
        ]);
        $this->assertSame(0, Todo::query()->where('title', 'Shared task')->count());

        $this->actingAs($admin)->post('/admin/groups/'.$group->id.'/approve')->assertRedirect();
        GroupMember::query()->firstOrCreate(
            ['group_id' => $group->id, 'user_id' => $member->id],
            ['role' => 'member']
        );

        $this->actingAs($owner)->post('/todos', [
            'titles' => "Shared task\n",
            'groupId' => $group->id,
            'startDate' => '2026-07-20',
            'returnTo' => '/todos',
        ])->assertRedirect();

        $todo = Todo::query()->where('title', 'Shared task')->first();
        $this->assertNotNull($todo);
        $this->assertSame($group->id, $todo->group_id);

        $this->actingAs($member)->get('/todos')->assertOk()->assertSee('Shared task');
        $this->actingAs($outsider)->get('/todos')->assertOk()->assertDontSee('Shared task');
    }

    public function test_album_group_visibility_and_public_visibility(): void
    {
        $owner = $this->makeUser('album-owner@example.com');
        $member = $this->makeUser('album-member@example.com');
        $outsider = $this->makeUser('album-outsider@example.com');
        $admin = $this->makeUser('album-admin@example.com', UserRole::Admin);

        $this->actingAs($owner)->post('/groups', ['name' => 'Photo Share'])->assertRedirect();
        $group = Group::query()->where('name', 'Photo Share')->firstOrFail();
        $this->actingAs($admin)->post('/admin/groups/'.$group->id.'/approve')->assertRedirect();
        GroupMember::query()->firstOrCreate(
            ['group_id' => $group->id, 'user_id' => $member->id],
            ['role' => 'member']
        );

        $this->actingAs($owner)->post('/photos/albums', [
            'name' => 'Group Album',
            'visibility' => 'group',
            'group_id' => $group->id,
            'returnTo' => '/photos',
        ])->assertRedirect();

        $this->actingAs($owner)->post('/photos/albums', [
            'name' => 'Public Album',
            'visibility' => 'public',
            'returnTo' => '/photos',
        ])->assertRedirect();

        $this->actingAs($member)->get('/photos')->assertOk()
            ->assertSee('Group Album')
            ->assertSee('Public Album');

        $this->actingAs($outsider)->get('/photos')->assertOk()
            ->assertDontSee('Group Album')
            ->assertSee('Public Album');
    }

    public function test_edit_image_creates_new_photo_row(): void
    {
        Storage::fake('public');
        config(['photos.disk' => 'public']);

        $user = $this->makeUser('editor@example.com');
        $source = Photo::create([
            'user_id' => $user->id,
            'album_id' => null,
            'path' => 'photos/original.jpg',
            'thumb_path' => null,
            'original_name' => 'original.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 10,
            'height' => 10,
            'taken_at' => now(),
            'sort_order' => 0,
        ]);
        Storage::disk('public')->put($source->path, 'fake');

        $edited = UploadedFile::fake()->image('edited.jpg', 40, 40);

        $this->actingAs($user)->post('/photos/'.$source->id.'/edit-image', [
            'image' => $edited,
            'label' => 'crop',
            'returnTo' => '/photos',
        ])->assertRedirect();

        $this->assertDatabaseHas('photos', [
            'parent_photo_id' => $source->id,
            'user_id' => $user->id,
            'edit_label' => 'crop',
        ]);
        $this->assertSame(2, Photo::query()->where('user_id', $user->id)->count());
    }

    public function test_trim_video_returns_clear_error_when_ffmpeg_unavailable(): void
    {
        Storage::fake('public');
        config(['photos.disk' => 'public', 'photos.ffmpeg_path' => 'ffmpeg-missing-binary-xyz']);

        $user = $this->makeUser('trimmer@example.com');
        $video = Photo::create([
            'user_id' => $user->id,
            'album_id' => null,
            'path' => 'photos/clip.mp4',
            'thumb_path' => null,
            'original_name' => 'clip.mp4',
            'mime' => 'video/mp4',
            'size_bytes' => 100,
            'width' => 640,
            'height' => 360,
            'taken_at' => now(),
            'sort_order' => 0,
        ]);
        Storage::disk('public')->put($video->path, 'not-a-real-video');

        $response = $this->actingAs($user)->post('/photos/'.$video->id.'/trim-video', [
            'start' => 0,
            'end' => 1,
            'returnTo' => '/photos',
        ]);

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringContainsString('error=', $location);
        $this->assertSame(1, Photo::query()->where('user_id', $user->id)->count());
    }
}
