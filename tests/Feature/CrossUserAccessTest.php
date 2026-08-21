<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Note;
use App\Models\Photo;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * グループ共有を介さない他ユーザーのレコードに、ID を差し替えただけで
 * 到達できないこと（IDOR）を確認する。
 */
class CrossUserAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeUser('idor-owner@example.com');
        $this->stranger = $this->makeUser('idor-stranger@example.com');
    }

    private function makeUser(string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => $email,
            'password' => Hash::make('password'),
            'role' => UserRole::Standard,
        ]);
    }

    private function makeTodo(): Todo
    {
        return Todo::create([
            'user_id' => $this->owner->id,
            'title' => 'オーナーの予定',
            'completed' => false,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-01',
            'importance' => 'medium',
            'category' => 'task',
        ]);
    }

    private function makeNote(): Note
    {
        return Note::create([
            'user_id' => $this->owner->id,
            'title' => 'オーナーのメモ',
            'body' => '秘密',
            'registered_date' => '2026-08-01',
            'completed' => false,
        ]);
    }

    private function makePhoto(): Photo
    {
        Storage::disk('public')->put('photos/owner/secret.jpg', 'owner-image-bytes');

        return Photo::create([
            'user_id' => $this->owner->id,
            'album_id' => null,
            'path' => 'photos/owner/secret.jpg',
            'thumb_path' => null,
            'original_name' => 'secret.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 17,
            'sort_order' => 0,
        ]);
    }

    public function test_user_cannot_update_another_users_todo(): void
    {
        $todo = $this->makeTodo();

        $this->actingAs($this->stranger)
            ->from('/todos')
            ->post("/todos/{$todo->id}/update", [
                'title' => '乗っ取り',
                'dateMode' => 'single',
                'startDate' => '2026-08-01',
                'returnTo' => '/todos',
            ])
            ->assertRedirect('/todos')
            ->assertSessionHas('error');

        $this->assertSame('オーナーの予定', $todo->fresh()->title);
    }

    public function test_user_cannot_delete_another_users_todo(): void
    {
        $todo = $this->makeTodo();

        $this->actingAs($this->stranger)
            ->from('/todos')
            ->post("/todos/{$todo->id}/delete", ['returnTo' => '/todos'])
            ->assertSessionHas('error');

        $this->assertNotNull($todo->fresh());
    }

    public function test_user_cannot_delete_another_users_note(): void
    {
        $note = $this->makeNote();

        $this->actingAs($this->stranger)
            ->from('/notes')
            ->post("/notes/{$note->id}/delete", ['returnTo' => '/notes'])
            ->assertSessionHas('error');

        $this->assertNotNull($note->fresh());
    }

    public function test_user_cannot_complete_another_users_note(): void
    {
        $note = $this->makeNote();

        $this->actingAs($this->stranger)
            ->from('/notes')
            ->post("/notes/{$note->id}/complete", ['returnTo' => '/notes'])
            ->assertSessionHas('error');

        $this->assertFalse((bool) $note->fresh()->completed);
    }

    public function test_user_cannot_stream_another_users_photo_file(): void
    {
        config(['photos.disk' => 'public']);
        Storage::fake('public');
        $photo = $this->makePhoto();

        $this->actingAs($this->stranger)
            ->get("/photos/{$photo->id}/file")
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->get("/photos/{$photo->id}/file")
            ->assertOk();
    }

    public function test_user_cannot_delete_another_users_photo(): void
    {
        config(['photos.disk' => 'public']);
        Storage::fake('public');
        $photo = $this->makePhoto();

        $this->actingAs($this->stranger)
            ->postJson("/photos/{$photo->id}/delete")
            ->assertNotFound();

        $this->assertNotNull($photo->fresh());
    }
}
